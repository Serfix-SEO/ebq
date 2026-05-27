<?php

namespace App\Livewire\Dashboard;

use App\Models\AnalyticsData;
use App\Models\SearchConsoleData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class KpiCards extends Component
{
    public int $websiteId = 0;

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
        $this->websiteId = (int) session('current_website_id', 0);
    }

    #[On('website-changed')]
    public function switchWebsite(int $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    public function render()
    {
        $data = [
            'clicks' => $this->emptyMetric(),
            'impressions' => $this->emptyMetric(),
            'users' => $this->emptyMetric(),
            'sessions' => $this->emptyMetric(),
        ];

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $today = Carbon::today(config('app.timezone'));
            $currentEnd = $today->copy()->subDay();
            $currentStart = $currentEnd->copy()->subDays(29);
            $previousEnd = $currentStart->copy()->subDay();
            $previousStart = $previousEnd->copy()->subDays(29);
            $cacheKey = sprintf(
                'kpis:%d:%s:%s',
                $this->websiteId,
                $currentStart->toDateString(),
                $currentEnd->toDateString()
            );

            $data = Cache::remember($cacheKey, 600, function () use ($currentStart, $currentEnd, $previousStart, $previousEnd) {
                $currentSc = SearchConsoleData::query()
                    ->where('website_id', $this->websiteId)
                    ->whereBetween('date', [$currentStart->toDateString(), $currentEnd->toDateString()]);
                $previousSc = SearchConsoleData::query()
                    ->where('website_id', $this->websiteId)
                    ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()]);
                $currentGa = AnalyticsData::query()
                    ->where('website_id', $this->websiteId)
                    ->whereBetween('date', [$currentStart->toDateString(), $currentEnd->toDateString()]);
                $previousGa = AnalyticsData::query()
                    ->where('website_id', $this->websiteId)
                    ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()]);

                return [
                    'clicks' => $this->buildMetric(
                        (int) (clone $currentSc)->sum('clicks'),
                        (int) (clone $previousSc)->sum('clicks')
                    ),
                    'impressions' => $this->buildMetric(
                        (int) (clone $currentSc)->sum('impressions'),
                        (int) (clone $previousSc)->sum('impressions')
                    ),
                    'users' => $this->buildMetric(
                        (int) (clone $currentGa)->sum('users'),
                        (int) (clone $previousGa)->sum('users')
                    ),
                    'sessions' => $this->buildMetric(
                        (int) (clone $currentGa)->sum('sessions'),
                        (int) (clone $previousGa)->sum('sessions')
                    ),
                ];
            });
        }

        return view('livewire.dashboard.kpi-cards', compact('data'));
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

    private function buildMetric(int $current, int $previous): array
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
