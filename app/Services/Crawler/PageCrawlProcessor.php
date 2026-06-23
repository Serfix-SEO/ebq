<?php

namespace App\Services\Crawler;

use App\Models\CrawlSite;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use App\Support\Crawler\BlockDetector;
use App\Support\Crawler\PageAnalyzer;
use App\Support\Crawler\SimHash;
use Illuminate\Support\Facades\DB;

/**
 * Crawls a single WebsitePage: conditional fetch, classify, analyze, persist
 * the inventory row, and (re)build its outbound internal-link edges (creating
 * stub pages for off-frontier internal targets so the graph stays complete).
 *
 * Returns an outcome string used for crawl_runs counters:
 *   not_modified | changed | unchanged | error | blocked
 */
class PageCrawlProcessor
{
    /** Errors/blocks are retried sooner than the adaptive content interval. */
    private const RETRY_INTERVAL_DAYS = 1;

    /** Cap outbound internal edges built per page (trap protection). */
    private const MAX_EDGES_PER_PAGE = 500;

    /** External link targets sampled per page for the broken-external pass. */
    private const MAX_EXTERNAL_SAMPLE = 25;

    public function __construct(
        private readonly CrawlFetcher $fetcher,
        private readonly PageAnalyzer $analyzer,
        private readonly BlockDetector $blockDetector,
        private readonly ProxyPool $pool,
        private readonly DomainRateLimiter $rateLimiter,
    ) {}

    public function process(WebsitePage $page, ?CrawlSite $website = null): string
    {
        // Param canonicalization: a URL that collapses to a different one is a
        // parameter variant we no longer track separately. Soft-remove it from
        // the inventory (excluded from due-crawl, findings, and the graph) so
        // existing ?name=…/?nick=… rows self-prune on the next crawl.
        if (\App\Support\Crawler\FrontierUrl::collapse($page->url) !== $page->url) {
            $page->forceFill(['removed_at' => now(), 'is_indexable' => false, 'next_crawl_at' => null])->save();

            return 'removed';
        }

        $res = $this->fetchWithPolicy($page, $website);

        $retry = $this->scheduleNext($page, self::RETRY_INTERVAL_DAYS);
        $baseNext = $this->scheduleNext($page, (int) config('crawler.recrawl_base_days', 7));

        // Did the sitemap's <lastmod> "claim" a change since our last crawl? Used
        // to learn whether this site's lastmod is a trustworthy freshness signal.
        $lastmodClaimedChange = $page->sitemap_lastmod !== null
            && $page->last_crawled_at !== null
            && $page->sitemap_lastmod->greaterThan($page->last_crawled_at);

        // Transport error / SSRF block (not a bot-wall — a real failure to fetch).
        if (! $res['ok']) {
            $page->forceFill([
                'http_status' => $res['status'],
                'http_error' => mb_substr((string) $res['error'], 0, 255),
                'last_crawled_at' => now(),
                'next_crawl_at' => $retry,
            ])->save();

            return 'error';
        }

        // Conditional GET said nothing changed → extend the recrawl interval.
        if ($res['not_modified']) {
            $consec = (int) $page->consecutive_unchanged + 1;
            $this->recordLastmodTrust($website, $lastmodClaimedChange, false);
            $page->forceFill([
                'http_status' => 304,
                'http_error' => null,
                'last_crawled_at' => now(),
                'consecutive_unchanged' => $consec,
                'next_crawl_at' => $this->adaptiveNext($page, $consec),
            ])->save();

            return 'not_modified';
        }

        $status = (int) $res['status'];

        // Bot-wall / CAPTCHA / rate-limit / login challenge.
        $blockReason = $this->blockDetector->classify([
            'status' => $status,
            'body' => $res['body'],
            'headers' => $res['headers'],
        ]);
        if ($blockReason !== null) {
            $page->forceFill([
                'http_status' => $status,
                'http_error' => 'blocked:'.$blockReason,
                'last_crawled_at' => now(),
                'next_crawl_at' => $retry,
            ])->save();

            return 'blocked';
        }

        // 4xx / 5xx — record, mark non-indexable; 410 is an authoritative removal.
        if ($status >= 400) {
            $page->forceFill([
                'http_status' => $status,
                'is_indexable' => false,
                'http_error' => null,
                'last_crawled_at' => now(),
                'last_changed_at' => now(),
                'next_crawl_at' => $baseNext,
                'removed_at' => $status === 410 ? now() : $page->removed_at,
            ])->save();

            return 'changed';
        }

        // Non-HTML response (image/pdf/font/etc, reached via an image sitemap or a
        // direct <a href> to an asset) — running on-page SEO analysis on it produces
        // nonsense findings (missing_title/h1/meta/og/structured_data/thin_content on
        // a JPEG). Mark non-indexable and stop here instead of feeding it to the HTML
        // analyzer. Fixed 2026-06-23 — see infra/crawler/known-issues.md.
        $contentType = strtolower(trim(explode(';', (string) $res['content_type'])[0]));
        if ($contentType !== '' && $contentType !== 'text/html' && $contentType !== 'application/xhtml+xml') {
            $page->forceFill([
                'http_status' => $status,
                'is_indexable' => false,
                'http_error' => null,
                'last_crawled_at' => now(),
                'last_changed_at' => now(),
                'next_crawl_at' => $baseNext,
            ])->save();

            return 'asset';
        }

        // 2xx (possibly via redirect). Analyze the HTML — relative links in the body
        // must resolve against the EFFECTIVE (post-redirect) URL, not the originally
        // requested one. Using $page->url here was a bug: on a site that redirects
        // apex->www (or http->https) for every page, the actual HTML comes from the
        // www host but every relative href got re-anchored to the apex host, so each
        // newly discovered link inherited the same redirect — cascading a single
        // host-level redirect into a "redirecting_url" finding on every single page.
        // Confirmed 2026-06-23 on a live site: 28/28 pages flagged. See known-issues.md.
        $redirected = (bool) $res['redirected'];
        $effectiveUrl = $redirected && $res['redirect_target'] ? (string) $res['redirect_target'] : $page->url;
        $analysis = $this->analyzer->analyze($effectiveUrl, $res['body'], $res['headers']);

        // Noise-tolerant change detection: a few rotating ads / timestamps / counters
        // barely move the SimHash, so only a distance beyond the threshold (or the
        // first ever crawl) counts as a real change. This drives the recrawl streak,
        // last_changed_at, edge rebuilds, and the sitemap-lastmod trust signal.
        $newSimhash = SimHash::fingerprint((string) $analysis['body_text']);
        $prevSimhash = $page->content_simhash;
        $significantlyChanged = $prevSimhash === null
            || SimHash::distance($newSimhash, $prevSimhash) > (int) config('crawler.simhash_threshold', 3);

        $consec = $significantlyChanged ? 0 : ((int) $page->consecutive_unchanged + 1);
        $this->recordLastmodTrust($website, $lastmodClaimedChange, $significantlyChanged);

        $page->forceFill([
            'http_status' => $status,
            'title' => mb_substr((string) $analysis['title'], 0, 512),
            'meta_description' => mb_substr((string) $analysis['meta_description'], 0, 1024) ?: null,
            'canonical_url' => $analysis['canonical_url'] ? mb_substr($analysis['canonical_url'], 0, 2048) : null,
            'is_indexable' => $redirected ? false : $analysis['is_indexable'],
            'robots_directives' => $analysis['robots_directives'],
            'redirect_target' => $redirected ? mb_substr((string) $res['redirect_target'], 0, 2048) : null,
            'content_hash' => $analysis['content_hash'],
            'content_simhash' => $newSimhash,
            'etag' => $res['etag'],
            'last_modified_header' => $res['last_modified'],
            'content_length' => strlen($res['body']),
            'word_count' => $analysis['word_count'],
            'headings_json' => $analysis['headings_json'],
            'seo_signals' => $this->seoSignals($analysis, $res),
            'body_text' => $analysis['body_text'],
            // Compact language-agnostic term candidates (drives the link suggester;
            // lets body_text be pruned post-analysis).
            'content_terms' => json_encode(
                app(\App\Support\Crawler\TermExtractor::class)->candidates(
                    (string) $analysis['title'],
                    (string) $analysis['body_text'],
                    (string) $page->url,
                ),
                JSON_UNESCAPED_UNICODE,
            ),
            'internal_link_count' => $analysis['internal_link_count'],
            'external_link_count' => $analysis['external_link_count'],
            'http_error' => null,
            'last_crawled_at' => now(),
            'consecutive_unchanged' => $consec,
            'last_changed_at' => $significantlyChanged ? now() : $page->last_changed_at,
            'next_crawl_at' => $this->adaptiveNext($page, $consec),
            'removed_at' => null,
        ])->save();

        // Rebuild edges when content meaningfully changed (links may differ).
        if ($significantlyChanged) {
            $this->rebuildEdges($page, $analysis['internal_links']);
        }

        return $significantlyChanged ? 'changed' : 'unchanged';
    }

    /** Days until next recrawl: back off geometrically from min→max while unchanged. */
    private function adaptiveIntervalDays(int $consecutiveUnchanged): int
    {
        $min = (int) config('crawler.recrawl_min_days', 3);
        $max = (int) config('crawler.recrawl_max_days', 30);
        // Cap the exponent so the multiplication can't overflow before clamping.
        $days = $min * (2 ** min(max(0, $consecutiveUnchanged), 20));

        return max($min, min($max, (int) $days));
    }

    private function adaptiveNext(WebsitePage $page, int $consecutiveUnchanged): \Illuminate\Support\Carbon
    {
        return $this->scheduleNext($page, $this->adaptiveIntervalDays($consecutiveUnchanged));
    }

    /**
     * Accumulate per-site evidence of whether a sitemap <lastmod> bump actually
     * predicts a content change (only counts crawls the lastmod "claimed").
     * Atomic increment so concurrent batch workers don't race.
     */
    private function recordLastmodTrust(?CrawlSite $website, bool $claimed, bool $significantlyChanged): void
    {
        if ($website === null || ! $claimed) {
            return;
        }
        CrawlSite::where('id', $website->id)
            ->increment($significantlyChanged ? 'sitemap_lastmod_true' : 'sitemap_lastmod_false');
    }

    /**
     * Fetch a page applying the adaptive anti-blocking policy:
     *  - sites already flagged cloudflare/blocked (or mode=always) go through a
     *    proxy from the first attempt;
     *  - a direct fetch that gets blocked is retried once through a pool proxy
     *    ("change IP").
     * The persistent crawl_protection flag (proxy preemption + allowlist banner)
     * is owned by AnalyzeSiteJob's run-level block rollup, not set per page here.
     *
     * @return array<string,mixed>
     */
    private function fetchWithPolicy(WebsitePage $page, ?CrawlSite $website): array
    {
        $conditional = ['etag' => $page->etag, 'last_modified' => $page->last_modified_header];
        $timeout = (int) config('crawler.timeout', 20);

        $domain = $website?->normalized_domain ?: parse_url($page->url, PHP_URL_HOST) ?: '';

        // Per-SERVER per-domain politeness (Redis) before we fetch. Also eases the rate to
        // 1/4 if some boxes are already blocked here ("cautious"). See infra/crawler/autoscaling.md.
        $this->rateLimiter->throttle($domain);

        // Use a proxy FIRST whenever the pool has one — never expose the server's OWN IP if
        // we can avoid it (a blocked server IP makes the whole box useless; pool IPs are
        // disposable/rotatable). Fetch DIRECT (own IP) only as a fallback when no proxy is
        // available — and then the per-SERVER rate limit + block coordination protect that
        // IP. crawler.use_proxies=false forces direct-only (e.g. if the pool is unhealthy).
        $useProxies = (bool) config('crawler.use_proxies', true);
        $proxy = ($useProxies && $this->pool->available()) ? $this->pool->pick() : null;
        $t0 = microtime(true);
        $res = $this->fetcher->fetch($page->url, $conditional, $timeout, $proxy);
        // Feed the smart rate policy: rising latency ⇒ it backs the domain off before a block.
        $this->rateLimiter->recordFetch($domain, (int) round((microtime(true) - $t0) * 1000));

        $blocked = $res['ok'] ? $this->blockDetector->classify([
            'status' => (int) $res['status'], 'body' => $res['body'], 'headers' => $res['headers'],
        ]) : null;

        if ($blocked === null) {
            if ($proxy !== null && $res['ok']) {
                $this->pool->markSuccess($proxy);
            }

            return $res;
        }

        // A block here means this DOMAIN is banning THIS server — record it fleet-wide so
        // the others go cautious (and, once every box is blocked, switch to proxies). Per
        // domain, never global.
        $this->rateLimiter->recordBlock($domain);

        // (Legacy) direct-then-proxy retry — only relevant if proxies are forced on but we
        // somehow went direct; with the all-blocked logic above the first attempt already
        // uses a proxy, so this is a harmless fallback.
        if ($useProxies && $proxy === null && $this->pool->available()) {
            $retryProxy = $this->pool->pick();
            if ($retryProxy !== null) {
                $res2 = $this->fetcher->fetch($page->url, $conditional, $timeout, $retryProxy);
                $blocked2 = $res2['ok'] ? $this->blockDetector->classify([
                    'status' => (int) $res2['status'], 'body' => $res2['body'], 'headers' => $res2['headers'],
                ]) : null;
                if ($blocked2 === null && $res2['ok']) {
                    $this->pool->markSuccess($retryProxy);

                    return $res2;
                }
                $this->pool->markFailure($retryProxy);
            }
        } elseif ($proxy !== null) {
            $this->pool->markFailure($proxy);
        }

        // Still blocked after the retry — return as-is. The run-level rollup in
        // AnalyzeSiteJob decides whether the SITE is blocking us (and owns the
        // crawl_protection flag), so a single blocked page never trips the banner.
        return $res;
    }

    /**
     * @param  array<int,array{href:string,anchor?:string}>  $internalLinks
     */
    private function rebuildEdges(WebsitePage $page, array $internalLinks): void
    {
        $now = now();
        $crawlSiteId = $page->crawl_site_id;

        // Map of target url_hash => url (deduped, capped).
        $targets = [];
        foreach ($internalLinks as $link) {
            $href = (string) ($link['href'] ?? '');
            if ($href === '' || ! preg_match('#^https?://#i', $href)) {
                continue;
            }
            $href = \App\Support\Crawler\FrontierUrl::collapse($href);
            $hash = WebsitePage::hashUrl($href);
            if ($hash === $page->url_hash || isset($targets[$hash])) {
                continue; // skip self-links + dupes
            }
            $targets[$hash] = ['url' => $href, 'anchor' => mb_substr((string) ($link['anchor'] ?? ''), 0, 512)];
            if (count($targets) >= self::MAX_EDGES_PER_PAGE) {
                break;
            }
        }

        // Always clear this page's previously-discovered outbound edges.
        WebsiteInternalLink::where('from_page_id', $page->id)
            ->where('status', WebsiteInternalLink::STATUS_DISCOVERED)
            ->delete();

        if ($targets === []) {
            return;
        }

        // Ensure a (possibly stub) website_pages row exists for each target.
        $stubRows = [];
        foreach ($targets as $hash => $t) {
            $stubRows[] = [
                'crawl_site_id' => $crawlSiteId,
                'url' => mb_substr($t['url'], 0, 2048),
                'url_hash' => $hash,
                'discovered_at' => $now,
                'next_crawl_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('website_pages')->insertOrIgnore(ulid_rows($stubRows));

        $idByHash = WebsitePage::where('crawl_site_id', $crawlSiteId)
            ->whereIn('url_hash', array_keys($targets))
            ->pluck('id', 'url_hash');

        $edges = [];
        foreach ($targets as $hash => $t) {
            $toId = $idByHash[$hash] ?? null;
            if ($toId === null) {
                continue;
            }
            $edges[] = [
                'crawl_site_id' => $crawlSiteId,
                'from_page_id' => $page->id,
                'to_page_id' => $toId,
                'anchor_text' => $t['anchor'] !== '' ? $t['anchor'] : null,
                'status' => WebsiteInternalLink::STATUS_DISCOVERED,
                'discovered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($edges, 500) as $chunk) {
            DB::table('website_internal_links')->insert(ulid_rows($chunk));
        }
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return array<string,mixed>
     */
    private function seoSignals(array $analysis, array $res = []): array
    {
        $schemaTypes = [];
        $invalidSchemaCount = 0;
        foreach (($analysis['schema']['blocks'] ?? []) as $b) {
            if (! empty($b['type'])) {
                $schemaTypes[] = (string) $b['type'];
            }
            if (($b['valid'] ?? true) === false) {
                $invalidSchemaCount++;
            }
        }

        $external = [];
        foreach (array_slice($analysis['external_links'] ?? [], 0, self::MAX_EXTERNAL_SAMPLE) as $l) {
            $external[] = ['href' => (string) ($l['href'] ?? ''), 'anchor' => mb_substr((string) ($l['anchor'] ?? ''), 0, 200)];
        }

        return [
            'h1_count' => $analysis['h1_count'] ?? 0,
            'heading_order_ok' => $analysis['heading_order_ok'] ?? true,
            'image_count' => $analysis['images']['total'] ?? 0,
            'missing_alt_count' => $analysis['images']['missing_alt_count'] ?? 0,
            'schema_types' => array_values(array_unique($schemaTypes)),
            'invalid_schema_count' => $invalidSchemaCount,
            'og_tag_count' => $analysis['og_tag_count'] ?? 0,
            'twitter_tag_count' => $analysis['twitter_tag_count'] ?? 0,
            'canonical_points_away' => $analysis['canonical_points_away'] ?? false,
            'external_links' => $external,
            'hreflang_count' => count($analysis['hreflangs'] ?? []),
            'hreflang_self_ref' => $analysis['hreflang_self_ref'] ?? false,
            'hreflangs' => array_slice($analysis['hreflangs'] ?? [], 0, self::MAX_EXTERNAL_SAMPLE),
            // ttfb_ms is measured after the full body downloads (CrawlFetcher::fetch()),
            // so it's really total fetch latency, not true time-to-first-byte — kept
            // under this name to match the fetcher's existing field.
            'ttfb_ms' => (int) ($res['ttfb_ms'] ?? 0),
            'redirect_chain' => (int) ($res['redirect_chain'] ?? 0),
            'mixed_content_count' => count($analysis['mixed_content_urls'] ?? []),
            'mixed_content_urls' => array_slice($analysis['mixed_content_urls'] ?? [], 0, self::MAX_EXTERNAL_SAMPLE),
        ];
    }

    private function scheduleNext(WebsitePage $page, int $days): \Illuminate\Support\Carbon
    {
        // Deterministic per-page jitter (0–23h) spreads load without randomness.
        // ULID ids aren't numeric, so derive the jitter from a stable hash.
        return now()->addDays($days)->addHours(crc32((string) $page->id) % 24);
    }
}
