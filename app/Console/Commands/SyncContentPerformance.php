<?php

namespace App\Console\Commands;

use App\Jobs\SyncContentPageAnalytics;
use App\Models\ContentPlan;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Daily per-page GA sync for the Content Autopilot Keyword Tracker's reporting.
 * Only content-entitled websites with GA connected — GSC per-page/query data
 * already syncs via ebq:sync-daily-data, so this is the GA-only half. The job
 * re-checks entitlement + hasGa(), so this filter is just to avoid dispatching
 * no-op jobs for the whole fleet.
 */
class SyncContentPerformance extends Command
{
    protected $signature = 'ebq:sync-content-performance';

    protected $description = 'Refresh per-page GA traffic for content-entitled websites (Keyword Tracker reporting)';

    public function handle(): int
    {
        $coveredIds = ContentPlan::query()
            ->whereNotNull('billing_covered_at')
            ->pluck('website_id');

        $dispatched = 0;
        Website::query()
            ->select('id')
            ->whereIn('id', $coveredIds)
            ->whereNotNull('ga_property_id')
            ->where('ga_property_id', '!=', '')
            ->chunkById(100, function ($websites) use (&$dispatched): void {
                foreach ($websites as $website) {
                    SyncContentPageAnalytics::dispatch($website->id);
                    $dispatched++;
                }
            });

        $this->info("Content performance sync dispatched for {$dispatched} website(s).");

        return self::SUCCESS;
    }
}
