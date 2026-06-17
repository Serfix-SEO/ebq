<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\WebsitePage;
use App\Support\Crawler\CrawlValueRank;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * One pass of a multi-pass crawl. Selects the pages due for crawling that have
 * NOT yet been fetched this run, fans them out across CrawlPageBatchJob children,
 * and — when that batch finishes — dispatches the NEXT pass. Pages discovered
 * mid-run (on-page links that created stub rows, e.g. the homepage's category
 * pages) are due + uncrawled, so the next pass picks them up. The loop ends when
 * a pass finds nothing new, the pass cap is reached, or the per-run page cap is
 * hit; then AnalyzeSiteJob computes the link graph + findings + scores.
 *
 * This is what makes the link graph reach the whole site from the homepage:
 * a single pass only crawls the pre-selected frontier and leaves linked-to stubs
 * uncrawled (no outbound edges → disconnected graph → false orphan/deep flags).
 */
class CrawlPassJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $crawlRunId,
        public string $crawlSiteId,
        public int $pass = 1,
        public bool $force = false,
    ) {
        $this->onQueue(\App\Support\Queues::CRAWL);
    }

    public function handle(): void
    {
        app(\App\Support\ShardContext::class)->forCrawlSite((string) $this->crawlSiteId);
        $run = CrawlRun::find($this->crawlRunId);
        if (! $run || $run->status !== CrawlRun::STATUS_RUNNING) {
            return; // run vanished or already finalized (e.g. blocked) — stop the loop.
        }
        $crawlSite = CrawlSite::find($this->crawlSiteId);
        if (! $crawlSite) {
            return;
        }

        // Shared crawl depth = MAX page cap among all subscribers. Re-read each pass
        // so a higher-cap subscriber joining mid-crawl extends the in-flight crawl.
        $maxPages = max(1, (int) $crawlSite->effective_cap ?: (int) config('crawler.max_pages_per_run', 200000));
        // Fairness: crawl at most this many pages per pass so a large site can't
        // enqueue its whole frontier in one Bus::batch and starve every other site on
        // the shared crawl queue. Each pass dispatches the next to the BACK of the
        // queue, so concurrent crawls interleave (round-robin) instead of one big
        // site monopolising the 5 workers. Smaller batches also complete faster, so a
        // worker --max-time recycle is far less likely to drop the batch mid-flight.
        $pagesPerPass = max(1, (int) config('crawler.pages_per_pass', 1000));
        // Runaway guard: passes scale with cap / per-pass size, so this ceiling is
        // generous. Each pass either advances toward maxPages or terminates on an
        // empty selection, so the loop is bounded even without it.
        $passCeiling = (int) ceil($maxPages / $pagesPerPass) * 2 + 50;

        // Stop conditions: per-run page budget reached or runaway ceiling → finalize.
        if ($this->pass > $passCeiling || (int) $run->pages_seen >= $maxPages) {
            if ($this->pass > $passCeiling) {
                Log::warning("CrawlPassJob: pass ceiling ({$passCeiling}) reached for run {$run->id}; finalizing.");
            }
            AnalyzeSiteJob::dispatch($run->id)->onQueue(\App\Support\Queues::CRAWL_FINALIZE);

            return;
        }

        // Pages to crawl this pass: due (or everything when forced) and NOT yet
        // fetched during THIS run. Pages crawled earlier in the run have
        // last_crawled_at >= started_at and drop out; mid-run stubs (last_crawled_at
        // null) and any still-due pages stay in. Errored/unchanged pages push their
        // next_crawl_at into the future, so they won't loop.
        $query = WebsitePage::where('crawl_site_id', $crawlSite->id)
            ->whereNull('removed_at')
            ->where(function ($q) use ($run): void {
                $q->whereNull('last_crawled_at')
                    ->orWhere('last_crawled_at', '<', $run->started_at);
            });
        if (! $this->force) {
            $query->due();
        }

        // Respect the remaining per-run budget so a single pass can't blow past it.
        // Crawl the highest-value pages first so a capped budget buys what matters:
        // GSC-trafficked → in sitemap → shallow URLs (closer to the homepage).
        // Cap this pass at the smaller of the remaining budget and the per-pass limit
        // (the per-pass limit is what yields the queue to other sites — see above).
        $remaining = min(max(0, $maxPages - (int) $run->pages_seen), $pagesPerPass);
        $pageIds = CrawlValueRank::order($query)
            ->limit($remaining)->pluck('id')->all();

        if ($pageIds === []) {
            // Nothing new to crawl — the graph is as complete as it gets. Analyze.
            AnalyzeSiteJob::dispatch($run->id)->onQueue(\App\Support\Queues::CRAWL_FINALIZE);

            return;
        }

        $run->increment('pages_seen', count($pageIds));
        Log::info("CrawlPassJob: run {$run->id} pass {$this->pass} crawling ".count($pageIds).' pages.');

        $batchSize = max(1, (int) config('crawler.batch_size', 25));
        $jobs = [];
        foreach (array_chunk($pageIds, $batchSize) as $chunk) {
            $jobs[] = new CrawlPageBatchJob($chunk, $run->id);
        }

        $runId = $run->id;
        $crawlSiteId = $crawlSite->id;
        $nextPass = $this->pass + 1;
        $force = $this->force;

        Bus::batch($jobs)
            ->name("crawl-site-{$crawlSiteId}-pass-{$this->pass}")
            ->allowFailures()
            ->onQueue(\App\Support\Queues::CRAWL)
            ->finally(function (Batch $batch) use ($runId, $crawlSiteId, $nextPass, $force): void {
                CrawlPassJob::dispatch($runId, $crawlSiteId, $nextPass, $force)
                    ->onQueue(\App\Support\Queues::CRAWL);
            })
            ->dispatch();
    }
}
