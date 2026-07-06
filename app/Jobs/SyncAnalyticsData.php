<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Google\GoogleAnalyticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAnalyticsData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(
        public string $websiteId,
        public int $days = 30,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function handle(GoogleAnalyticsService $service): void
    {
        if (\App\Support\ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::findOrFail($this->websiteId);
        // Plan-limit freeze: when the website is past the owning user's
        // plan limit, skip the GA fetch. Avoids burning Google quota on
        // sites the user can't actually see in EBQ until they upgrade.
        if ($website->isFrozen()) {
            Log::info("SyncAnalyticsData: skipping frozen website {$this->websiteId}");
            return;
        }
        // No GA source configured (GSC-only or PageSpeed-only site): nothing to sync.
        if ($website->ga_property_id === null || $website->ga_property_id === '') {
            return;
        }
        $account = $website->gaAccountResolved();

        if (! $account) {
            Log::warning("SyncAnalyticsData: No Google account for website {$this->websiteId}");
            return;
        }

        $rows = $service->fetchDailyTraffic(
            $account,
            $website->ga_property_id,
            Carbon::now()->subDays($this->days)->toDateString(),
            Carbon::now()->toDateString()
        );

        if ($rows === []) {
            Website::whereKey($this->websiteId)->update(['last_analytics_sync_at' => now()]);

            return;
        }

        foreach ($rows as &$row) {
            $row['website_id'] = $this->websiteId;
        }

        DB::table('analytics_data')->upsert(ulid_rows($rows),
            ['website_id', 'date', 'source'],
            ['users', 'sessions', 'bounce_rate', 'updated_at']
        );

        Website::whereKey($this->websiteId)->update(['last_analytics_sync_at' => now()]);

        // New GA rows landed — orphan every version-keyed dashboard/report
        // cache for this website (KPI cards, traffic chart read AnalyticsData).
        // SyncSearchConsoleData does the same for GSC rows; without this bump
        // the GA half of those payloads stayed stale for the full TTL.
        \App\Services\ReportCache::flushWebsite((string) $this->websiteId);
        \App\Jobs\WarmDashboardCaches::dispatch((string) $this->websiteId);
    }
}
