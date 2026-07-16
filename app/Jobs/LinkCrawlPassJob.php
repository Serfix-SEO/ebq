<?php

namespace App\Jobs;

use App\Models\LinkCrawlFrontier;
use App\Services\LinkGraph\LinkCrawlBudget;
use App\Support\Queues;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One pass of the Tier-1.5 link crawler: pull the next batch of due frontier
 * URLs, fan them out into LinkCrawlBatchJobs, and — via the batch's finally
 * hook — dispatch the NEXT pass so the crawler self-perpetuates while there's
 * work and daily budget left (mirrors CrawlPassJob). Runs on the `crawl`
 * queue (worker box B / ephemeral fleet). A cache heartbeat lets the
 * supervisor restart the chain if it ever dies.
 */
class LinkCrawlPassJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public const HEARTBEAT_KEY = 'linkcrawl:heartbeat';

    public function __construct(public int $pass = 1)
    {
        $this->onQueue(Queues::CRAWL);
    }

    public function handle(LinkCrawlBudget $budget): void
    {
        if (! \App\Support\LinkCrawlToggle::enabled()) {
            Cache::forget(self::HEARTBEAT_KEY);

            return;
        }
        // Keep the heartbeat warm so the supervisor knows a chain is alive.
        Cache::put(self::HEARTBEAT_KEY, now()->timestamp, now()->addMinutes(15));

        if ($budget->exhausted()) {
            Cache::forget(self::HEARTBEAT_KEY); // done for the day; supervisor won't restart until seeded work + budget

            return;
        }

        $perPass = max(1, (int) config('crawler.link_crawl.pages_per_pass', 500));
        $batchSize = max(1, (int) config('crawler.link_crawl.batch_size', 20));

        $due = LinkCrawlFrontier::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->orderBy('next_at')
            ->limit($perPass)
            ->pluck('id');

        if ($due->isEmpty()) {
            Cache::forget(self::HEARTBEAT_KEY); // nothing to do; supervisor restarts after next seed

            return;
        }

        $jobs = $due->chunk($batchSize)
            ->map(fn ($ids) => new LinkCrawlBatchJob($ids->values()->all()))
            ->all();

        $nextPass = $this->pass + 1;
        Bus::batch($jobs)
            ->name('link-crawl-pass-'.$this->pass)
            ->onQueue(Queues::CRAWL)
            ->finally(function (Batch $batch) use ($nextPass) {
                // Chain the next pass regardless of individual failures — the
                // enabled/budget/empty guards at the top stop the loop.
                LinkCrawlPassJob::dispatch($nextPass);
            })
            ->dispatch();
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget(self::HEARTBEAT_KEY);
    }
}
