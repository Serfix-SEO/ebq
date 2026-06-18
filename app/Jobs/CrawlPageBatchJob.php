<?php

namespace App\Jobs;

use App\Models\CrawlSite;
use App\Models\WebsitePage;
use App\Services\Crawler\PageCrawlProcessor;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Crawls one chunk of pages for a website. Sequential within the chunk with a
 * per-host politeness delay; conditional-GET + content-hash skipping happens
 * inside PageCrawlProcessor. Updates the crawl_runs counters atomically.
 */
class CrawlPageBatchJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;
    public int $tries = 2;

    /**
     * @param  list<int>  $pageIds
     */
    public function __construct(
        public array $pageIds,
        public string $crawlRunId,
    ) {}

    public function handle(PageCrawlProcessor $processor): void
    {
        // Horizon workers inherit PHP's CLI default memory_limit (128M); the pre-Horizon
        // raw crawl worker ran `php -d memory_limit=2048M`, dropped in the migration, so
        // HtmlAuditor OOM'd at 128M on large pages. Set the ceiling in code so it applies
        // on EVERY box — the pinned box AND every autoscaled ephemeral crawl box (which
        // run this same job). See config/crawler.php.
        ini_set('memory_limit', (string) config('crawler.batch_memory_limit', '512M'));

        if ($this->batch()?->cancelled()) {
            return;
        }

        $delayMs = max(0, (int) config('crawler.delay_ms', 250));
        $counts = ['fetched' => 0, 'not_modified' => 0, 'changed' => 0, 'error' => 0];

        $pages = WebsitePage::whereIn('id', $this->pageIds)->whereNull('removed_at')->get();
        // Resolved once and shared across the batch so the per-domain crawl-protection
        // flag (Cloudflare/blocked) is read/written in memory, not re-queried per page.
        $crawlSite = ($csid = $pages->first()?->crawl_site_id) ? CrawlSite::find($csid) : null;
        foreach ($pages as $i => $page) {
            try {
                $outcome = $processor->process($page, $crawlSite);
            } catch (\Throwable $e) {
                $outcome = 'error';
            }

            $counts['fetched']++;
            if ($outcome === 'not_modified') {
                $counts['not_modified']++;
            } elseif ($outcome === 'changed') {
                $counts['changed']++;
            } elseif ($outcome === 'error' || $outcome === 'blocked') {
                $counts['error']++;
            }

            if ($delayMs > 0 && $i < $pages->count() - 1) {
                usleep($delayMs * 1000);
            }
        }

        DB::table('crawl_runs')->where('id', $this->crawlRunId)->update([
            'pages_fetched' => DB::raw('pages_fetched + '.$counts['fetched']),
            'pages_304' => DB::raw('pages_304 + '.$counts['not_modified']),
            'pages_changed' => DB::raw('pages_changed + '.$counts['changed']),
            'pages_error' => DB::raw('pages_error + '.$counts['error']),
            'updated_at' => now(),
        ]);
    }
}
