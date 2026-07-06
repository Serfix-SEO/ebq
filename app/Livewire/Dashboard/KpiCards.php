<?php

namespace App\Livewire\Dashboard;

use App\Models\AnalyticsData;
use App\Models\SearchConsoleData;
use App\Services\ReportCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class KpiCards extends Component
{
    public ?string $websiteId = null;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="h-2.5 w-1/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="mt-3 h-6 w-2/3 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
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
    }

    public function render()
    {
        $data = [
            '_window' => null,
            'clicks' => $this->emptyMetric(),
            'impressions' => $this->emptyMetric(),
            'users' => $this->emptyMetric(),
            'sessions' => $this->emptyMetric(),
        ];

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $data = self::payload($this->websiteId);
        }

        return view('livewire.dashboard.kpi-cards', compact('data'));
    }

    /**
     * Cached KPI payload — shared by render() and WarmDashboardCaches (the
     * post-sync warmer) so both use the identical key + computation, and the
     * first dashboard visitor after a sync never pays the cold aggregate.
     */
    public static function payload(string $websiteId): array
    {
        // Anchor the window to the last day WITH Search Console data, not
        // "yesterday": GSC finalises ~3 days late, so a yesterday-anchored
        // window silently contained 2-3 empty lag days — deflating the
        // current totals and biasing every previous-period comparison
        // (30 full previous days vs ~27 current). Found 2026-07-06 on
        // namesforfreefire.com. GA is included in the same window so both
        // sources stay comparable.
        $currentEnd = app(\App\Services\ReportDataService::class)->lastSafeReportDate($websiteId)
            ?? Carbon::today(config('app.timezone'))->subDay();
        $currentStart = $currentEnd->copy()->subDays(29);
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays(29);
        // Mix in ReportCache::version so a GSC/GA sync that corrects the most
        // recent (partial) days inside this fixed window invalidates the cache —
        // the date range alone doesn't change, so without the version these KPIs
        // stayed stale for the whole TTL after a re-sync.
        $cacheKey = sprintf(
            'kpis:%s:%s:%s:%d',
            $websiteId,
            $currentStart->toDateString(),
            $currentEnd->toDateString(),
            ReportCache::version($websiteId)
        );

        return Cache::remember($cacheKey, 86400, function () use ($websiteId, $currentStart, $currentEnd, $previousStart, $previousEnd) {
            $currentSc = SearchConsoleData::query()
                ->where('website_id', $websiteId)
                ->whereBetween('date', [$currentStart->toDateString(), $currentEnd->toDateString()]);
            $previousSc = SearchConsoleData::query()
                ->where('website_id', $websiteId)
                ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()]);
            $currentGa = AnalyticsData::query()
                ->where('website_id', $websiteId)
                ->whereBetween('date', [$currentStart->toDateString(), $currentEnd->toDateString()]);
            $previousGa = AnalyticsData::query()
                ->where('website_id', $websiteId)
                ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()]);

            return [
                '_window' => [
                    'start' => $currentStart->toDateString(),
                    'end' => $currentEnd->toDateString(),
                ],
                'clicks' => self::buildMetric(
                    (int) (clone $currentSc)->sum('clicks'),
                    (int) (clone $previousSc)->sum('clicks')
                ),
                'impressions' => self::buildMetric(
                    (int) (clone $currentSc)->sum('impressions'),
                    (int) (clone $previousSc)->sum('impressions')
                ),
                'users' => self::buildMetric(
                    (int) (clone $currentGa)->sum('users'),
                    (int) (clone $previousGa)->sum('users')
                ),
                'sessions' => self::buildMetric(
                    (int) (clone $currentGa)->sum('sessions'),
                    (int) (clone $previousGa)->sum('sessions')
                ),
            ];
        });
    }

    private function emptyMetric(): array
    {
        return [
            'current' => 0,
            'previous' => 0,
            'change_percent' => 0.0,
            'direction' => 'flat',
        ];
    }

    private static function buildMetric(int $current, int $previous): array
    {
        $change = $current - $previous;
        $changePercent = $previous !== 0
            ? round(($change / abs($previous)) * 100, 1)
            : ($current !== 0 ? null : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => $changePercent,
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }
}
