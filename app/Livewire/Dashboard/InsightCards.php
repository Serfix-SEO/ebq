<?php

namespace App\Livewire\Dashboard;

use App\Models\KeywordMetric;
use App\Models\SearchConsoleData;
use App\Services\KeywordValueCalculator;
use App\Services\ReportCache;
use App\Services\ReportDataService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Lazy]
class InsightCards extends Component
{
    public ?string $websiteId = null;

    #[Url(as: 'country', history: true)]
    public string $country = '';

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
        </div>
        HTML;
    }

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->country = '';
    }

    #[On('country-changed')]
    public function onCountryChanged(string $country): void
    {
        $this->country = $country;
    }

    public function render()
    {
        $counts = ['cannibalizations' => 0, 'striking_distance' => 0, 'indexing_fails_with_traffic' => 0, 'content_decay' => 0];
        $ppcEquivalent = null;
        $ppcKeywordCount = 0;

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $country = $this->country !== '' ? $this->country : null;
            [$counts, $ppc] = self::payload($this->websiteId, $country);
            $ppcEquivalent = $ppc['value'];
            $ppcKeywordCount = $ppc['keywords'];
        }

        return view('livewire.dashboard.insight-cards', [
            'counts' => $counts,
            'country' => $this->country,
            'ppcEquivalent' => $ppcEquivalent,
            'ppcKeywordCount' => $ppcKeywordCount,
        ]);
    }

    /**
     * Sum of projectedMonthlyValue across all GSC queries (last 30 days) where
     * we have cached Keywords Everywhere metrics. Requires ≥10 priced queries
     * before we show the number — keeps the message from being misleading when
     * the KE cache is sparse.
     *
     * @return array{value: ?float, keywords: int}
     */
    private function computePpcEquivalent(?string $country): array
    {
        $since = Carbon::now()->subDays(30)->toDateString();

        $rows = SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->whereDate('date', '>=', $since)
            ->where('query', '!=', '')
            ->when($country, fn ($q, $c) => $q->where('country', $c))
            ->selectRaw('query, SUM(impressions) as impressions, AVG(position) as avg_position')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ?', [50])
            ->orderByDesc('impressions')
            ->limit(1000)
            ->get();

        if ($rows->isEmpty()) {
            return ['value' => null, 'keywords' => 0];
        }

        $hashes = $rows->map(fn ($r) => KeywordMetric::hashKeyword((string) $r->query))->unique()->all();
        $metrics = KeywordMetric::query()
            ->whereIn('keyword_hash', $hashes)
            ->where('country', 'global')
            ->get()
            ->keyBy('keyword_hash');

        $sum = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $hit = $metrics[KeywordMetric::hashKeyword((string) $r->query)] ?? null;
            if (! $hit || $hit->cpc === null || $hit->search_volume === null) {
                continue;
            }
            $val = KeywordValueCalculator::projectedMonthlyValue(
                $hit->search_volume,
                (float) $r->avg_position,
                $hit->cpc,
            );
            if ($val !== null) {
                $sum += $val;
                $count++;
            }
        }

        if ($count < 10) {
            return ['value' => null, 'keywords' => $count];
        }

        return ['value' => round($sum, 2), 'keywords' => $count];
    }

    /**
     * Cached insight counts + PPC-equivalent — shared by render() and
     * WarmDashboardCaches (warms the default all-countries view).
     *
     * @return array{0: array, 1: array}
     */
    public static function payload(string $websiteId, ?string $country = null): array
    {
        $counts = Cache::remember(
            'insights:counts:'.$websiteId.':'.($country ?? 'all').':v'.ReportCache::version($websiteId),
            86400,
            fn () => app(ReportDataService::class)->insightCounts($websiteId, $country),
        );

        $self = new self;
        $self->websiteId = $websiteId;
        $ppc = Cache::remember(
            'insights:ppc:'.$websiteId.':'.($country ?? 'all').':v'.ReportCache::version($websiteId),
            86400,
            fn () => $self->computePpcEquivalent($country),
        );

        return [$counts, $ppc];
    }
}
