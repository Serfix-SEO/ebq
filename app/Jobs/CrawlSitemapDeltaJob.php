<?php

namespace App\Jobs;

use App\Models\CrawlRun;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\Crawler\SitemapUrlExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily sitemap-delta check: re-reads the site's XML sitemaps and, for any URL
 * not already known (not in website_pages), seeds it as due-now and kicks off a
 * crawl so brand-new pages are discovered + crawled within a day instead of
 * waiting for the weekly full recrawl. URLs already crawled or already queued
 * (present in website_pages) are skipped.
 */
class CrawlSitemapDeltaJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 180;
    public int $tries = 2;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(\App\Support\Queues::CRAWL);
    }

    public function uniqueId(): string
    {
        $csid = Website::where('id', $this->websiteId)->value('crawl_site_id');

        return 'sitemap-delta-'.($csid ?: 'w'.$this->websiteId);
    }

    public function uniqueFor(): int
    {
        return 3600 * 3;
    }

    public function handle(SitemapUrlExtractor $extractor): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::find($this->websiteId);
        if (! $website || $website->isFrozen() || ! $website->crawl_site_id) {
            return;
        }
        $crawlSite = $website->crawlSite;
        if (! $crawlSite) {
            return;
        }

        $paths = $website->sitemaps()->pluck('path')->all();
        if ($paths === []) {
            return;
        }

        // url_hash => ['url' => string, 'lastmod' => ?Carbon] for every sitemap <loc>.
        $candidates = [];
        foreach ($extractor->extract($paths) as $entry) {
            $url = trim((string) $entry['loc']);
            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $url = \App\Support\Crawler\FrontierUrl::collapse($url);
            $candidates[WebsitePage::hashUrl($url)] = [
                'url' => $url,
                'lastmod' => $this->parseLastmod($entry['lastmod'] ?? null),
            ];
        }
        if ($candidates === []) {
            return;
        }

        $now = now();

        // Which of these are already known (crawled OR already queued as a row)?
        $known = WebsitePage::where('crawl_site_id', $crawlSite->id)
            ->whereIn('url_hash', array_keys($candidates))
            ->pluck('url_hash')
            ->all();
        $newHashes = array_diff(array_keys($candidates), $known);

        // (1) Seed brand-new URLs as due-now (with their sitemap lastmod).
        foreach (array_chunk($newHashes, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $hash) {
                $rows[] = [
                    'crawl_site_id' => $crawlSite->id,
                    'url' => mb_substr($candidates[$hash]['url'], 0, 2048),
                    'url_hash' => $hash,
                    'source_sitemap' => true,
                    'sitemap_lastmod' => $candidates[$hash]['lastmod'],
                    'discovered_at' => $now,
                    'next_crawl_at' => $now, // due immediately
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('website_pages')->insertOrIgnore(ulid_rows($rows));
        }

        // (2) Refresh sitemap_lastmod on existing pages, and on TRUSTED sites pull
        // genuinely-updated pages forward (early recrawl). Skip sites whose lastmod
        // is confirmed-meaningless (always-bumping) — adaptive scheduling carries them.
        $triggered = 0;
        if (! $crawlSite->sitemapLastmodConfirmedUntrusted()) {
            $trusted = $crawlSite->sitemapLastmodTrusted();
            WebsitePage::where('crawl_site_id', $crawlSite->id)
                ->whereIn('url_hash', $known)
                ->whereNull('removed_at')
                ->select('id', 'url_hash', 'sitemap_lastmod', 'last_crawled_at', 'next_crawl_at')
                ->chunkById(1000, function ($pages) use ($candidates, $trusted, $now, &$triggered): void {
                    foreach ($pages as $p) {
                        $newLm = $candidates[$p->url_hash]['lastmod'] ?? null;
                        if ($newLm === null) {
                            continue;
                        }
                        // Only act when the sitemap lastmod actually advanced.
                        if ($p->sitemap_lastmod !== null && ! $newLm->greaterThan($p->sitemap_lastmod)) {
                            continue;
                        }
                        $update = ['sitemap_lastmod' => $newLm];
                        if ($trusted
                            && ($p->last_crawled_at === null || $newLm->greaterThan($p->last_crawled_at))
                            && ($p->next_crawl_at === null || $p->next_crawl_at->greaterThan($now))) {
                            $update['next_crawl_at'] = $now; // due now
                            $triggered++;
                        }
                        WebsitePage::whereKey($p->id)->update($update);
                    }
                });
        }

        if ($newHashes === [] && $triggered === 0) {
            return;
        }

        Log::info("CrawlSitemapDeltaJob: {$website->id} new=".count($newHashes)." lastmod-triggered={$triggered}; crawling.");
        CrawlWebsitePagesJob::dispatch($website->id, CrawlRun::TRIGGER_SITEMAP_DELTA);
    }

    private function parseLastmod(?string $value): ?\Illuminate\Support\Carbon
    {
        $value = $value !== null ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
