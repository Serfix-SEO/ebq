<?php

namespace App\Services\Crawler;

use App\Models\CrawlSite;
use App\Models\SearchConsoleData;
use App\Models\WebsitePage;
use App\Support\Crawler\SitemapUrlExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Builds/refreshes the crawl frontier for a shared crawl_site: the union of
 * every page URL Search Console knows about AND every <loc> in the XML sitemaps,
 * aggregated across ALL subscriber websites (so the single shared crawl covers
 * everyone). Seeds/updates rows in website_pages keyed by crawl_site_id (without
 * touching crawl data) and marks discovery source. source_gsc is an aggregate
 * ("any subscriber's GSC has this URL") used only for value ordering — no
 * per-user data leaks. New rows are due immediately (next_crawl_at = now).
 */
class CrawlFrontierBuilder
{
    public function __construct(private readonly SitemapUrlExtractor $sitemapExtractor) {}

    /**
     * @return array{discovered:int, from_gsc:int, from_sitemap:int}
     */
    public function build(CrawlSite $crawlSite): array
    {
        $now = now();
        $subscriberIds = $crawlSite->websites()->pluck('id')->all();

        // url_hash => ['url' => string, 'gsc' => bool, 'sitemap' => bool]
        $candidates = [];

        // (a) GSC pages — union across every subscriber's Search Console data.
        $fromGsc = 0;
        if ($subscriberIds !== []) {
            SearchConsoleData::query()
                ->whereIn('website_id', $subscriberIds)
                ->where('page', '!=', '')
                ->select('page')
                ->distinct()
                ->orderBy('page')
                ->chunk(2000, function ($rows) use (&$candidates, &$fromGsc): void {
                    foreach ($rows as $row) {
                        $url = trim((string) $row->page);
                        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                            continue;
                        }
                        $url = \App\Support\Crawler\FrontierUrl::collapse($url);
                        $hash = WebsitePage::hashUrl($url);
                        $candidates[$hash] ??= ['url' => $url, 'gsc' => false, 'sitemap' => false];
                        $candidates[$hash]['gsc'] = true;
                        $fromGsc++;
                    }
                });
        }

        // Always seed the canonical homepage so a domain-only site (no GSC, no
        // sitemap yet) still has a frontier to crawl from.
        $home = $crawlSite->homepageUrl();
        $homeHash = WebsitePage::hashUrl($home);
        $candidates[$homeHash] ??= ['url' => $home, 'gsc' => false, 'sitemap' => false];

        // (b) Sitemap <loc> URLs — union across every subscriber's sitemaps.
        $fromSitemap = 0;
        $sitemapPaths = $subscriberIds === [] ? [] : DB::table('website_sitemaps')
            ->whereIn('website_id', $subscriberIds)->distinct()->pluck('path')->all();
        foreach ($this->sitemapExtractor->extract($sitemapPaths) as $entry) {
            $url = trim((string) $entry['loc']);
            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $url = \App\Support\Crawler\FrontierUrl::collapse($url);
            $hash = WebsitePage::hashUrl($url);
            $candidates[$hash] ??= ['url' => $url, 'gsc' => false, 'sitemap' => false];
            $candidates[$hash]['sitemap'] = true;
            $fromSitemap++;
        }

        // Scope gate: a sitemap (or a GSC property covering several hosts) can
        // list URLs on any domain, and every row inserted here is crawled. Keep
        // the frontier inside the site it belongs to — same boundary
        // PageCrawlProcessor::rebuildEdges() enforces for discovered links.
        $candidates = array_filter(
            $candidates,
            fn ($c) => \App\Support\DomainName::urlBelongsToSite((string) $c['url'], (string) $crawlSite->normalized_domain),
        );

        if ($candidates === []) {
            return ['discovered' => 0, 'from_gsc' => 0, 'from_sitemap' => 0];
        }

        $discovered = 0;
        foreach (array_chunk($candidates, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $hash => $c) {
                $rows[] = [
                    'crawl_site_id' => $crawlSite->id,
                    'url' => mb_substr($c['url'], 0, 2048),
                    'url_hash' => $hash,
                    'source_gsc' => $c['gsc'],
                    'source_sitemap' => $c['sitemap'],
                    'discovered_at' => $now,
                    'next_crawl_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // Insert new pages only; never clobber existing crawl data. We OR the
            // source flags for existing rows in a follow-up update.
            $discovered += DB::table('website_pages')->insertOrIgnore(ulid_rows($rows));
        }

        // OR the discovery-source flags onto pre-existing rows.
        foreach (array_chunk(array_keys($candidates), 500) as $hashChunk) {
            $gscHashes = array_filter($hashChunk, fn ($h) => $candidates[$h]['gsc']);
            $sitemapHashes = array_filter($hashChunk, fn ($h) => $candidates[$h]['sitemap']);
            if ($gscHashes) {
                WebsitePage::where('crawl_site_id', $crawlSite->id)->whereIn('url_hash', $gscHashes)->where('source_gsc', false)->update(['source_gsc' => true]);
            }
            if ($sitemapHashes) {
                WebsitePage::where('crawl_site_id', $crawlSite->id)->whereIn('url_hash', $sitemapHashes)->where('source_sitemap', false)->update(['source_sitemap' => true]);
            }
        }

        return ['discovered' => $discovered, 'from_gsc' => $fromGsc, 'from_sitemap' => $fromSitemap];
    }
}
