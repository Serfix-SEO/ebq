<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\Website;
use App\Services\Crawler\CrawlFrontierBuilder;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a full crawl of one website (the job named in the original
 * website_pages migration intent). Builds the GSC ∪ sitemap frontier, then
 * fans the due pages out across CrawlPageBatchJob children in a batch; when the
 * batch finishes, AnalyzeSiteJob computes the link graph + findings + scores.
 *
 * ShouldBeUnique per website so scheduled + on-create triggers can't overlap.
 */
class CrawlWebsitePagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $websiteId,
        public string $trigger = CrawlRun::TRIGGER_SCHEDULED,
        public bool $force = false,
    ) {
        $this->onQueue(Queues::CRAWL);
    }

    /** Unique per shared crawl_site so two subscribers can't double-crawl a domain. */
    public function uniqueId(): string
    {
        $crawlSiteId = Website::where('id', $this->websiteId)->value('crawl_site_id');

        return 'crawl-site-'.($crawlSiteId ?: 'w'.$this->websiteId);
    }

    public function uniqueFor(): int
    {
        return 3600 * 6; // 6h lock — longer than any reasonable single crawl
    }

    public function handle(CrawlFrontierBuilder $frontier): void
    {
        if (ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
        app(ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::find($this->websiteId);
        if (! $website) {
            return;
        }
        // Freeze is a DASHBOARD plan-limit state. A site the customer buys
        // Content Autopilot for must still be crawled: the crawl is what feeds
        // the business profile, internal linking, cannibalization checks and
        // keyword seeds. Skipping it would silently degrade a product they pay
        // for. crawlPageCap() already clamps content-only sites to a small cap.
        if ($website->isFrozen() && ! $website->contentAutopilotEntitled()) {
            Log::info("CrawlWebsitePagesJob: skipping frozen website {$this->websiteId}");

            return;
        }

        $crawlSite = $this->resolveCrawlSite($website);
        if ($crawlSite === null) {
            Log::info("CrawlWebsitePagesJob: website {$this->websiteId} has no crawlable domain; skipping.");

            return;
        }

        // Start-of-crawl is serialized with a short atomic lock per crawl_site so two
        // near-simultaneous dispatches can't both pass the isCrawling() check and
        // create duplicate runs. ShouldBeUnique only de-dupes *queued* jobs; once two
        // are already processing (e.g. a rapid re-dispatch, or a leaked unique lock)
        // the queue lock is gone, so we need this DB-state guard too. The lock has a
        // short TTL so a dead worker can't hold it permanently. Once the run exists,
        // isCrawling() is true for everyone else, so the lock only needs to cover the
        // check + create — the frontier build + pass dispatch happen outside it.
        $startLock = Cache::lock('crawl-site-start-'.$crawlSite->id, 30);
        if (! $startLock->get()) {
            return; // another worker is already starting a run for this crawl_site
        }
        try {
            if ($crawlSite->isCrawling()) {
                return;
            }
            $run = CrawlRun::create([
                'crawl_site_id' => $crawlSite->id,
                'trigger' => $this->trigger,
                'status' => CrawlRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);
            $crawlSite->forceFill(['status' => 'crawling', 'last_crawl_started_at' => now()])->save();
        } finally {
            $startLock->release();
        }

        try {
            $frontier->build($crawlSite);
        } catch (Throwable $e) {
            Log::warning("CrawlWebsitePagesJob: frontier build failed for crawl_site {$crawlSite->id}: {$e->getMessage()}");
        }

        // Hand off to the multi-pass loop: crawls the due frontier, re-selects
        // mid-run discoveries (link stubs), until the site is exhausted. Each pass
        // dispatches the next; the final pass dispatches AnalyzeSiteJob.
        $run->update(['pages_seen' => 0]);
        CrawlPassJob::dispatch($run->id, $crawlSite->id, 1, $this->force)
            ->onQueue(Queues::CRAWL);
    }

    /** Resolve (creating + linking if needed) the shared crawl_site for this website. */
    private function resolveCrawlSite(Website $website): ?CrawlSite
    {
        if ($website->crawl_site_id) {
            return $website->crawlSite;
        }
        $domain = CrawlSite::normalizeDomain((string) $website->domain);
        if ($domain === '') {
            return null;
        }
        $site = CrawlSite::firstOrCreate(['normalized_domain' => $domain]);
        $website->forceFill(['crawl_site_id' => $site->id])->saveQuietly();
        $site->recomputeEffectiveCap();

        return $site;
    }
}
