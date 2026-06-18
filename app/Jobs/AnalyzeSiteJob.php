<?php

namespace App\Jobs;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\WebsitePage;
use App\Services\Crawler\InternalLinkSuggester;
use App\Services\Crawler\SiteGraphAnalyzer;
use App\Services\Crawler\SiteIssueDetector;
use App\Support\Crawler\BlockDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-crawl analysis for a shared crawl_site: builds link-graph metrics +
 * value_rank, detects findings (shared catalog), scores pages, rolls up
 * crawl-blocked state, bridges internal 404s into each subscriber's redirect
 * pipeline, resolves stale findings, and closes the run. Cache flushes fan out
 * to every subscriber website.
 */
class AnalyzeSiteJob implements ShouldQueue
{
    use Queueable;

    // Long finalize: a very large site (~168k pages / ~1.5M edges) takes many minutes
    // through graph → value_rank → issue-detection → suggestions → scoring. 1200s
    // timed out mid-detection. Runs on the dedicated `redis-long` connection whose
    // retry_after (3900s) stays safely above this, so a still-running finalize is never
    // re-reserved underneath us. See config/queue.php + horizon $heavyPool.
    public int $timeout = 3600;

    /** Long finalize runs on a connection with retry_after > $timeout (see config/queue.php). */
    public $connection = 'redis-long';

    // tries=2: a single transient failure (e.g. a lock-wait timeout, or a worker
    // recycle) used to permanently fail finalization and strand the run. One retry
    // recovers it.
    public int $tries = 2;

    // Wait between attempts so a contended DB or a slow site has time to settle.
    public int $backoff = 120;

    /** Severity → page-score penalty. */
    private const PENALTY = ['critical' => 40, 'high' => 25, 'medium' => 12, 'low' => 5];

    public function __construct(public string $crawlRunId)
    {
        // The long finalize runs on the dedicated crawl-finalize queue (pinned box
        // only), so an autoscale scale-down draining an ephemeral box can never kill
        // a 1200s analysis mid-flight. See infra/crawler/autoscaling.md.
        $this->onQueue(\App\Support\Queues::CRAWL_FINALIZE);
    }

    /**
     * Never let two finalizes for the same run overlap. The crawl-supervisor
     * re-dispatches AnalyzeSiteJob for any FINALIZING run that looks stalled, but a
     * slow-but-alive finalize (up to $timeout) does not bump the run's updated_at —
     * so without this guard the supervisor would start a SECOND concurrent finalize
     * that fights the first for row locks on the same website_pages (the SQLSTATE
     * 1205 incident, 2026-06-18). dontRelease() drops the duplicate outright;
     * expireAfter() releases the lock if a holder dies hard (so we never wedge).
     *
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('analyze-site:'.$this->crawlRunId))
                ->dontRelease()
                ->expireAfter($this->timeout + 300),
        ];
    }

    /**
     * If analysis fails or times out, the crawl itself still finished — finalize
     * the run so it never stays stuck "running"/"finalizing". Analysis re-runs on
     * the next crawl.
     */
    public function failed(\Throwable $e): void
    {
        $run = CrawlRun::find($this->crawlRunId);
        CrawlRun::where('id', $this->crawlRunId)
            ->whereIn('status', [CrawlRun::STATUS_RUNNING, CrawlRun::STATUS_FINALIZING])
            ->update([
                'status' => CrawlRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'notes' => 'Crawl complete; post-crawl analysis did not finish ('.class_basename($e).').',
            ]);
        if ($run?->crawlSite) {
            $this->flushSubscribers($run->crawlSite);
        }
    }

    public function handle(SiteGraphAnalyzer $graph, SiteIssueDetector $detector, BlockDetector $blockDetector, InternalLinkSuggester $suggester, \App\Support\Crawler\TermExtractor $terms): void
    {
        // Horizon workers inherit PHP's CLI default memory_limit (128M); the pre-Horizon
        // raw finalize worker ran `php -d memory_limit=2048M`, dropped in the migration.
        // The link-graph analysis on a large site (xplate: ~1.5M edges) needs more than
        // 128M and OOM'd. Raise the ceiling for this job. See config/crawler.php.
        ini_set('memory_limit', (string) config('crawler.analyze_memory_limit', '1024M'));

        $run = CrawlRun::find($this->crawlRunId);
        if (! $run) {
            return;
        }
        $crawlSite = $run->crawlSite;
        if (! $crawlSite) {
            $run->update(['status' => CrawlRun::STATUS_FAILED, 'finished_at' => now()]);

            return;
        }
        $crawlSiteId = $crawlSite->id;

        // 1. Crawl-blocked rollup — if the site wholesale-blocked us, abort here
        // and raise a single crawlability finding instead of page-level noise.
        $blockVerdict = $this->blockRollup($crawlSiteId, (int) $run->pages_fetched, $blockDetector);
        if ($blockVerdict['blocked']) {
            $this->recordCrawlability($run, $crawlSite, $blockVerdict['reason']);
            $this->resolveStale($crawlSiteId, $run);
            $crawlSite->forceFill([
                'crawl_protection' => $blockVerdict['reason'] === BlockDetector::CAPTCHA ? 'cloudflare' : 'blocked',
                'crawl_protection_at' => now(),
                'status' => 'blocked',
            ])->save();
            $run->update([
                'status' => CrawlRun::STATUS_ABORTED,
                'blocked_reason' => $blockVerdict['reason'],
                'notes' => 'Crawler blocked by site ('.$blockVerdict['reason'].').',
                'findings_total' => CrawlFinding::where('crawl_site_id', $crawlSiteId)->where('status', 'open')->count(),
                'finished_at' => now(),
            ]);
            $this->flushSubscribers($crawlSite);

            return;
        }

        if ($crawlSite->crawl_protection !== null) {
            $crawlSite->forceFill(['crawl_protection' => null, 'crawl_protection_at' => null])->save();
        }
        // Fetching done → finalizing (banner shows "computing your results").
        $run->update(['status' => CrawlRun::STATUS_FINALIZING]);
        $crawlSite->forceFill(['last_crawl_finished_at' => now()])->save();

        try {
            // 2. Link graph (inbound counts, orphans, click-depth, value_rank).
            $graph->analyze($crawlSite);
            // 3. Findings (shared catalog).
            $detector->detect($crawlSite, $run);
            // 3b. Internal-link suggestions via significant-term overlap (shared).
            [$df, $docs] = $terms->buildDf($crawlSiteId, (int) config('crawler.terms_df_sample', 3000));
            $suggester->suggest($crawlSiteId, $df, $docs);
            // 4. Page + site scores.
            $health = $this->scorePages($crawlSiteId);
            // 5. Resolve findings not re-seen this run.
            $this->resolveStale($crawlSiteId, $run);
            // 6. Bridge internal 404s into each subscriber's redirect pipeline.
            $this->bridge404s($crawlSite);

            if (config('crawler.prune_body_text')) {
                $this->pruneBodyText($crawlSiteId);
            }

            $crawlSite->forceFill(['health_score' => $health, 'status' => 'ready'])->save();
            $run->update([
                'status' => CrawlRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'findings_total' => CrawlFinding::where('crawl_site_id', $crawlSiteId)->where('status', 'open')->count(),
                'health_score' => $health,
            ]);
        } catch (\Throwable $e) {
            // Finalize the run so the banner clears even if enrichment failed.
            $run->update(['status' => CrawlRun::STATUS_COMPLETED, 'finished_at' => now()]);
            Log::error("AnalyzeSiteJob enrichment failed for run {$run->id}: {$e->getMessage()}");
        }

        $this->flushSubscribers($crawlSite);
    }

    /** Invalidate dashboard/report caches for every subscriber of this crawl_site. */
    private function flushSubscribers(CrawlSite $crawlSite): void
    {
        foreach ($crawlSite->websites()->pluck('id') as $wid) {
            \App\Services\ReportCache::flushWebsite((string) $wid);
        }
    }

    /**
     * @return array{blocked:bool,reason:?string}
     */
    private function blockRollup(string $crawlSiteId, int $fetched, BlockDetector $detector): array
    {
        $reasonCounts = [];
        WebsitePage::where('crawl_site_id', $crawlSiteId)
            ->where('http_error', 'like', 'blocked:%')
            ->select('http_error', DB::raw('COUNT(*) as c'))
            ->groupBy('http_error')
            ->each(function ($row) use (&$reasonCounts): void {
                $reason = substr((string) $row->http_error, strlen('blocked:'));
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + (int) $row->c;
            });

        return $detector->rollup($reasonCounts, $fetched);
    }

    private function recordCrawlability(CrawlRun $run, CrawlSite $crawlSite, ?string $reason): void
    {
        $url = $crawlSite->homepageUrl();
        CrawlFinding::updateOrCreate(
            ['crawl_site_id' => $crawlSite->id, 'type' => 'crawl_blocked', 'affected_url_hash' => CrawlFinding::hashUrl($url)],
            [
                'page_id' => null,
                'crawl_run_id' => $run->id,
                'category' => CrawlFinding::CATEGORY_CRAWLABILITY,
                'severity' => CrawlFinding::SEVERITY_CRITICAL,
                'impact' => 0,
                'affected_url' => $url,
                'detail' => ['reason' => $reason],
                'status' => CrawlFinding::STATUS_OPEN,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'resolved_at' => null,
            ],
        );
    }

    private function scorePages(string $crawlSiteId): int
    {
        $penaltyByPage = [];
        CrawlFinding::where('crawl_site_id', $crawlSiteId)
            ->where('status', 'open')
            ->whereNotNull('page_id')
            ->select('page_id', 'severity')
            ->chunk(2000, function ($rows) use (&$penaltyByPage): void {
                foreach ($rows as $r) {
                    $penaltyByPage[$r->page_id] = ($penaltyByPage[$r->page_id] ?? 0) + (self::PENALTY[$r->severity] ?? 5);
                }
            });

        $base = WebsitePage::where('crawl_site_id', $crawlSiteId)
            ->whereNull('removed_at')
            ->whereNotNull('last_crawled_at');
        (clone $base)->update(['page_score' => 100]);

        $idsByScore = [];
        foreach ($penaltyByPage as $pageId => $penalty) {
            $score = max(0, 100 - (int) $penalty);
            if ($score < 100) {
                $idsByScore[$score][] = $pageId;
            }
        }
        foreach ($idsByScore as $score => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                (clone $base)->whereIn('id', $chunk)->update(['page_score' => $score]);
            }
        }

        $avg = (clone $base)->where('is_indexable', true)->avg('page_score');

        return $avg !== null ? (int) round((float) $avg) : 100;
    }

    private function pruneBodyText(string $crawlSiteId): void
    {
        $chars = max(0, (int) config('crawler.body_excerpt_chars', 500));
        WebsitePage::where('crawl_site_id', $crawlSiteId)
            ->whereNotNull('content_terms')
            ->whereNotNull('body_text')
            ->whereRaw('CHAR_LENGTH(body_text) > ?', [$chars])
            ->update(['body_text' => DB::raw("LEFT(body_text, {$chars})")]);
    }

    private function resolveStale(string $crawlSiteId, CrawlRun $run): void
    {
        CrawlFinding::where('crawl_site_id', $crawlSiteId)
            ->where('status', CrawlFinding::STATUS_OPEN)
            ->where('last_seen_at', '<', $run->started_at)
            ->update(['status' => CrawlFinding::STATUS_RESOLVED, 'resolved_at' => now()]);
    }

    /**
     * Bridge internal 404s into the redirect-suggestion pipeline. Redirects are
     * per-website (each subscriber sets up their own), so dispatch one matcher
     * per subscriber for each broken URL the shared crawl found.
     */
    private function bridge404s(CrawlSite $crawlSite): void
    {
        $subscriberIds = $crawlSite->websites()->pluck('id')->all();
        if ($subscriberIds === []) {
            return;
        }

        CrawlFinding::where('crawl_site_id', $crawlSite->id)
            ->where('status', 'open')
            ->whereIn('type', ['broken_internal', 'broken_page'])
            ->select('affected_url', 'impact')
            ->chunk(200, function ($rows) use ($subscriberIds): void {
                foreach ($rows as $r) {
                    $path = parse_url($r->affected_url, PHP_URL_PATH) ?: '/';
                    foreach ($subscriberIds as $wid) {
                        MatchRedirectFor404Job::dispatch((string) $wid, $path, (int) $r->impact)->onQueue(\App\Support\Queues::CRAWL);
                    }
                }
            });
    }
}
