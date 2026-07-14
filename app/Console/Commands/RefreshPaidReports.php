<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWebsiteReport;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\ReportFreshnessGate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Monthly refresh of report snapshots for domains owned by a paid (isPro)
 * account. Free-owned / anonymous-queried domains refresh lazily on the next
 * query (90-day TTL); paid-owned domains are kept fresh on a 30-day cadence for
 * as long as the site stays in a paid account.
 *
 * Mirrors the TrackRankings dispatch pattern: chunk websites, filter to paid
 * owners in PHP (isPro is computed), dedupe by domain, dispatch the (freshness-
 * gated) GenerateWebsiteReport for any snapshot older than the paid TTL.
 */
class RefreshPaidReports extends Command
{
    protected $signature = 'ebq:refresh-paid-reports {--force : Dispatch every paid-owned domain regardless of freshness}';

    protected $description = 'Refresh report snapshots for paid-owned domains on a monthly cadence';

    public function handle(ReportFreshnessGate $gate): int
    {
        $force = (bool) $this->option('force');
        $cutoff = Carbon::now()->subDays($gate->paidTtlDays());
        $seen = [];
        $dispatched = 0;

        Website::query()
            ->select(['id', 'user_id', 'normalized_domain', 'current_plan_slug', 'trial_ends_at'])
            ->whereNotNull('normalized_domain')
            ->chunkById(200, function ($websites) use (&$seen, &$dispatched, $force, $cutoff) {
                foreach ($websites as $website) {
                    $domain = (string) $website->normalized_domain;
                    if ($domain === '' || isset($seen[$domain])) {
                        continue;
                    }
                    if (! $website->isPro()) {
                        continue;
                    }
                    $seen[$domain] = true;

                    if (! $force) {
                        $snapshot = WebsiteReportSnapshot::query()->where('normalized_domain', $domain)->first();
                        if ($snapshot !== null && $snapshot->fetched_at !== null && $snapshot->fetched_at->greaterThan($cutoff)) {
                            continue;
                        }
                    }

                    GenerateWebsiteReport::dispatch($domain, $force);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} paid report refresh job(s).");

        return self::SUCCESS;
    }
}
