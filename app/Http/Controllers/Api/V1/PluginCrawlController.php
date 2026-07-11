<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CrawlFinding;
use App\Models\Website;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site Audit (crawler report) API for the WordPress plugin — the first
 * token-authorized JSON surface over the shared-crawl subsystem (before
 * this, crawl data reached the plugin only as signed Livewire iframe
 * embeds). Read-only: every method delegates to CrawlReportService,
 * whose heavy aggregates (actionGroups/typeBreakdown/typeCounts) are
 * already 24h version-keyed cached and warmed post-crawl.
 *
 * Tenancy: the bearer token's Website only (api_website attribute set by
 * WebsiteApiAuth) — no request parameter is ever trusted for scoping.
 * All ids are ULID strings; never int-cast, never sprintf %d.
 *
 * Gating: the `hq` feature flag (Site Audit is an HQ section). A frozen
 * website gets 403 like the rest of the plugin's tier-gated paths.
 * Works without GSC: impact fields simply degrade to 0 on crawl-only
 * sites — never gate existence on GSC.
 */
class PluginCrawlController extends Controller
{
    /** Valid {category} route values — must match CrawlFinding::CATEGORY_*. */
    private const CATEGORIES = [
        CrawlFinding::CATEGORY_BROKEN_LINK,
        CrawlFinding::CATEGORY_REDIRECT,
        CrawlFinding::CATEGORY_ONPAGE,
        CrawlFinding::CATEGORY_INDEXABILITY,
        CrawlFinding::CATEGORY_INTERNAL_LINKS,
        CrawlFinding::CATEGORY_SITEMAP,
        CrawlFinding::CATEGORY_SCHEMA,
        CrawlFinding::CATEGORY_PERFORMANCE,
        CrawlFinding::CATEGORY_SECURITY,
        CrawlFinding::CATEGORY_CRAWLABILITY,
    ];

    public function __construct(private readonly CrawlReportService $reports)
    {
    }

    /** GET /v1/hq/site-audit/summary — health overview + crawl status. */
    public function summary(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $s = $this->reports->summary($website->id);

        $s['last_crawled_at'] = $s['last_crawled_at']?->toIso8601String();

        return response()->json($s);
    }

    /** GET /v1/hq/site-audit/issues — open findings grouped by category. */
    public function issues(Request $request): JsonResponse
    {
        $website = $this->website($request);

        return response()->json([
            'groups' => $this->reports->actionGroups($website->id),
        ]);
    }

    /**
     * GET /v1/hq/site-audit/issues/{category} — per-type breakdown plus a
     * paginated finding list for one category, with fix guidance when the
     * caller filters down to a single type.
     */
    public function issueDetail(Request $request, string $category): JsonResponse
    {
        $website = $this->website($request);

        if (! in_array($category, self::CATEGORIES, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_category',
                'message' => 'Unknown issue category.',
                'valid' => self::CATEGORIES,
            ], 422);
        }

        $filters = [
            'type' => (string) $request->query('type', ''),
            'severity' => (string) $request->query('severity', ''),
            'q' => (string) $request->query('q', ''),
        ];

        $findings = $this->reports
            ->issuesQuery($category, $website->id, $filters)
            ->simplePaginate($this->perPage($request))
            ->through(fn (CrawlFinding $f): array => [
                'type' => $f->type,
                'label' => $this->reports->typeLabel($f->type),
                'severity' => $f->severity,
                'affected_url' => $f->affected_url,
                'source_url' => $f->page?->url,
                'detail' => $f->detail ?? [],
                'first_seen_at' => $f->first_seen_at?->toIso8601String(),
            ]);

        $out = [
            'category' => $category,
            'types' => $this->reports->typeBreakdown($category, $website->id, $filters['severity']),
            'findings' => [
                'data' => $findings->items(),
                'current_page' => $findings->currentPage(),
                'per_page' => $findings->perPage(),
                'has_more' => $findings->hasMorePages(),
            ],
        ];

        // Guidance is per-type, so only meaningful once a type is selected.
        if ($filters['type'] !== '') {
            $out['guidance'] = [
                'fix' => $this->reports->fixGuidance($filters['type']),
                'about' => $this->reports->auditAbout($filters['type']),
            ];
        }

        return response()->json($out);
    }

    /** GET /v1/hq/site-audit/pages — paginated crawled-page inventory. */
    public function pages(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'orphans', 'broken', 'noindex', 'deep'], true)) {
            $filter = 'all';
        }

        $paginated = $this->reports->inventory($website->id, $filter)
            ->simplePaginate($this->perPage($request));

        // Per-page open-issue counts (same matching as the page detail view,
        // so this number always equals what /site-audit/page lists).
        $counts = $this->reports->pageFindingCounts(
            $website->id,
            collect($paginated->items())->pluck('url_hash', 'id')->all(),
        );

        $pages = $paginated->through(fn ($p): array => [
            'url' => $p->url,
            'title' => $p->title,
            'http_status' => $p->http_status,
            'is_indexable' => (bool) $p->is_indexable,
            'click_depth' => $p->click_depth,
            'inbound_links' => (int) $p->inbound_link_count,
            'word_count' => $p->word_count,
            'page_score' => $p->page_score,
            'open_issues' => (int) ($counts[$p->id] ?? 0),
            'last_crawled_at' => $p->last_crawled_at?->toIso8601String(),
        ]);

        return response()->json([
            'filter' => $filter,
            'pages' => [
                'data' => $pages->items(),
                'current_page' => $pages->currentPage(),
                'per_page' => $pages->perPage(),
                'has_more' => $pages->hasMorePages(),
            ],
        ]);
    }

    /** GET /v1/hq/site-audit/page?url= — crawl intel + findings for one URL. */
    public function page(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $url = trim((string) $request->query('url', ''));

        if ($url === '') {
            return response()->json(['ok' => false, 'error' => 'missing_url', 'message' => 'A url query parameter is required.'], 422);
        }

        $structure = $this->reports->pageLinkStructure($website->id, $url);
        if ($structure === null) {
            return response()->json([
                'ok' => false,
                'error' => 'not_crawled',
                'message' => 'This page is not in the crawl inventory yet.',
            ], 404);
        }

        return response()->json([
            'page' => $structure['page'],
            'intel' => $this->reports->pageIntel($website->id, $url),
            'findings' => $this->reports->pageFindings($website->id, $url, (string) $structure['page']['id']),
        ]);
    }

    /**
     * GET /v1/hq/site-audit/links?url= — internal link explorer. Without a
     * url, returns top-inbound suggestions to seed the plugin's URL picker.
     */
    public function links(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $url = trim((string) $request->query('url', ''));

        if ($url === '') {
            return response()->json([
                'suggestions' => $this->reports->topInboundPages($website->id, 8),
            ]);
        }

        $structure = $this->reports->pageLinkStructure($website->id, $url);
        if ($structure === null) {
            return response()->json([
                'ok' => false,
                'error' => 'not_crawled',
                'message' => 'This page is not in the crawl inventory yet.',
            ], 404);
        }

        return response()->json(['structure' => $structure]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 25), 1), 50);
    }

    private function website(Request $request): Website
    {
        $w = $request->attributes->get('api_website');
        abort_unless($w instanceof Website, 500, 'Website context missing');
        abort_unless((bool) ($w->effectiveFeatureFlags()['hq'] ?? false), 403, 'This feature is not available on your plan.');

        return $w;
    }
}
