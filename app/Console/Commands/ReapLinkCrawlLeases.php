<?php

namespace App\Console\Commands;

use App\Services\LinkGraph\FrontierClaimer;
use Illuminate\Console\Command;

/**
 * Returns crashed workers' claimed rows to the frontier. A LinkCrawlBatchJob
 * that dies mid-run leaves its rows `in_progress` with a `leased_until` in the
 * past; this sweeps them back to `pending` so they get retried (crash recovery
 * for the concurrent frontier). Scheduled every few minutes.
 */
class ReapLinkCrawlLeases extends Command
{
    protected $signature = 'ebq:reap-link-crawl-leases';

    protected $description = 'Return expired link-crawl leases (crashed workers) to the frontier';

    public function handle(FrontierClaimer $claimer): int
    {
        $reaped = $claimer->reapExpired();
        if ($reaped > 0) {
            $this->info("Reclaimed {$reaped} expired link-crawl lease(s).");
        }

        return self::SUCCESS;
    }
}
