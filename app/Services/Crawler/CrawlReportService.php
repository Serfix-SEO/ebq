<?php

namespace App\Services\Crawler;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use App\Services\ReportCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over the SHARED crawl_site. Every method takes a
 * website id and internally resolves it to: the shared crawl_site, that user's
 * cap window (pages with value_rank <= the owner's plan crawl cap), the per-user
 * finding overlay (ignored/resolved), and the user's own 28d Search Console
 * clicks (used to compute per-user impact read-time — the shared findings store
 * impact 0). So a domain is crawled once but each user sees their own capped,
 * privately-scored view.
 */
class CrawlReportService
{
    /** Display metadata + dashboard severity per finding category. */
    private const CATEGORIES = [
        CrawlFinding::CATEGORY_CRAWLABILITY => ['title' => 'Crawler blocked', 'desc' => 'Bot-walled by the site (CAPTCHA/403/429) or blocked by robots.txt on a page that earns traffic.', 'sev' => 'critical'],
        CrawlFinding::CATEGORY_BROKEN_LINK => ['title' => 'Broken links', 'desc' => 'Internal or external links returning 4xx/5xx errors.', 'sev' => 'high'],
        CrawlFinding::CATEGORY_INDEXABILITY => ['title' => 'Indexability issues', 'desc' => 'noindex on traffic pages, canonical mismatches and similar.', 'sev' => 'high'],
        CrawlFinding::CATEGORY_INTERNAL_LINKS => ['title' => 'Internal-link issues', 'desc' => 'Orphan pages and pages buried too deep in the site.', 'sev' => 'high'],
        CrawlFinding::CATEGORY_REDIRECT => ['title' => 'Redirects', 'desc' => 'Redirecting URLs and redirect chains.', 'sev' => 'growth'],
        CrawlFinding::CATEGORY_ONPAGE => ['title' => 'On-page SEO issues', 'desc' => 'Missing/duplicate titles, meta, H1s, thin content, alt text.', 'sev' => 'growth'],
        CrawlFinding::CATEGORY_SITEMAP => ['title' => 'Sitemap issues', 'desc' => 'Sitemap coverage gaps and invalid sitemap URLs.', 'sev' => 'growth'],
        CrawlFinding::CATEGORY_SCHEMA => ['title' => 'Structured data', 'desc' => 'Pages missing or with invalid schema.org structured data.', 'sev' => 'growth'],
        CrawlFinding::CATEGORY_PERFORMANCE => ['title' => 'Slow pages', 'desc' => 'Pages with high fetch latency.', 'sev' => 'growth'],
        CrawlFinding::CATEGORY_SECURITY => ['title' => 'Mixed content', 'desc' => 'HTTPS pages loading plain-http resources — browsers block or warn on these.', 'sev' => 'high'],
    ];

    /** @var array<int,array{cs:?int,cap:int,clicks:array<string,int>}> per-request memo */
    private array $ctx = [];

    /**
     * Resolve a website to its shared-crawl read context (cached per request).
     *
     * @return array{cs:?int,cap:int,clicks:array<string,int>}
     */
    private function context(string $websiteId): array
    {
        return $this->ctx[$websiteId] ??= (function () use ($websiteId): array {
            $website = Website::find($websiteId);

            return [
                'cs' => $website?->crawl_site_id,
                'cap' => $website ? (int) $website->crawlPageCap() : 0,
                'clicks' => $this->loadUserClicks($websiteId),
            ];
        })();
    }

    /** This website's own 28d GSC clicks, keyed by url_hash (per-user impact). */
    private function loadUserClicks(string $websiteId): array
    {
        $since = Carbon::now()->subDays(28)->toDateString();
        $clicks = [];
        SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereDate('date', '>=', $since)
            ->where('page', '!=', '')
            ->select('page', DB::raw('SUM(clicks) as c'))
            ->groupBy('page')
            ->chunk(2000, function ($rows) use (&$clicks): void {
                foreach ($rows as $r) {
                    $clicks[WebsitePage::hashUrl((string) $r->page)] = (int) $r->c;
                }
            });

        return $clicks;
    }

    /** Per-user impact (clicks-at-risk) for a finding's URL. */
    private function impactFor(string $websiteId, string $urlHash): int
    {
        return (int) ($this->context($websiteId)['clicks'][$urlHash] ?? 0);
    }

    /** Window a website_pages query to this user's cap (value_rank <= cap; nulls visible). */
    private function windowPages(string $websiteId, ?Builder $q = null): Builder
    {
        $ctx = $this->context($websiteId);
        $q ??= WebsitePage::query();

        return $q->where('crawl_site_id', $ctx['cs'])
            ->where(fn (Builder $v) => $v->whereNull('value_rank')->orWhere('value_rank', '<=', $ctx['cap']));
    }

    /**
     * Base open-findings query for this user: the shared crawl_site's findings,
     * limited to pages inside the user's cap window (or site-level page-null
     * findings), excluding the ones this user has ignored/resolved.
     */
    private function findingsBase(string $websiteId, ?string $category = null): Builder
    {
        $ctx = $this->context($websiteId);
        $cap = $ctx['cap'];

        $q = CrawlFinding::where('crawl_site_id', $ctx['cs'])
            ->where('status', 'open')
            ->where(function (Builder $w) use ($cap): void {
                $w->whereNull('page_id')->orWhereExists(function ($sub) use ($cap): void {
                    $sub->from('website_pages')
                        ->whereColumn('website_pages.id', 'crawl_findings.page_id')
                        ->where(fn ($v) => $v->whereNull('value_rank')->orWhere('value_rank', '<=', $cap));
                });
            })
            ->whereNotExists(function ($sub) use ($websiteId): void {
                $sub->from('website_finding_states')
                    ->whereColumn('website_finding_states.finding_id', 'crawl_findings.id')
                    ->where('website_finding_states.website_id', $websiteId)
                    ->whereIn('status', ['ignored', 'resolved']);
            });

        if ($category !== null) {
            $q->where('category', $category);
        }

        return $q;
    }

    /**
     * Site-level health overview for a website.
     *
     * @return array<string,mixed>
     */
    public function summary(string $websiteId): array
    {
        $ctx = $this->context($websiteId);
        // Health + last-crawled come from the last COMPLETED run, so a later failed/
        // aborted recrawl can't wipe a healthy score or flash the "we can't crawl
        // this site" banner over data that's still valid. run_status reflects the
        // CURRENT (latest) run so the live crawl UI (running/finalizing partial
        // state) keeps working. blocked only when there is no good crawl to show.
        $latest = $ctx['cs'] ? CrawlRun::where('crawl_site_id', $ctx['cs'])->latest('started_at')->first() : null;
        $completed = $ctx['cs']
            ? CrawlRun::where('crawl_site_id', $ctx['cs'])->where('status', CrawlRun::STATUS_COMPLETED)->latest('finished_at')->first()
            : null;
        $display = $completed ?? $latest;

        $bySeverity = $this->findingsBase($websiteId)
            ->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity');

        $pagesTotal = $this->windowPages($websiteId)->whereNotNull('last_crawled_at')->whereNull('removed_at')->count();
        $indexable = $this->windowPages($websiteId)->indexable()->whereNotNull('last_crawled_at')->count();
        $orphans = $this->windowPages($websiteId)->orphans()->count();

        return [
            'has_crawl' => $latest !== null,
            'health_score' => $display?->health_score,
            'last_crawled_at' => $display?->finished_at ?? $display?->started_at,
            'run_status' => $latest?->status,
            'blocked' => $completed === null && ($latest?->isBlocked() ?? false),
            'blocked_reason' => $completed === null ? $latest?->blocked_reason : null,
            'pages_total' => $pagesTotal,
            'pages_indexable' => $indexable,
            'orphan_count' => $orphans,
            'findings' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'high' => (int) ($bySeverity['high'] ?? 0),
                'medium' => (int) ($bySeverity['medium'] ?? 0),
                'low' => (int) ($bySeverity['low'] ?? 0),
                'total' => (int) $bySeverity->sum(),
            ],
        ];
    }

    /**
     * Open findings grouped by category, ranked. Per-user impact is summed from
     * this user's own clicks (the shared findings store impact 0).
     *
     * @return array<int,array<string,mixed>>
     */
    public function actionGroups(string $websiteId): array
    {
        $rows = $this->findingsBase($websiteId)
            ->select('category', DB::raw('COUNT(*) as c'),
                DB::raw("SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as crit"),
                DB::raw("SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END) as high"))
            ->groupBy('category')->get();

        // Per-user impact per category: sum this user's clicks over the category's
        // affected URLs (bounded fetch of category + url_hash for open in-window findings).
        $impactByCategory = [];
        $this->findingsBase($websiteId)->select('category', 'affected_url_hash')
            ->chunk(2000, function ($findings) use ($websiteId, &$impactByCategory): void {
                foreach ($findings as $f) {
                    $impactByCategory[$f->category] = ($impactByCategory[$f->category] ?? 0) + $this->impactFor($websiteId, $f->affected_url_hash);
                }
            });

        $groups = [];
        foreach ($rows as $row) {
            $meta = self::CATEGORIES[$row->category] ?? ['title' => ucfirst($row->category), 'desc' => '', 'sev' => 'growth'];
            $sev = $meta['sev'];
            if ((int) $row->crit > 0) {
                $sev = 'critical';
            } elseif ((int) $row->high > 0 && $sev === 'growth') {
                $sev = 'high';
            }
            $groups[] = [
                'key' => 'crawl_'.$row->category,
                'title' => $meta['title'],
                'description' => $meta['desc'],
                'count' => (int) $row->c,
                'severity' => $sev,
                'impact' => (float) ($impactByCategory[$row->category] ?? 0),
                'impact_label' => null,
                'action_label' => 'View',
            ];
        }

        return $groups;
    }

    /**
     * Detail rows for one crawl category (Priority Action Queue slide-over).
     *
     * @return array<int,array<string,mixed>>
     */
    public function issueRows(string $category, string $websiteId): array
    {
        return $this->issuesQuery($category, $websiteId)
            ->limit(100)
            ->get()
            ->map(fn (CrawlFinding $f): array => $this->mapFinding($f, $websiteId))
            ->all();
    }

    /**
     * Base query for the open findings in one crawl category for this user
     * (cap-windowed, overlay-filtered). Returns the builder so callers paginate.
     */
    public function issuesQuery(string $category, string $websiteId, array $filters = []): Builder
    {
        $q = $this->findingsBase($websiteId, $category)->with('page:id,url');

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }
        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (! empty($filters['q'])) {
            $q->where('affected_url', 'like', '%'.$filters['q'].'%');
        }

        // Per-user impact isn't stored, so order by severity then recency (stable,
        // index-friendly) rather than the now-zero stored impact.
        return $q->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('id');
    }

    /** Open-finding counts per type within a category for this user. */
    public function typeCounts(string $category, string $websiteId): array
    {
        return $this->findingsBase($websiteId, $category)
            ->select('type', DB::raw('COUNT(*) as c'))
            ->groupBy('type')->orderByDesc('c')
            ->pluck('c', 'type')->all();
    }

    /**
     * Per-type breakdown for the Semrush-style grouped issue list: one row per
     * issue TYPE (not the coarser category bucket), each with its own count and
     * worst-seen severity, sorted critical→low then by count. Used by SiteIssues
     * to render type sections instead of one undifferentiated category list.
     *
     * @return list<array{type:string,label:string,count:int,severity:string}>
     */
    public function typeBreakdown(string $category, string $websiteId, string $severity = ''): array
    {
        $base = $this->findingsBase($websiteId, $category);
        if ($severity !== '') {
            $base->where('severity', $severity);
        }
        $rows = $base
            ->select('type', DB::raw('COUNT(*) as c'),
                DB::raw("SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as crit"),
                DB::raw("SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END) as high"),
                DB::raw("SUM(CASE WHEN severity='medium' THEN 1 ELSE 0 END) as med"))
            ->groupBy('type')->get();

        $out = [];
        foreach ($rows as $r) {
            $sev = 'low';
            if ((int) $r->crit > 0) {
                $sev = 'critical';
            } elseif ((int) $r->high > 0) {
                $sev = 'high';
            } elseif ((int) $r->med > 0) {
                $sev = 'medium';
            }
            $out[] = ['type' => $r->type, 'label' => $this->typeLabel($r->type), 'count' => (int) $r->c, 'severity' => $sev];
        }

        $tier = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($out, fn (array $a, array $b): int => ($tier[$a['severity']] <=> $tier[$b['severity']]) ?: ($b['count'] <=> $a['count']));

        return $out;
    }

    /** Human label for a finding type, e.g. 'missing_image_alt' → 'Missing image alt'. */
    public function typeLabel(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }

    /** Normalize one finding into the uniform detail-row shape used by the UI. */
    public function mapFinding(CrawlFinding $f, string $websiteId): array
    {
        $impact = $this->impactFor($websiteId, $f->affected_url_hash);

        if (in_array($f->type, ['broken_external', 'external_redirect'], true)) {
            $source = $f->page?->url;

            return [
                'title' => $f->affected_url,
                'subtitle' => ($source ? 'On '.$this->shortUrl($source).' · ' : '').$this->describe($f),
                'metric' => null,
                'fix_url' => $source,
                'fix_feature' => 'link_structure',
                'fix_new_tab' => true,
            ];
        }

        return [
            'title' => $this->shortUrl($f->affected_url),
            'subtitle' => $this->describe($f),
            'metric' => $impact > 0 ? number_format($impact).' clicks (28d)' : null,
            'fix_url' => route('link-structure.index', ['url' => $f->affected_url]),
            'fix_feature' => 'link_structure',
        ];
    }

    /**
     * Full finding detail for one category, with per-user impact.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoryFindings(string $category, string $websiteId, int $limit = 200): array
    {
        $rows = $this->findingsBase($websiteId, $category)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrawlFinding $f): array => [
                'url' => $f->affected_url,
                'label' => $this->shortUrl($f->affected_url),
                'description' => $this->describe($f),
                'severity' => $f->severity,
                'impact' => $this->impactFor($websiteId, $f->affected_url_hash),
                'detail' => $f->detail ?? [],
            ])->all();

        // Stable order with per-user impact as the tiebreaker within severity.
        usort($rows, fn ($a, $b) => $b['impact'] <=> $a['impact']);

        return $rows;
    }

    /**
     * Full per-category issue breakdown for the admin Marketing report email.
     *
     * @return array<int,array<string,mixed>>
     */
    public function reportBreakdown(string $websiteId, int $perCategory = 5): array
    {
        $rank = ['critical' => 0, 'high' => 1, 'growth' => 2, 'medium' => 2, 'low' => 3];
        $groups = $this->actionGroups($websiteId);
        usort($groups, fn ($a, $b) => [$rank[$a['severity']] ?? 9, -$a['impact']] <=> [$rank[$b['severity']] ?? 9, -$b['impact']]);

        $out = [];
        foreach ($groups as $g) {
            $category = \Illuminate\Support\Str::after($g['key'], 'crawl_');
            $out[] = [
                'key' => $category,
                'title' => $g['title'],
                'severity' => $g['severity'],
                'count' => (int) $g['count'],
                'impact' => (int) $g['impact'],
                'examples' => $this->dedupeByUrl(
                    $this->categoryFindings($category, $websiteId, max($perCategory * 4, 12)),
                    $perCategory,
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,array<string,mixed>>
     */
    private function dedupeByUrl(array $findings, int $limit): array
    {
        $seen = [];
        $out = [];
        foreach ($findings as $f) {
            $url = (string) ($f['url'] ?? $f['label'] ?? '');
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [
                'url' => $f['url'] ?? null,
                'label' => $f['label'] ?? null,
                'description' => $f['description'] ?? '',
                'severity' => $f['severity'] ?? 'low',
                'impact' => (int) ($f['impact'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Paginated page inventory (cap-windowed) for the Site Health page. */
    public function inventory(string $websiteId, string $filter = 'all')
    {
        $q = $this->windowPages($websiteId)->whereNull('removed_at')->whereNotNull('last_crawled_at');

        match ($filter) {
            'orphans' => $q->orphans(),
            'broken' => $q->where('http_status', '>=', 400),
            'noindex' => $q->where('is_indexable', false),
            'deep' => $q->where('click_depth', '>=', (int) config('crawler.deep_page_threshold', 3)),
            default => null,
        };

        return $q->orderByDesc('inbound_link_count')->orderBy('url');
    }

    /**
     * Compact internal-link graph for the diagram (cap-windowed, capped).
     *
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,int>>}
     */
    public function linkGraph(string $websiteId, int $cap = 120): array
    {
        $ctx = $this->context($websiteId);
        $pages = $this->windowPages($websiteId)
            ->whereNotNull('last_crawled_at')->whereNull('removed_at')
            ->orderByDesc('inbound_link_count')
            ->limit($cap)
            ->get(['id', 'url', 'inbound_link_count', 'click_depth', 'is_indexable']);

        $ids = $pages->pluck('id')->all();
        $nodes = $pages->map(fn (WebsitePage $p) => [
            'id' => $p->id,
            'label' => $this->shortUrl($p->url),
            'inbound' => (int) $p->inbound_link_count,
            'depth' => $p->click_depth,
            'indexable' => (bool) $p->is_indexable,
        ])->all();

        $edges = WebsiteInternalLink::where('crawl_site_id', $ctx['cs'])
            ->where('status', 'discovered')
            ->whereIn('from_page_id', $ids)->whereIn('to_page_id', $ids)
            ->limit(600)
            ->get(['from_page_id', 'to_page_id'])
            ->map(fn ($e) => ['from' => (string) $e->from_page_id, 'to' => (string) $e->to_page_id])
            ->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Per-page internal-link structure for the Link Structure page.
     *
     * @return array<string,mixed>|null  null when the URL isn't in the inventory
     */
    public function pageLinkStructure(string $websiteId, string $url): ?array
    {
        $ctx = $this->context($websiteId);
        $page = WebsitePage::where('crawl_site_id', $ctx['cs'])
            ->where('url_hash', WebsitePage::hashUrl($url))->first();
        if (! $page) {
            return null;
        }

        $inbound = WebsiteInternalLink::where('to_page_id', $page->id)
            ->where('status', 'discovered')->with('fromPage:id,url,http_status,is_indexable')
            ->limit(500)->get()
            ->map(fn ($l) => [
                'url' => $l->fromPage?->url, 'anchor' => $l->anchor_text,
                'status' => $l->fromPage?->http_status, 'indexable' => (bool) $l->fromPage?->is_indexable,
            ])->filter(fn ($r) => $r['url'])->values()->all();

        $outbound = WebsiteInternalLink::where('from_page_id', $page->id)
            ->where('status', 'discovered')->with('toPage:id,url,http_status,is_indexable')
            ->limit(500)->get()
            ->map(fn ($l) => [
                'url' => $l->toPage?->url, 'anchor' => $l->anchor_text,
                'status' => $l->toPage?->http_status, 'indexable' => (bool) $l->toPage?->is_indexable,
                'broken' => (int) $l->toPage?->http_status >= 400,
            ])->filter(fn ($r) => $r['url'])->values()->all();

        $suggestedInbound = WebsiteInternalLink::where('to_page_id', $page->id)
            ->where('status', 'suggested')->with('fromPage:id,url')
            ->limit(50)->get()
            ->map(fn ($l) => ['url' => $l->fromPage?->url, 'anchor' => $l->anchor_text])
            ->filter(fn ($r) => $r['url'])->values()->all();

        $crawlRunning = $ctx['cs'] && CrawlRun::where('crawl_site_id', $ctx['cs'])
            ->whereIn('status', [CrawlRun::STATUS_RUNNING, CrawlRun::STATUS_FINALIZING])->exists();
        $path = (string) parse_url($page->url, PHP_URL_PATH);
        $isHomepage = $path === '' || $path === '/';

        return [
            'page' => [
                'url' => $page->url,
                'title' => $page->title,
                'http_status' => $page->http_status,
                'is_indexable' => (bool) $page->is_indexable,
                'click_depth' => $page->click_depth,
                'page_score' => $page->page_score,
                'inbound_count' => (int) $page->inbound_link_count,
                'outbound_count' => (int) $page->internal_link_count,
                'is_homepage' => $isHomepage,
                'crawl_running' => $crawlRunning,
                'source_sitemap' => (bool) $page->source_sitemap,
                'source_gsc' => (bool) $page->source_gsc,
                'discovered_at' => $page->discovered_at,
            ],
            'path' => $this->pathFromHome($websiteId, $page),
            'inbound' => $inbound,
            'outbound' => $outbound,
            'suggested_inbound' => $suggestedInbound,
        ];
    }

    /**
     * @return array<int,array{url:string,title:?string,is_current:bool}>
     */
    private function pathFromHome(string $websiteId, WebsitePage $page): array
    {
        $parents = $this->bfsParents($websiteId);
        if (! array_key_exists($page->id, $parents)) {
            return [];
        }

        $ids = [];
        $node = $page->id;
        $guard = 0;
        while ($node !== null && $guard++ < 100) {
            $ids[] = $node;
            $node = $parents[$node] ?? null;
        }
        $ids = array_reverse($ids);

        $byId = WebsitePage::whereIn('id', $ids)->pluck('url', 'id');
        $titles = WebsitePage::whereIn('id', $ids)->pluck('title', 'id');

        $out = [];
        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                continue;
            }
            $out[] = [
                'url' => (string) $byId[$id],
                'title' => $titles[$id] ?? null,
                'is_current' => $id === $page->id,
            ];
        }

        return $out;
    }

    /**
     * BFS parent map from the homepage over the shared discovered link graph.
     * Cached on the crawl_site (shared across subscribers).
     *
     * @return array<int,int|null>
     */
    private function bfsParents(string $websiteId): array
    {
        $ctx = $this->context($websiteId);
        $crawlSiteId = $ctx['cs'];
        if (! $crawlSiteId) {
            return [];
        }

        return Cache::remember(
            "ls-parents-cs:{$crawlSiteId}:".ReportCache::version($websiteId),
            3600,
            function () use ($crawlSiteId): array {
                $homeId = $this->homepageId($crawlSiteId);
                if ($homeId === null) {
                    return [];
                }

                $adjacency = [];
                foreach (
                    DB::table('website_internal_links')
                        ->where('crawl_site_id', $crawlSiteId)->where('status', 'discovered')
                        ->select('id', 'from_page_id', 'to_page_id')
                        ->lazyById(5000, 'id') as $r
                ) {
                    $adjacency[$r->from_page_id][] = $r->to_page_id;
                }

                $parent = [$homeId => null];
                $queue = [$homeId];
                while ($queue !== []) {
                    $node = array_shift($queue);
                    foreach ($adjacency[$node] ?? [] as $next) {
                        if (! array_key_exists($next, $parent)) {
                            $parent[$next] = $node;
                            $queue[] = $next;
                        }
                    }
                }

                return $parent;
            }
        );
    }

    private function homepageId(string $crawlSiteId): ?string
    {
        $site = \App\Models\CrawlSite::find($crawlSiteId);
        if ($site) {
            $id = WebsitePage::where('crawl_site_id', $crawlSiteId)
                ->where('url_hash', WebsitePage::hashUrl($site->homepageUrl()))->value('id');
            if ($id) {
                return $id;
            }
        }

        return WebsitePage::where('crawl_site_id', $crawlSiteId)
            ->whereNull('removed_at')->orderByRaw('LENGTH(url) asc')->orderBy('id')->value('id');
    }

    /**
     * Crawl knowledge for one URL — for the AI ContextBuilder.
     *
     * @return array<string,mixed>|null
     */
    public function pageIntel(string $websiteId, string $url): ?array
    {
        $ctx = $this->context($websiteId);
        $page = WebsitePage::where('crawl_site_id', $ctx['cs'])
            ->where('url_hash', WebsitePage::hashUrl($url))->first();
        if (! $page) {
            return null;
        }

        $findings = $this->findingsBase($websiteId)->where('page_id', $page->id)->pluck('type')->all();
        $userHasTraffic = $this->impactFor($websiteId, $page->url_hash) > 0;

        return [
            'http_status' => $page->http_status,
            'is_indexable' => (bool) $page->is_indexable,
            'word_count' => $page->word_count,
            'inbound_links' => (int) $page->inbound_link_count,
            'outbound_internal_links' => (int) $page->internal_link_count,
            'click_depth' => $page->click_depth,
            'is_orphan' => (int) $page->inbound_link_count === 0,
            'page_score' => $page->page_score,
            'discovered_via' => array_values(array_filter([
                (int) $page->inbound_link_count > 0 ? 'internal_links' : null,
                $page->source_sitemap ? 'sitemap' : null,
                $userHasTraffic ? 'search_console' : null,
            ])),
            'issues' => $findings,
        ];
    }

    private function describe(CrawlFinding $f): string
    {
        $d = $f->detail ?? [];

        return match ($f->type) {
            'broken_internal' => 'Internal link → '.($d['http_status'] ?? '4xx').' ('.($d['inbound_links'] ?? 1).' inbound)',
            'broken_external' => 'External link → '.($d['http_status'] ?? 'error'),
            'broken_page' => 'Page returns '.($d['http_status'] ?? '4xx').$this->discoveryOrigin($d),
            'noindex_important' => 'noindex on a page that earns traffic',
            'canonical_mismatch' => 'Canonical points elsewhere',
            'orphan_page' => 'No internal links point here',
            'deep_page' => 'Click depth '.($d['click_depth'] ?? '3+').' from homepage',
            'thin_content' => 'Only '.($d['word_count'] ?? 'few').' words',
            'redirecting_url' => 'Redirects to '.$this->shortUrl((string) ($d['redirect_target'] ?? '')),
            'duplicate_title' => 'Same title used on '.(((int) ($d['duplicate_count'] ?? 1)) - 1).' other page(s)',
            'duplicate_meta_description' => 'Same meta description used on '.(((int) ($d['duplicate_count'] ?? 1)) - 1).' other page(s)',
            'duplicate_content' => 'Identical content found on '.(((int) ($d['duplicate_count'] ?? 1)) - 1).' other page(s)',
            'missing_self_hreflang' => 'Hreflang tags present but none reference this page itself',
            'hreflang_canonical_conflict' => 'Hreflang declares this page while canonical points elsewhere',
            'redirect_chain_too_long' => ($d['hops'] ?? '3+').' redirect hops before landing',
            'slow_response' => 'Fetch took '.number_format(((int) ($d['ttfb_ms'] ?? 0)) / 1000, 1).'s',
            'missing_twitter_card' => 'No Twitter Card meta tags',
            'invalid_structured_data' => (($d['invalid_count'] ?? 1)).' malformed JSON-LD block(s)',
            'robots_blocked_important' => 'Blocked by robots.txt ('.($d['path'] ?? '').') on a page that earns traffic',
            'mixed_content' => ((int) ($d['count'] ?? 1)).' plain-http resource(s) on an https page',
            'sitemap_broken_url' => 'Sitemap lists a URL that returns '.($d['http_status'] ?? '4xx'),
            'sitemap_redirect_url' => 'Sitemap lists a URL that redirects to '.$this->shortUrl((string) ($d['redirect_target'] ?? '')),
            'sitemap_noindex_url' => 'Sitemap lists a non-indexable URL',
            'crawl_blocked' => 'Crawler blocked ('.($d['reason'] ?? 'unknown').')',
            default => ucfirst(str_replace('_', ' ', $f->type)),
        };
    }

    /**
     * @param  array<string,mixed>  $d
     */
    private function discoveryOrigin(array $d): string
    {
        $parts = [];
        $n = (int) ($d['inbound_internal'] ?? 0);
        if ($n > 0) {
            $parts[] = $n.' internal link'.($n === 1 ? '' : 's');
        }
        if (! empty($d['source_sitemap'])) {
            $parts[] = 'sitemap';
        }
        if (! empty($d['source_gsc'])) {
            $parts[] = 'Search Console';
        }

        return $parts === [] ? '' : ' · found via '.implode(', ', $parts);
    }

    private function shortUrl(?string $url): string
    {
        if (! $url) {
            return '—';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' && $path !== '/' ? $path : $url;
    }
}
