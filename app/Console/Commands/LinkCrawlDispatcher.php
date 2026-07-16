<?php

namespace App\Console\Commands;

use App\Jobs\LinkCrawlBatchJob;
use App\Services\LinkGraph\FrontierClaimer;
use App\Services\LinkGraph\LinkCrawlBudget;
use App\Support\LinkCrawlToggle;
use App\Support\Queues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * The link crawler's dispatcher — keeps the `link-crawl` queue topped up to
 * `target_in_flight` batch jobs so every fleet worker stays busy. Batches
 * self-replace 1:1 once running (so the pool sustains itself between ticks);
 * this scheduled top-up seeds the pool from cold and refills any shortfall
 * (e.g. after batches that claimed nothing wound the pool down, then new work
 * was seeded). No pass barrier — a slow domain never gates the fleet.
 *
 * Runs every minute withoutOverlapping; cheap no-op when disabled, out of
 * budget, or idle.
 */
class LinkCrawlDispatcher extends Command
{
    protected $signature = 'ebq:link-crawl-dispatch';

    protected $description = 'Top up the link-crawl queue to the target in-flight batch count';

    public function handle(LinkCrawlBudget $budget, FrontierClaimer $claimer): int
    {
        if (! LinkCrawlToggle::enabled() || $budget->exhausted()) {
            return self::SUCCESS;
        }
        if (! $claimer->hasDueWork()) {
            return self::SUCCESS;
        }

        $target = max(1, (int) config('crawler.link_crawl.target_in_flight', 40));
        $inFlight = $this->queueDepth();
        $toAdd = max(0, $target - $inFlight);
        if ($toAdd === 0) {
            return self::SUCCESS;
        }

        for ($i = 0; $i < $toAdd; $i++) {
            LinkCrawlBatchJob::dispatch();
        }
        $this->info("Dispatched {$toAdd} link-crawl batches (target {$target}, was {$inFlight} in flight).");

        return self::SUCCESS;
    }

    private function queueDepth(): int
    {
        try {
            return (int) Queue::size(Queues::LINK_CRAWL);
        } catch (\Throwable) {
            return 0;
        }
    }
}
