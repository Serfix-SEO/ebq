<?php

namespace App\Livewire\Dashboard;

use App\Models\KeywordMetric;
use App\Models\SearchConsoleData;
use App\Services\KeywordValueCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dashboard card that surfaces up to 5 keywords whose KE trend is classified
 * as "seasonal" AND whose historical peak month is within the next ~60 days,
 * so the customer can refresh content before the traffic arrives.
 *
 * Card is silent when there's nothing to show — no empty frame on new sites.
 */
#[Lazy]
class SeasonalityCard extends Component
{
    public int $websiteId = 0;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="h-3 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="mt-4 space-y-2.5">
                <div class="h-3 w-full animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-10/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-3 w-9/12 animate-pulse rounded bg-slate-100 dark:bg-slate-800/60"></div>
            </div>
        </div>
        HTML;
    }

    public function mount(): void
    {
        $this->websiteId = (int) session('current_website_id', 0);
    }

    #[On('website-changed')]
    public function switchWebsite(int $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    public function render()
    {
        $rows = [];

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $rows = Cache::remember(
                'seasonality_card:'.$this->websiteId,
                600,
                fn () => $this->computeRows(),
            );
        }

        return view('livewire.dashboard.seasonality-card', [
            'rows' => $rows,
        ]);
    }

    /**
     * Pull the site's top GSC queries, intersect with seasonal KE rows whose
     * next peak is in the next 60 days. Return up to 5 sorted by peak date.
     *
     * @return list<array<string, mixed>>
     */
    private function computeRows(): array
    {
        $since = Carbon::now()->subDays(30)->toDateString();
        $queries = SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->whereDate('date', '>=', $since)
            ->where('query', '!=', '')
            ->selectRaw('query, SUM(impressions) as impressions')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ?', [50])
            ->orderByDesc('impressions')
            ->limit(500)
            ->pluck('query')
            ->map(fn ($q) => (string) $q)
            ->all();

        if ($queries === []) {
            return [];
        }

        $metrics = KeywordMetric::query()
            ->whereIn('keyword_hash', array_map(fn ($q) => KeywordMetric::hashKeyword($q), $queries))
            ->where('country', 'global')
            ->get();

        $now = Carbon::now();
        $currentMonth = (int) $now->format('n');
        $out = [];

        foreach ($metrics as $m) {
            if (KeywordValueCalculator::trendClassify($m->trend_12m) !== 'seasonal') {
                continue;
            }
            $peak = KeywordValueCalculator::nextPeakMonth($m->trend_12m);
            if ($peak === null) {
                continue;
            }

            // Months until the next occurrence of that peak month (0..11).
            $monthsUntil = ($peak - $currentMonth + 12) % 12;
            if ($monthsUntil > 2) { // ~60 days
                continue;
            }

            $out[] = [
                'keyword' => $m->keyword,
                'language' => app(\App\Services\LanguageDetectorService::class)->detect((string) $m->keyword),
                'peak_month' => $peak,
                'peak_month_name' => Carbon::create(null, $peak, 1)->format('F'),
                'months_until' => $monthsUntil,
                'search_volume' => $m->search_volume,
            ];
        }

        usort($out, fn ($a, $b) => $a['months_until'] <=> $b['months_until']);

        return array_slice($out, 0, 5);
    }
}
