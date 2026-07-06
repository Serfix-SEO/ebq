<?php

namespace App\Services\Crawler;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\SearchConsoleData;
use App\Models\WebsitePage;
use App\Support\Crawler\RobotsTxtParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Produces the unified SEO issue catalog (crawl_findings) from the persisted
 * crawl inventory + link graph. Idempotent: findings are upserted by
 * (website_id, type, affected_url_hash); the caller resolves stale ones.
 */
class SiteIssueDetector
{
    /** Referrer samples attached to a broken target so the user can find the link. */
    private const REFERRER_SAMPLE_CAP = 5;

    /** Hard bound on referrer-edge rows scanned (pathological inbound fan-in). */
    private const REFERRER_SCAN_CAP = 50000;

    /** Click-to-chat/shortlink services that always redirect by design — never an
     *  external_redirect finding, regardless of how many hops the chain has. */
    private const KNOWN_REDIRECTOR_HOSTS = ['wa.me', 'api.whatsapp.com', 't.me', 'm.me', 'bit.ly'];

    /** Social hosts whose WAF/anti-bot layer routinely 403s non-browser UAs (incl. our
     *  Googlebot-spoofed crawler) even for perfectly live profile/page URLs. A 401/403/429/999
     *  here is not trustworthy evidence of a real broken link, so we don't report it. Confirmed
     *  2026-06-25: x.com/WesleyLawn38331 (live profile) flagged broken_external on 403. */
    private const KNOWN_ANTIBOT_HOSTS = ['x.com', 'twitter.com', 'linkedin.com', 'facebook.com', 'instagram.com'];

    /** Statuses these anti-bot hosts return to block scrapers, not to signal a dead link. */
    private const ANTIBOT_BLOCK_STATUSES = [401, 403, 429, 999];

    /** @var array<int,array<string,mixed>> rows pending upsert, keyed by type|hash */
    private array $pending = [];

    /** @var array<string,int> url_hash => 28d GSC clicks */
    private array $clicks = [];

    /**
     * Provenance for every 4xx/5xx target: how many internal pages link to it and
     * a sample of those referrers (url + anchor). Lets broken_page/broken_internal
     * findings answer "where is this linked from?" instead of just "it's 404".
     *
     * @var array<string,array{count:int,samples:array<int,array{url:string,anchor:?string}>}>
     */
    private array $referrers = [];

    public function __construct(
        private readonly LinkChecker $linkChecker,
        private readonly CrawlFetcher $fetcher,
    ) {}

    public function detect(CrawlSite $website, CrawlRun $run): int
    {
        // Capture before any row() calls so stale resolution (last_seen_at < $detectStart)
        // only resolves findings NOT touched this run. If captured after detection, every
        // freshly-built row (last_seen_at = now() during detection) would satisfy
        // last_seen_at < $detectStart and be immediately resolved → 0 findings shown.
        $detectStart = now();

        $this->pending = [];
        $this->loadClicks($website);
        $this->loadBrokenReferrers($website);

        $deepThreshold = (int) config('crawler.deep_page_threshold', 3);
        $importantClicks = (int) config('crawler.important_clicks', 1);
        $homepageHash = WebsitePage::hashUrl($website->homepageUrl());

        // Map of page_id => [http_status, url, clicks] for broken-internal lookups.
        $statusByHash = [];

        WebsitePage::where('crawl_site_id', $website->id)
            ->whereNull('removed_at')
            ->chunkById(500, function ($pages) use ($website, $run, $deepThreshold, $importantClicks, $homepageHash, &$statusByHash): void {
                foreach ($pages as $page) {
                    $statusByHash[$page->url_hash] = ['status' => $page->http_status, 'url' => $page->url, 'id' => $page->id];
                    // Pages not yet crawled (stubs) carry no signal.
                    if ($page->last_crawled_at === null) {
                        continue;
                    }
                    $this->detectForPage($website, $run, $page, $deepThreshold, $importantClicks, $homepageHash);
                }
            });

        $this->detectBrokenInternalLinks($website, $run, $statusByHash);
        $this->detectBrokenExternalLinks($website, $run);
        $this->detectDuplicateField($website, $importantClicks, 'title', 'duplicate_title');
        $this->detectDuplicateField($website, $importantClicks, 'meta_description', 'duplicate_meta_description');
        $this->detectDuplicateContent($website, $importantClicks);
        $this->detectRobotsBlocked($website);

        $count = $this->flush($website->id, $run->id);

        // Any finding not refreshed in this run is stale (issue no longer exists).
        // Mark it resolved so it disappears from the open-findings UI.
        DB::table('crawl_findings')
            ->where('crawl_site_id', $website->id)
            ->where('status', 'open')
            ->where('last_seen_at', '<', $detectStart)
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

        return $count;
    }

    /**
     * Pages robots.txt blocks for our crawler's UA, restricted to pages the SITE
     * ITSELF treats as real (sitemap-listed or internally linked) — not gated on
     * GSC traffic like noindex_important, because plenty of subscribers never
     * connect GSC and would get zero coverage from this check otherwise. Sitemap/
     * internal-link presence is the crawl-only proxy for "this isn't an
     * intentionally-excluded utility path like /admin or /cart" (those are
     * normally neither). Severity bumps to high when traffic data IS available.
     * One robots.txt fetch per run, not per page — added 2026-06-23, full
     * Semrush-catalog gap sweep.
     */
    private function detectRobotsBlocked(CrawlSite $website): void
    {
        $robotsTxt = $this->fetchRobotsTxt($website);
        if ($robotsTxt === null) {
            return;
        }

        WebsitePage::where('crawl_site_id', $website->id)
            ->whereNull('removed_at')
            ->where('http_status', 200)
            ->where(fn ($q) => $q->where('source_sitemap', true)->orWhere('inbound_link_count', '>', 0))
            ->select('id', 'url', 'url_hash', 'crawl_site_id')
            ->chunkById(500, function ($pages) use ($robotsTxt): void {
                foreach ($pages as $page) {
                    $path = (string) (parse_url($page->url, PHP_URL_PATH) ?: '/');
                    if (RobotsTxtParser::isBlocked($robotsTxt, $path)) {
                        $clicks = $this->clicks[$page->url_hash] ?? 0;
                        $this->add($page, CrawlFinding::CATEGORY_CRAWLABILITY, 'robots_blocked_important', $clicks > 0 ? 'high' : 'medium', $clicks, [
                            'path' => $path,
                        ]);
                    }
                }
            });
    }

    /** Fetches robots.txt from the site's homepage origin. Null on any failure
     *  (missing/error/non-200) — no robots.txt means nothing is blocked by one. */
    private function fetchRobotsTxt(CrawlSite $website): ?string
    {
        $home = parse_url($website->homepageUrl());
        if (empty($home['scheme']) || empty($home['host'])) {
            return null;
        }

        $res = $this->fetcher->fetch($home['scheme'].'://'.$home['host'].'/robots.txt', [], 10);

        return ($res['ok'] && (int) $res['status'] === 200) ? (string) $res['body'] : null;
    }

    /** Same title/meta-description reused across 2+ indexable pages — a real gap vs
     *  Semrush et al, this catalog had missing/too-long/too-short checks but never
     *  cross-page duplicates (e.g. an i18n site whose /ar//en//fr/ variants never got
     *  translated copy). Added 2026-06-23 (title), extended to meta_description same day. */
    private function detectDuplicateField(CrawlSite $website, int $importantClicks, string $column, string $type): void
    {
        $groups = WebsitePage::where('crawl_site_id', $website->id)
            ->whereNull('removed_at')
            ->where('http_status', 200)
            ->where('is_indexable', true)
            ->whereNull('redirect_target')
            ->where($column, '!=', '')
            ->whereNotNull($column)
            ->select($column, 'id', 'url', 'url_hash', 'crawl_site_id', 'canonical_url')
            ->get()
            ->groupBy($column)
            ->filter(fn ($pages) => $pages->count() > 1);

        foreach ($groups as $value => $pages) {
            // All pages share the same canonical → duplication is intentional, not a bug.
            $canonicals = $pages->pluck('canonical_url')->filter()->unique();
            if ($canonicals->count() === 1) {
                continue;
            }

            foreach ($pages as $page) {
                $clicks = $this->clicks[$page->url_hash] ?? 0;
                $others = $pages->reject(fn ($p) => $p->id === $page->id)->pluck('url')->values()->all();
                $severity = $clicks >= max(1, $importantClicks) ? 'high' : 'medium';
                $this->add($page, CrawlFinding::CATEGORY_ONPAGE, $type, $severity, $clicks, [
                    $column => $value,
                    'duplicate_count' => $pages->count(),
                    'other_urls' => array_slice($others, 0, self::REFERRER_SAMPLE_CAP),
                ]);
            }
        }
    }

    /** Full-page exact-duplicate content (not just title/meta) — `content_hash` (sha1 of
     *  the analyzed body_text) already exists per-page for change detection but nothing
     *  grouped it ACROSS pages. `word_count > 0` excludes pages whose body_text came back
     *  empty (a fetch/parse edge case, not real duplicate content) — sha1('') would
     *  otherwise group every such page together as a false "duplicate". Added 2026-06-23,
     *  full Semrush-catalog gap sweep. */
    private function detectDuplicateContent(CrawlSite $website, int $importantClicks): void
    {
        $groups = WebsitePage::where('crawl_site_id', $website->id)
            ->whereNull('removed_at')
            ->where('http_status', 200)
            ->where('is_indexable', true)
            ->whereNull('redirect_target')
            ->where('word_count', '>', 0)
            ->whereNotNull('content_hash')
            ->select('content_hash', 'id', 'url', 'url_hash', 'crawl_site_id', 'canonical_url')
            ->get()
            ->groupBy('content_hash')
            ->filter(fn ($pages) => $pages->count() > 1);

        foreach ($groups as $pages) {
            // All pages share the same canonical → intentional deduplication, not a bug.
            $canonicals = $pages->pluck('canonical_url')->filter()->unique();
            if ($canonicals->count() === 1) {
                continue;
            }

            foreach ($pages as $page) {
                $clicks = $this->clicks[$page->url_hash] ?? 0;
                $others = $pages->reject(fn ($p) => $p->id === $page->id)->pluck('url')->values()->all();
                $severity = $clicks >= max(1, $importantClicks) ? 'high' : 'medium';
                $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'duplicate_content', $severity, $clicks, [
                    'duplicate_count' => $pages->count(),
                    'other_urls' => array_slice($others, 0, self::REFERRER_SAMPLE_CAP),
                ]);
            }
        }
    }

    private function detectForPage(CrawlSite $website, CrawlRun $run, WebsitePage $page, int $deepThreshold, int $importantClicks, string $homepageHash): void
    {
        $clicks = $this->clicks[$page->url_hash] ?? 0;
        $status = (int) $page->http_status;
        $signals = $page->seo_signals ?? [];
        $sev = fn (string $withClicks, string $without) => $clicks >= max(1, $importantClicks) ? $withClicks : $without;

        // Broken page (URL known to GSC/sitemap/internal links returns 4xx).
        // Excluded: 5xx (transient server errors — rate-limit, bot-protection, temp
        // outage) and 429 (Too Many Requests — we hit the site too fast; page exists).
        // Only hard client-error codes (404, 410, 400, 403, etc.) mean the page is
        // genuinely gone or forbidden. Carry discovery provenance so the fix surface
        // can say where it came from (which internal pages link to it, the sitemap,
        // or Search Console history) instead of leaving the user with a bare 404 and
        // nowhere to look.
        if ($status >= 400 && $status < 500 && $status !== 429) {
            $ref = $this->referrers[$page->url_hash] ?? null;
            $detail = ['http_status' => $status, 'inbound_internal' => $ref['count'] ?? 0];
            if ($page->source_sitemap) {
                $detail['source_sitemap'] = true;
            }
            if ($page->source_gsc) {
                $detail['source_gsc'] = true;
            }
            if ($page->discovered_at) {
                $detail['discovered_at'] = $page->discovered_at->toIso8601String();
            }
            if (($ref['samples'] ?? []) !== []) {
                $detail['referrers'] = $ref['samples'];
            }
            $this->add($page, CrawlFinding::CATEGORY_BROKEN_LINK, 'broken_page', $sev('critical', 'high'), $clicks, $detail);
            // The sitemap is a direct signal to search engines "crawl/index this" —
            // listing a 4xx/5xx URL in it is its own distinct, actionable problem
            // (regenerate/prune the sitemap) independent of fixing the dead page
            // itself. Click-independent: a sitemap shouldn't lie regardless of
            // traffic. Added 2026-06-23, full Semrush-catalog gap sweep.
            if ($page->source_sitemap) {
                $this->add($page, CrawlFinding::CATEGORY_SITEMAP, 'sitemap_broken_url', $sev('high', 'medium'), $clicks, ['http_status' => $status]);
            }

            return; // no point checking on-page signals for a dead page
        }

        // Redirecting URL in the frontier.
        if ($page->redirect_target) {
            $this->add($page, CrawlFinding::CATEGORY_REDIRECT, 'redirecting_url', $sev('medium', 'low'), $clicks, ['redirect_target' => $page->redirect_target]);
            if ($page->source_sitemap) {
                $this->add($page, CrawlFinding::CATEGORY_SITEMAP, 'sitemap_redirect_url', $sev('medium', 'low'), $clicks, ['redirect_target' => $page->redirect_target]);
            }
            // 3+ hops before landing — wastes crawl budget and stacks latency.
            // Guzzle's redirect-history count, threaded through unused since the
            // fetcher already computed it. Added 2026-06-23.
            if ((int) ($signals['redirect_chain'] ?? 0) >= 3) {
                $this->add($page, CrawlFinding::CATEGORY_REDIRECT, 'redirect_chain_too_long', 'low', $clicks, [
                    'hops' => (int) $signals['redirect_chain'],
                    'redirect_target' => $page->redirect_target,
                ]);
            }
        }

        // Slow fetch — $signals['ttfb_ms'] is really total fetch latency (measured
        // after the full body downloads, not after headers), so this catches a slow
        // server/page overall rather than strict TTFB. Threshold picked generously
        // (5s) to avoid flagging normal network jitter.
        if ((int) ($signals['ttfb_ms'] ?? 0) >= 5000) {
            $this->add($page, CrawlFinding::CATEGORY_PERFORMANCE, 'slow_response', $sev('medium', 'low'), $clicks, [
                'ttfb_ms' => (int) $signals['ttfb_ms'],
            ]);
        }

        $indexable = (bool) $page->is_indexable && $status === 200 && ! $page->redirect_target;

        // Sitemap listing a non-indexable URL (noindex meta/header OR canonical
        // pointing away) is a contradiction — the sitemap says "index this," the
        // page itself says "don't." Click-independent like the other sitemap-
        // quality checks above. Not gated on the more specific noindex_important
        // reason text below since canonical-points-away also makes a page
        // non-indexable and is just as wrong to list in a sitemap.
        if ($status === 200 && ! $page->redirect_target && ! $page->is_indexable && $page->source_sitemap) {
            $this->add($page, CrawlFinding::CATEGORY_SITEMAP, 'sitemap_noindex_url', $sev('medium', 'low'), $clicks);
        }

        // Indexability. Crawl-only "this page is structurally real" proxy — same
        // one used for robots_blocked_important/sitemap checks above — so these
        // fire for every subscriber, GSC-connected or not. GSC clicks (when
        // present) only bump severity; they're never required for the finding to
        // exist. Re-done 2026-06-23: both were previously gated on GSC clicks for
        // EXISTENCE, not just severity — the crawler's own findings must stand on
        // crawl data alone (see [[crawl-only-over-gsc-gating]]).
        $isStructurallyReal = (bool) $page->source_sitemap || (int) $page->inbound_link_count > 0 || $page->url_hash === $homepageHash;
        if ($status === 200 && ! $page->is_indexable && ! $page->redirect_target && str_contains((string) $page->robots_directives, 'noindex') && $isStructurallyReal) {
            $this->add($page, CrawlFinding::CATEGORY_INDEXABILITY, 'noindex_important', $sev('high', 'medium'), $clicks, ['robots' => $page->robots_directives]);
        }
        // A page canonicalizing to a different URL is usually intentional dedup
        // (e.g. ?name=… variants → the clean page) and not an issue — those
        // variants are typically neither sitemap-listed nor internally linked, so
        // $isStructurallyReal already filters them out without needing GSC.
        if (($signals['canonical_points_away'] ?? false) === true && $isStructurallyReal) {
            $this->add($page, CrawlFinding::CATEGORY_INDEXABILITY, 'canonical_mismatch', $sev('high', 'medium'), $clicks, ['canonical' => $page->canonical_url]);
        }

        // International (hreflang). Checked before the indexable-only gate below
        // because a locale variant with a self-canonicalizing-elsewhere conflict is
        // (by definition) non-indexable — that's the bug, not a reason to skip it.
        if ((int) ($signals['hreflang_count'] ?? 0) > 0) {
            if (($signals['hreflang_self_ref'] ?? false) === false) {
                $this->add($page, CrawlFinding::CATEGORY_INDEXABILITY, 'missing_self_hreflang', 'medium', $clicks, [
                    'hreflangs' => $signals['hreflangs'] ?? [],
                ]);
            }
            if (($signals['canonical_points_away'] ?? false) === true) {
                $this->add($page, CrawlFinding::CATEGORY_INDEXABILITY, 'hreflang_canonical_conflict', 'medium', $clicks, [
                    'canonical' => $page->canonical_url,
                    'hreflangs' => $signals['hreflangs'] ?? [],
                ]);
            }
        }

        if (! $indexable) {
            return; // remaining checks only meaningful for live, indexable pages
        }

        // seo_signals=null means HTML parser never ran (non-HTML response, first-crawl
        // bot-block, or pre-WAF-detection crawl). Skip all onpage checks — no signal to analyse.
        if ($page->seo_signals === null) {
            return;
        }

        // On-page SEO.
        $title = (string) $page->title;
        $titleLen = mb_strlen($title);
        if ($title === '') {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_title', $sev('high', 'medium'), $clicks);
        } elseif ($titleLen > 60) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'title_too_long', 'low', $clicks, ['length' => $titleLen]);
        } elseif ($titleLen < 15) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'title_too_short', 'low', $clicks, ['length' => $titleLen]);
        }

        $metaLen = mb_strlen((string) $page->meta_description);
        if ($metaLen === 0) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_meta_description', $sev('medium', 'low'), $clicks);
        } elseif ($metaLen > 160) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'meta_description_too_long', 'low', $clicks, ['length' => $metaLen]);
        }

        $h1 = (int) ($signals['h1_count'] ?? 0);
        if ($h1 === 0) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_h1', $sev('medium', 'low'), $clicks);
        } elseif ($h1 > 1) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'multiple_h1', 'low', $clicks, ['count' => $h1]);
        }
        if (($signals['heading_order_ok'] ?? true) === false) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'broken_heading_order', 'low', $clicks);
        }

        if ((int) $page->word_count > 0 && (int) $page->word_count < 200) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'thin_content', $sev('medium', 'low'), $clicks, ['word_count' => (int) $page->word_count]);
        }

        if ((int) ($signals['missing_alt_count'] ?? 0) > 0) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_image_alt', 'low', $clicks, ['count' => (int) $signals['missing_alt_count']]);
        }
        if ((int) ($signals['og_tag_count'] ?? 0) === 0) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_open_graph', 'low', $clicks);
        }
        if ((int) ($signals['twitter_tag_count'] ?? 0) === 0) {
            $this->add($page, CrawlFinding::CATEGORY_ONPAGE, 'missing_twitter_card', 'low', $clicks);
        }
        if ((int) ($signals['mixed_content_count'] ?? 0) > 0) {
            $this->add($page, CrawlFinding::CATEGORY_SECURITY, 'mixed_content', $sev('high', 'medium'), $clicks, [
                'count' => (int) $signals['mixed_content_count'],
                'urls' => $signals['mixed_content_urls'] ?? [],
            ]);
        }

        // Schema. "Missing" and "invalid" are mutually exclusive on purpose: a page
        // with one malformed JSON-LD block and zero valid ones already gets
        // missing_structured_data (schema_types is empty); invalid_structured_data
        // only fires when the page has SOME valid schema alongside a broken block,
        // which missing_structured_data would otherwise silently swallow.
        if (($signals['schema_types'] ?? []) === []) {
            $this->add($page, CrawlFinding::CATEGORY_SCHEMA, 'missing_structured_data', 'low', $clicks);
        } elseif ((int) ($signals['invalid_schema_count'] ?? 0) > 0) {
            $this->add($page, CrawlFinding::CATEGORY_SCHEMA, 'invalid_structured_data', 'low', $clicks, [
                'invalid_count' => (int) $signals['invalid_schema_count'],
            ]);
        }

        // Internal-link structure. Skip the homepage and skip query-string URLs
        // (e.g. ?name=…/?nick=… parameter variants): a specific parameter combo
        // having no inbound internal links is expected, not an actionable orphan,
        // and would otherwise flood the report with noise.
        $hasQuery = parse_url($page->url, PHP_URL_QUERY) !== null;
        if ($page->url_hash !== $homepageHash && ! $hasQuery) {
            // Orphan = no inbound internal links AND not listed in the sitemap.
            // Sitemap-listed pages are discoverable by search engines regardless of
            // internal links, so flagging them floods the report (tens of thousands
            // on large programmatic sites) for little actionable value.
            if ((int) $page->inbound_link_count === 0 && ! $page->source_sitemap) {
                $this->add($page, CrawlFinding::CATEGORY_INTERNAL_LINKS, 'orphan_page', $sev('high', 'medium'), $clicks);
            }
            if ($page->click_depth !== null && (int) $page->click_depth >= $deepThreshold) {
                $this->add($page, CrawlFinding::CATEGORY_INTERNAL_LINKS, 'deep_page', $sev('medium', 'low'), $clicks, ['click_depth' => (int) $page->click_depth]);
            }
        }

        // Sitemap hygiene: GSC-trafficked + indexable but absent from sitemap.
        if ($page->source_gsc && ! $page->source_sitemap) {
            $this->add($page, CrawlFinding::CATEGORY_SITEMAP, 'indexed_not_in_sitemap', 'low', $clicks);
        }
    }

    /**
     * Internal links pointing at pages that return 4xx/5xx — the on-site broken
     * links that hurt crawl + UX. Derived from the inventory (every internal
     * target is itself crawled), so no extra network calls.
     *
     * @param  array<string,array{status:?int,url:string,id:int}>  $statusByHash
     */
    private function detectBrokenInternalLinks(CrawlSite $website, CrawlRun $run, array $statusByHash): void
    {
        DB::table('website_internal_links as l')
            ->join('website_pages as t', 't.id', '=', 'l.to_page_id')
            ->where('l.crawl_site_id', $website->id)
            ->where('l.status', 'discovered')
            ->where('t.http_status', '>=', 400)
            ->where('t.http_status', '<', 500)
            ->where('t.http_status', '!=', 429)
            ->select('t.id', 't.url', 't.url_hash', 't.http_status', DB::raw('COUNT(*) as inbound'))
            ->groupBy('t.id', 't.url', 't.url_hash', 't.http_status')
            ->orderByDesc('inbound')
            ->chunk(500, function ($rows) use ($website, $run): void {
                foreach ($rows as $r) {
                    $key = 'broken_internal|'.$r->url_hash;
                    $detail = ['http_status' => (int) $r->http_status, 'inbound_links' => (int) $r->inbound];
                    $samples = $this->referrers[$r->url_hash]['samples'] ?? [];
                    if ($samples !== []) {
                        $detail['referrers'] = $samples;
                    }
                    $this->pending[$key] = $this->row(
                        $website->id, $run->id, (string) $r->id,
                        CrawlFinding::CATEGORY_BROKEN_LINK, 'broken_internal',
                        ($this->clicks[$r->url_hash] ?? 0) > 0 ? 'critical' : 'high',
                        0.0, // per-user impact computed read-time
                        $r->url,
                        $detail,
                    );
                }
            });
    }

    /**
     * Bounded broken-external-link pass: check the sampled external links stored
     * on each page (capped per site) and flag 4xx/5xx + redirects.
     */
    private function detectBrokenExternalLinks(CrawlSite $website, CrawlRun $run): void
    {
        $cap = (int) config('crawler.max_external_checks', 500);
        if ($cap <= 0) {
            return;
        }

        $links = [];
        WebsitePage::where('crawl_site_id', $website->id)
            ->whereNull('removed_at')
            ->whereNotNull('seo_signals')
            ->select('id', 'seo_signals')
            ->chunkById(500, function ($pages) use (&$links, $cap): void {
                foreach ($pages as $page) {
                    foreach (($page->seo_signals['external_links'] ?? []) as $l) {
                        $href = (string) ($l['href'] ?? '');
                        if ($href === '' || isset($links[$href])) {
                            continue;
                        }
                        $links[$href] = ['href' => $href, 'anchor' => (string) ($l['anchor'] ?? ''), 'from' => (string) $page->id];
                    }
                }
            });

        if ($links === []) {
            return;
        }

        $sample = array_slice(array_values($links), 0, $cap);
        $problems = $this->linkChecker->check($sample, $cap);

        foreach ($problems as $p) {
            $href = (string) $p['href'];
            $fromId = $links[$href]['from'] ?? null;
            // Click-to-chat/shortlink services 302 by design (e.g. wa.me -> api.whatsapp.com)
            // — not a site issue, not actionable by the owner. Confirmed 2026-06-23 on a
            // real site: every wa.me "Order on WhatsApp" link was flagged as external_redirect.
            $isKnownRedirector = $this->isKnownRedirector($href) || $this->isKnownAntibotHost($href);
            if (! empty($p['redirected']) && ! $isKnownRedirector) {
                $this->pending['ext_redirect|'.CrawlFinding::hashUrl($href)] = $this->row(
                    $website->id, $run->id, $fromId,
                    CrawlFinding::CATEGORY_REDIRECT, 'external_redirect', 'low', 0.0, $href,
                    ['final_url' => $p['final_url'], 'chain' => $p['chain'], 'anchor' => $p['anchor']],
                );
            }
            $status = $p['status'];
            // Known social/antibot hosts always block bots — skip regardless of status.
            // Their share/profile URLs are never dead links, any response is untrustworthy.
            $isUntrustedAntibotBlock = $this->isKnownAntibotHost($href);
            // 403 from any external host almost always means WAF/bot-blocking, not a dead link.
            $isAccessBlocked = $status === 403;
            if ($isUntrustedAntibotBlock || $isAccessBlocked) {
                Log::info('crawler.broken_external.antibot_skip', [
                    'website_id' => $website->id, 'run_id' => $run->id, 'href' => $href, 'status' => $status,
                ]);
            }
            if (($status === null || $status >= 400) && ! $isUntrustedAntibotBlock && ! $isAccessBlocked) {
                $this->pending['broken_external|'.CrawlFinding::hashUrl($href)] = $this->row(
                    $website->id, $run->id, $fromId,
                    CrawlFinding::CATEGORY_BROKEN_LINK, 'broken_external', 'medium', 0.0, $href,
                    ['http_status' => $status, 'error' => $p['error'], 'anchor' => $p['anchor']],
                );
            }
        }
    }

    private function isKnownRedirector(string $href): bool
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        foreach (self::KNOWN_REDIRECTOR_HOSTS as $d) {
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownAntibotHost(string $href): bool
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        foreach (self::KNOWN_ANTIBOT_HOSTS as $d) {
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    private function add(WebsitePage $page, string $category, string $type, string $severity, int $clicks, array $detail = []): void
    {
        // Stored impact is 0: the shared finding must not carry any user's (or the
        // aggregate's) click count. Per-user impact is computed read-time in
        // CrawlReportService from each website's own SearchConsoleData.
        $this->pending[$type.'|'.$page->url_hash] = $this->row(
            $page->crawl_site_id, 0, $page->id, $category, $type, $severity, 0.0, $page->url, $detail,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $crawlSiteId, string $runId, ?string $pageId, string $category, string $type, string $severity, float $impact, string $url, array $detail): array
    {
        $now = now();

        return [
            'crawl_site_id' => $crawlSiteId,
            'page_id' => $pageId ?: null,
            'crawl_run_id' => $runId ?: null,
            'category' => $category,
            'type' => $type,
            'severity' => $severity,
            'impact' => $impact,
            'affected_url' => mb_substr($url, 0, 2048),
            'affected_url_hash' => CrawlFinding::hashUrl($url),
            'detail' => $detail === [] ? null : json_encode($detail),
            'status' => CrawlFinding::STATUS_OPEN,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(string $crawlSiteId, string $runId): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $rows = array_map(function (array $r) use ($runId) {
            $r['crawl_run_id'] = $r['crawl_run_id'] ?: $runId;

            return $r;
        }, array_values($this->pending));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('crawl_findings')->upsert(ulid_rows($chunk),
                ['crawl_site_id', 'type', 'affected_url_hash'],
                ['page_id', 'crawl_run_id', 'category', 'severity', 'impact', 'affected_url', 'detail', 'status', 'last_seen_at', 'resolved_at', 'updated_at'],
            );
        }

        return count($rows);
    }

    private function loadClicks(CrawlSite $website): void
    {
        $this->clicks = [];
        $since = Carbon::now()->subDays(28)->toDateString();

        // Aggregate clicks across ALL subscribers so a click-conditional finding
        // (e.g. noindex_important) exists in the shared catalog if ANY subscriber
        // has traffic. Per-user impact + severity are recomputed read-time.
        $subscriberIds = $website->websites()->pluck('id')->all();
        if ($subscriberIds === []) {
            return;
        }

        SearchConsoleData::query()
            ->whereIn('website_id', $subscriberIds)
            ->whereDate('date', '>=', $since)
            ->where('page', '!=', '')
            ->select('page', DB::raw('SUM(clicks) as c'))
            ->groupBy('page')
            ->chunk(2000, function ($rows): void {
                foreach ($rows as $r) {
                    $this->clicks[WebsitePage::hashUrl((string) $r->page)] = (int) $r->c;
                }
            });
    }

    /**
     * Build $this->referrers: for every internal-link edge whose target returns
     * 4xx/5xx, accumulate an inbound count + a capped sample of the source pages
     * (url + anchor) keyed by the target's url_hash. Streamed via cursor() and
     * globally bounded so a single 404 with huge fan-in can't blow up memory.
     */
    private function loadBrokenReferrers(CrawlSite $website): void
    {
        $this->referrers = [];
        $scanned = 0;

        foreach (
            DB::table('website_internal_links as l')
                ->join('website_pages as t', 't.id', '=', 'l.to_page_id')
                ->join('website_pages as f', 'f.id', '=', 'l.from_page_id')
                ->where('l.crawl_site_id', $website->id)
                ->where('l.status', 'discovered')
                ->where('t.http_status', '>=', 400)
                ->whereNull('f.removed_at')
                ->orderBy('l.id')
                ->select('t.url_hash as target_hash', 'f.url as from_url', 'l.anchor_text as anchor')
                ->cursor() as $r
        ) {
            $hash = (string) $r->target_hash;
            if (! isset($this->referrers[$hash])) {
                $this->referrers[$hash] = ['count' => 0, 'samples' => []];
            }
            $this->referrers[$hash]['count']++;
            if (count($this->referrers[$hash]['samples']) < self::REFERRER_SAMPLE_CAP) {
                $this->referrers[$hash]['samples'][] = [
                    'url' => (string) $r->from_url,
                    'anchor' => $r->anchor !== null ? (string) $r->anchor : null,
                ];
            }
            if (++$scanned >= self::REFERRER_SCAN_CAP) {
                break;
            }
        }
    }
}
