<?php

namespace App\Livewire\Dashboard;

use App\Models\AnalyticsData;
use App\Models\SearchConsoleData;
use App\Services\ReportCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class TrafficChart extends Component
{
    public ?string $websiteId = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="h-3 w-1/4 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="mt-4 h-56 w-full animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
        </div>
        HTML;
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    public function render()
    {
        $days = collect();
        $anomalies = [];
        $latestClicksPair = null;
        $latestUsersPair = null;

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $cached = self::payload($this->websiteId, Auth::user());

            // Backward compatibility in case an older cache payload is present.
            if ($cached instanceof Collection) {
                $days = $cached;
            } else {
                $days = collect($cached['days'] ?? []);
                $latestClicksPair = $cached['clicks_pair'] ?? null;
                $latestUsersPair = $cached['users_pair'] ?? null;
            }

            if (is_array($latestUsersPair) && count($latestUsersPair) === 2) {
                [$prevUsers, $lastUsers] = array_map('intval', $latestUsersPair);
                if ($prevUsers > 0 && $lastUsers < ($prevUsers * 0.75)) {
                    $anomalies[] = 'Users dropped more than 25% day-over-day.';
                }
            }

            if (is_array($latestClicksPair) && count($latestClicksPair) === 2) {
                [$prevClicks, $lastClicks] = array_map('intval', $latestClicksPair);
                if ($prevClicks > 0 && $lastClicks < ($prevClicks * 0.75)) {
                    $anomalies[] = 'Clicks dropped more than 25% day-over-day.';
                }
            }
        }

        return view('livewire.dashboard.traffic-chart', [
            'days' => $days,
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * Cached traffic-chart payload — shared by render() and WarmDashboardCaches
     * (post-sync warmer). Key is per-user (display timezone + date formatting),
     * so the warmer warms the website OWNER's view; teammates in other
     * timezones pay their own (rare) cold path.
     */
    public static function payload(string $websiteId, \App\Models\User $user): array|Collection
    {
        $tz = display_timezone($user);
        $today = Carbon::today($tz);
        $end = $today->copy()->subDay();
        $start = $end->copy()->subDays(29);
        // Mix in ReportCache::version so a GSC/GA re-sync of the recent partial
        // days inside this fixed window invalidates the cache (date range alone
        // doesn't change after a same-window correction).
        $cacheKey = sprintf(
            'traffic_chart:v3:%s:%s:%s:%s:%s:%d',
            $websiteId,
            (string) $user->id,
            str_replace('/', '_', $tz),
            $start->toDateString(),
            $end->toDateString(),
            ReportCache::version($websiteId)
        );

        return Cache::remember($cacheKey, 86400, function () use ($websiteId, $start, $end, $user) {
            $clicks = SearchConsoleData::where('website_id', $websiteId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('date, SUM(clicks) as clicks')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('clicks', 'date');

            $users = AnalyticsData::where('website_id', $websiteId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('date, SUM(users) as users')
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('users', 'date');

            $allDates = $clicks->keys()->merge($users->keys())->unique()->sort()->values();

            return [
                'days' => $allDates->map(fn ($d) => [
                    'date' => format_user_date(is_string($d) ? $d : (string) $d, 'M d', $user),
                    'clicks' => (int) ($clicks[$d] ?? 0),
                    'users' => (int) ($users[$d] ?? 0),
                ]),
                // Keep anomaly comparison on real metric samples only.
                'clicks_pair' => $clicks->count() >= 2 ? $clicks->slice(-2, 2, true)->values()->all() : null,
                'users_pair' => $users->count() >= 2 ? $users->slice(-2, 2, true)->values()->all() : null,
            ];
        });
    }
}
