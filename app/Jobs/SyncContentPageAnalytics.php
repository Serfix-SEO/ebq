<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Google\GoogleAnalyticsService;
use App\Services\ReportCache;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-page daily GA4 sync for Content Autopilot performance reporting. GA's
 * regular sync (SyncAnalyticsData) only stores date+source totals, so this fills
 * content_page_analytics with per-URL pageviews/sessions — the "how is my
 * article doing" data. Content-entitled sites are synced even when frozen (the
 * same exemption GSC sync already grants content sites); GSC per-page data
 * already lands via SyncSearchConsoleData, so this is GA-only.
 */
class SyncContentPageAnalytics implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public string $websiteId,
        public int $days = 30,
    ) {
        $this->onQueue(Queues::SYNC);
    }

    public function handle(GoogleAnalyticsService $service): void
    {
        if (ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
        app(ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::find($this->websiteId);
        if ($website === null || ! $website->contentAutopilotEntitled() || ! $website->hasGa()) {
            return;
        }

        $account = $website->gaAccountResolved();
        if (! $account) {
            Log::warning("SyncContentPageAnalytics: no Google account for website {$this->websiteId}");

            return;
        }

        $rows = $service->fetchPageTraffic(
            $account,
            $website->ga_property_id,
            Carbon::now()->subDays($this->days)->toDateString(),
            Carbon::now()->toDateString()
        );

        if ($rows === []) {
            return;
        }

        foreach ($rows as &$row) {
            $row['website_id'] = $this->websiteId;
        }
        unset($row);

        DB::table('content_page_analytics')->upsert(
            ulid_rows($rows),
            ['website_id', 'date', 'page'],
            ['pageviews', 'sessions', 'users', 'engagement_rate', 'updated_at']
        );

        // New per-page GA rows → orphan the content-performance caches for this site.
        ReportCache::flushWebsite((string) $this->websiteId);
    }
}
