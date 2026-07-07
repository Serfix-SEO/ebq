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

    /**
     * Finding types whose EXISTENCE requires GSC data (not just severity) — every
     * other finding type fires from crawl data alone, GSC only ever bumps
     * severity. Search Console history can lag real-world site state by days, so
     * these carry real false-positive risk and get their own section/heading in
     * the UI instead of being mixed into pure-crawl findings.
     */
    private const GSC_SOURCED_TYPES = ['indexed_not_in_sitemap'];

    /** @var array<int,array{cs:?int,cap:int}> per-request memo */
    private array $ctx = [];

    /** @var array<string,array<string,int>> per-request memo, built on first impactFor() call */
    private array $clicks = [];

    /**
     * Resolve a website to its shared-crawl read context (cached per request).
     *
     * Deliberately does NOT load the 28d GSC click map — that's a full
     * GROUP BY page over the website's search_console_data (20s+ on a
     * 1.3M-row site) and only the finding-impact paths need it. Loading it
     * here made every context() consumer pay for it; summary() (called on
     * every /dashboard load, uncached by design for the live crawl banner)
     * was spending ~all of its time building a click map it never read
     * (found 2026-07-06). Clicks are lazy via userClicks() instead.
     *
     * @return array{cs:?int,cap:int}
     */
    private function context(string $websiteId): array
    {
        return $this->ctx[$websiteId] ??= (function () use ($websiteId): array {
            $website = Website::find($websiteId);

            return [
                'cs' => $website?->crawl_site_id,
                'cap' => $website ? (int) $website->crawlPageCap() : 0,
            ];
        })();
    }

    /** This website's own 28d GSC clicks, keyed by url_hash (per-user impact). Lazy + memoized. */
    private function userClicks(string $websiteId): array
    {
        return $this->clicks[$websiteId] ??= (function () use ($websiteId): array {
            // Anchor to the last finalized GSC day, not "now" — the raw
            // 28d-from-now window held 2-3 empty lag days (see
            // ReportDataService::statsWindowEnd), understating per-user impact.
            $anchor = app(\App\Services\ReportDataService::class)->lastSafeReportDate($websiteId)
                ?? Carbon::now()->subDay();
            $since = $anchor->copy()->subDays(27)->toDateString();
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
        })();
    }

    /** Per-user impact (clicks-at-risk) for a finding's URL. */
    private function impactFor(string $websiteId, string $urlHash): int
    {
        return (int) ($this->userClicks($websiteId)[$urlHash] ?? 0);
    }

    /**
     * Cache wrapper for the post-crawl finding aggregates below — these only
     * change when a new crawl run completes (or new GSC data lands, which also
     * bumps the same version per {@see ReportCache}), so it's safe to hold them
     * until then instead of re-running the underlying queries on every page
     * load. Deliberately NOT used for summary()/anything reflecting the
     * CURRENT run's live status (running/finalizing) — that has to stay
     * real-time for the crawl-progress banner.
     */
    private function remember(string $tag, string $websiteId, \Closure $compute): mixed
    {
        $key = "crawl-rpt:{$tag}:{$websiteId}:v".ReportCache::version($websiteId);

        return Cache::remember($key, now()->addHours(24), $compute);
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
     * Top-N most-inbound-linked crawled pages, inside this user's cap window.
     * Used to suggest example URLs (Link Structure panel) — cap-filtered so a
     * small-cap user is never shown pages outside their window (found
     * 2026-07-06: the caller used to query WebsitePage directly with no cap
     * filter at all).
     *
     * @return list<string>
     */
    public function topInboundPages(string $websiteId, int $limit = 8): array
    {
        return $this->windowPages($websiteId)
            ->whereNotNull('last_crawled_at')->whereNull('removed_at')
            ->orderByDesc('inbound_link_count')->limit($limit)->pluck('url')->all();
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
        // tag v2: payload gained the per-type 'types' breakdown (2026-07-07)
        return $this->remember('actionGroups2', $websiteId, fn (): array => $this->computeActionGroups($websiteId));
    }

    private function computeActionGroups(string $websiteId): array
    {
        $rows = $this->findingsBase($websiteId)
            ->select('category', DB::raw('COUNT(*) as c'),
                DB::raw("SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as crit"),
                DB::raw("SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END) as high"))
            ->groupBy('category')->get();

        // Per-type breakdown per category ("Missing H1 × 197 · Alt text × 320")
        // — one grouped query for the whole card, labels via typeLabel().
        $typesByCategory = [];
        foreach ($this->findingsBase($websiteId)
            ->select('category', 'type', DB::raw('COUNT(*) as c'))
            ->groupBy('category', 'type')->orderByDesc('c')->get() as $t) {
            $typesByCategory[$t->category][] = [
                'type' => $t->type,
                'label' => $this->typeLabel($t->type),
                'count' => (int) $t->c,
            ];
        }

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
                'types' => $typesByCategory[$row->category] ?? [],
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
        return $this->remember("typeBreakdown:{$category}:{$severity}", $websiteId, fn (): array => $this->computeTypeBreakdown($category, $websiteId, $severity));
    }

    private function computeTypeBreakdown(string $category, string $websiteId, string $severity): array
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
            $out[] = ['type' => $r->type, 'label' => $this->typeLabel($r->type), 'count' => (int) $r->c, 'severity' => $sev, 'gsc_sourced' => $this->isGscSourced($r->type)];
        }

        $tier = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        // GSC-sourced types sort to the bottom regardless of severity tier — they
        // get their own section in the UI, not mixed into the crawl-derived list.
        usort($out, fn (array $a, array $b): int => ($a['gsc_sourced'] <=> $b['gsc_sourced']) ?: ($tier[$a['severity']] <=> $tier[$b['severity']]) ?: ($b['count'] <=> $a['count']));

        return $out;
    }

    public function isGscSourced(string $type): bool
    {
        return in_array($type, self::GSC_SOURCED_TYPES, true);
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
                // The fix lives on the SOURCE page (it owns the bad link), not the
                // off-site target — route to our Page Health view for that page,
                // same as every other finding, instead of opening the live site.
                'fix_url' => $source ? route('link-structure.index', ['url' => $source, 'issue' => $f->type]) : null,
                'fix_feature' => 'link_structure',
            ];
        }

        // Broken internal links carry referrers (the source page(s) linking to the
        // dead URL) in detail — surface the first one inline, same as broken_external
        // does with its source page, so the user isn't forced to click through to
        // Page Health just to see what to fix.
        $referrers = $f->detail['referrers'] ?? [];
        $sourcePrefix = '';
        if ($referrers !== []) {
            $first = $this->shortUrl($referrers[0]['url'] ?? null);
            $extra = count($referrers) - 1;
            $sourcePrefix = 'Linked from '.$first.($extra > 0 ? " (+{$extra} more)" : '').' · ';
        }

        return [
            'title' => $this->shortUrl($f->affected_url),
            'subtitle' => $sourcePrefix.$this->describe($f),
            'metric' => $impact > 0 ? number_format($impact).' clicks (28d)' : null,
            'fix_url' => route('link-structure.index', ['url' => $f->affected_url, 'issue' => $f->type]),
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
        return $this->remember("categoryFindings:{$category}:{$limit}", $websiteId, fn (): array => $this->computeCategoryFindings($category, $websiteId, $limit));
    }

    private function computeCategoryFindings(string $category, string $websiteId, int $limit): array
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
                'id' => $page->id,
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
     * Concrete "what to do" instructions per finding type — the actual fix
     * action, not just a restatement of the problem. Every type the catalog
     * produces has an entry (no generic fallback) so the Page Health panel
     * never leaves a user without a next step.
     */
    public function fixGuidance(string $type): string
    {
        return match ($type) {
            'broken_internal' => 'Update or remove the link on the source page below so it points at a live URL — or restore/redirect the dead target.',
            'broken_external' => 'Replace the link with a working URL, or remove it if the destination is permanently gone.',
            'external_redirect' => 'Update the link to point straight at the final destination — skip the redirect hop.',
            'broken_page' => 'Restore this page, 301-redirect it to the closest live equivalent, or return 410 if it should stay gone for good. Then fix or remove every internal link pointing here (listed below).',
            'noindex_important' => 'Remove the noindex directive (meta robots tag or X-Robots-Tag header) — this page already earns search traffic, so noindex is actively hiding it.',
            'canonical_mismatch' => 'Either remove the canonical tag (if this page should rank on its own) or 301-redirect it to the canonical target — right now it ranks despite telling Google not to.',
            'orphan_page' => 'Add at least one internal link to this page from relevant existing content — Google relies on internal links to find and re-crawl it.',
            'deep_page' => 'Add internal links from higher-level pages (homepage, category/hub pages) to shorten the click path — pages many clicks deep get crawled and ranked less.',
            'thin_content' => 'Expand the page with substantive, unique content (aim for 200+ words) or merge it into a more complete page if it can\'t stand alone.',
            'redirecting_url' => 'Update every internal link/sitemap entry pointing at this URL to use the final destination directly, instead of routing through a redirect.',
            'redirect_chain_too_long' => 'Point the link straight at the final destination — collapse the chain to a single hop.',
            'duplicate_title' => 'Write a unique, specific title for this page. Check the other pages listed below — they need unique titles too.',
            'duplicate_meta_description' => 'Write a unique meta description summarizing THIS page\'s content. Check the other pages listed below.',
            'duplicate_content' => 'Rewrite this page with unique content, or pick one canonical version and 301-redirect/canonicalize the rest to it (see the other URLs below).',
            'missing_self_hreflang' => 'Add a <link rel="alternate" hreflang="..." href="THIS-page-url"> tag pointing at this page itself, alongside the existing alternates.',
            'hreflang_canonical_conflict' => 'Make the canonical tag point at this page itself (not elsewhere) — a page can\'t be both "the right page for this locale" (hreflang) and "not the right page" (canonical pointing away).',
            'missing_title' => 'Add a unique <title> tag, ideally 15–60 characters, describing this specific page.',
            'title_too_long' => 'Shorten the <title> tag to under ~60 characters so it doesn\'t get truncated in search results.',
            'title_too_short' => 'Expand the <title> tag — under 15 characters rarely gives search engines or users enough context.',
            'missing_meta_description' => 'Add a meta description (~50–160 characters) summarizing the page — it drives the search-result snippet.',
            'meta_description_too_long' => 'Shorten the meta description to under ~160 characters so it isn\'t truncated in search results.',
            'missing_h1' => 'Add a single <h1> heading that states what the page is about.',
            'multiple_h1' => 'Keep exactly one <h1> per page — demote the extras to <h2> or lower.',
            'broken_heading_order' => 'Fix the heading order so levels don\'t skip (e.g. no <h3> directly after an <h1> with no <h2> between) — it confuses both users and search engines about the page\'s outline.',
            'missing_image_alt' => 'Add descriptive alt text to every image listed — it\'s required for accessibility and helps image search.',
            'missing_open_graph' => 'Add Open Graph tags (og:title, og:description, og:image at minimum) so shared links render properly on social platforms.',
            'missing_twitter_card' => 'Add Twitter Card meta tags (twitter:card, twitter:title, twitter:image) for proper rendering when shared on X/Twitter.',
            'missing_structured_data' => 'Add relevant JSON-LD structured data (e.g. Article, Product, FAQPage) so this page is eligible for rich results.',
            'invalid_structured_data' => 'Fix the malformed JSON-LD block(s) — run the page through Google\'s Rich Results Test to see the exact parse error.',
            'slow_response' => 'Investigate server/page latency for this URL — caching, a CDN, or optimizing slow backend calls will speed this up.',
            'mixed_content' => 'Change every plain-http:// resource listed below to https:// — browsers block or warn on http resources on an https page.',
            'robots_blocked_important' => 'Remove or narrow the Disallow rule in robots.txt for this path — it\'s currently blocking a page the site itself treats as real (sitemap-listed or internally linked).',
            'sitemap_broken_url' => 'Remove this URL from the sitemap (it 404s/errors) — a sitemap should only list live, working URLs.',
            'sitemap_redirect_url' => 'Update the sitemap to list the final destination URL directly, not the redirecting one.',
            'sitemap_noindex_url' => 'Either remove this URL from the sitemap or make it indexable — listing a non-indexable page in the sitemap sends Google a contradictory signal.',
            'indexed_not_in_sitemap' => 'Add this URL to the sitemap — Google already indexes it via Search Console, so the sitemap should reflect that too.',
            'crawl_blocked' => 'The site is bot-walling our crawler (CAPTCHA/403/429) — check firewall/WAF rules for an allowance, or this and every check below it can\'t run.',
            default => 'Review this finding\'s detail and address the underlying cause.',
        };
    }

    /**
     * "About this issue" copy for the Site Audit PDF export — explains WHY a
     * finding type matters (impact on rankings/UX/crawl budget), paired with
     * fixGuidance()'s WHAT-TO-DO. Every type has a real entry; no generic
     * fallback, matching the standard set by professional audit tools.
     */
    public function auditAbout(string $type): string
    {
        return match ($type) {
            'broken_internal' => 'Internal links pointing at dead pages waste crawl budget and strand any link equity flowing through them — and they\'re a broken experience for any visitor who clicks.',
            'broken_external' => 'A link to a dead external page is a poor user experience and can make the page look unmaintained, which search engines weigh when assessing content quality.',
            'external_redirect' => 'Linking through an unnecessary redirect adds latency and a small amount of link-equity loss per hop, and increases the odds the destination eventually breaks.',
            'broken_page' => 'A page returning an error wastes whatever links/sitemap entries/search-engine trust point at it, and any visitor who lands here hits a dead end.',
            'noindex_important' => 'A noindex directive tells search engines to drop this page from their index entirely. If the page is structurally important (linked, sitemap-listed, or the homepage), this is usually accidental and actively hides traffic-worthy content.',
            'canonical_mismatch' => 'A canonical tag tells search engines "don\'t rank this URL, rank that one instead." When the page is structurally important but still gets traffic, the canonical is actively working against the page\'s own visibility.',
            'orphan_page' => 'Pages with no internal links pointing to them are harder for search engines to discover and re-crawl, and receive no internal link equity — both hurt ranking potential.',
            'deep_page' => 'Pages buried many clicks from the homepage get crawled less frequently and are seen as less important by search engines, which weigh proximity to the homepage as a relevance signal.',
            'thin_content' => 'Pages with very little text give search engines little to work with when deciding what queries the page is relevant for, and tend to underperform fuller competing pages.',
            'redirecting_url' => 'A URL that immediately redirects elsewhere shouldn\'t be the one referenced by internal links or the sitemap — it adds an avoidable hop on every visit.',
            'redirect_chain_too_long' => 'Each redirect hop adds latency and a small amount of link-equity loss; chains of 3+ also risk search engines giving up before reaching the final page.',
            'duplicate_title' => 'Search engines use the title tag to understand and display what a page is about. Identical titles across pages make it harder for search engines to tell them apart and decide which one to rank.',
            'duplicate_meta_description' => 'A duplicate meta description is a missed opportunity to differentiate each page in search results and wastes the snippet that drives click-through from the results page.',
            'duplicate_content' => 'Search engines generally index and rank only one version of duplicate content, so the others compete against themselves for the same ranking slot instead of earning their own.',
            'missing_self_hreflang' => 'A page with hreflang alternates needs a self-referencing entry so search engines can confirm which page in the set is "this one" — without it, the whole hreflang group can be interpreted as unreliable.',
            'hreflang_canonical_conflict' => 'hreflang says "this page is the right one for this locale" while canonical says "no, defer to this other page" — that\'s a direct contradiction search engines may resolve in either direction unpredictably.',
            'missing_title' => 'The title tag is one of the strongest on-page relevance signals and is almost always shown verbatim as the clickable headline in search results — a missing title hurts both rankings and click-through.',
            'title_too_long' => 'Search engines truncate long titles in the results page, which can cut off the part meant to entice a click.',
            'title_too_short' => 'A very short title rarely communicates enough about the page to either search engines or searchers deciding whether to click.',
            'missing_meta_description' => 'Without a meta description, search engines auto-generate a snippet from page text, which is frequently irrelevant or unappealing — hurting click-through even when the page ranks well.',
            'meta_description_too_long' => 'Search engines truncate long meta descriptions in results, which can cut the snippet off mid-sentence.',
            'missing_h1' => 'The H1 is the strongest heading-level signal of what the page is about, for both users skimming the page and search engines parsing its structure.',
            'multiple_h1' => 'Multiple H1s dilute the single clearest "this page is about X" signal a heading hierarchy is meant to provide.',
            'broken_heading_order' => 'A heading hierarchy that skips levels (e.g. H1 straight to H3) breaks the outline search engines and assistive technology rely on to understand page structure.',
            'missing_image_alt' => 'Alt text is required for screen readers (accessibility/legal exposure) and is also how search engines understand and index images for image search.',
            'missing_open_graph' => 'Without Open Graph tags, shared links render with no preview image/title/description on social platforms — links to the page look broken or unappealing when shared.',
            'missing_twitter_card' => 'Without Twitter Card tags, links shared on X/Twitter fall back to a bare URL instead of a rich preview, reducing click-through from social shares.',
            'missing_structured_data' => 'Structured data is what makes a page eligible for rich results (star ratings, FAQs, breadcrumbs, etc.) in search — without it, the listing looks plainer than competitors who have it.',
            'invalid_structured_data' => 'Malformed JSON-LD is silently ignored by search engines — the page loses any rich-result eligibility the structured data was meant to provide, with no visible error on the page itself.',
            'slow_response' => 'Slow-loading pages hurt user experience directly and are a known ranking factor — search engines also crawl slow sites less thoroughly since each fetch costs more of the crawl budget.',
            'mixed_content' => 'Browsers actively block or visibly warn on http resources loaded by an https page, which can break page functionality or show a "not secure" indicator to visitors.',
            'robots_blocked_important' => 'A robots.txt Disallow rule prevents search engines from crawling the page at all — far more severe than noindex, since the page can\'t even be evaluated for ranking.',
            'sitemap_broken_url' => 'A sitemap is a direct signal of "crawl and index this" — listing a URL that errors wastes crawl budget search engines could spend on real pages, and signals a poorly maintained sitemap.',
            'sitemap_redirect_url' => 'Listing a redirecting URL in the sitemap costs an extra hop for every crawl of that entry, for no benefit over listing the final destination directly.',
            'sitemap_noindex_url' => 'Listing a non-indexable page in the sitemap sends search engines a direct contradiction: "index this" vs. the page\'s own "don\'t index me."',
            'indexed_not_in_sitemap' => 'A page Google already indexes but that\'s missing from the sitemap relies entirely on other discovery paths (links, manual submission) — the sitemap should be the complete, authoritative list.',
            'crawl_blocked' => 'If the site itself blocks our crawler, every other check on it is unreliable or simply can\'t run — this is the most foundational issue to resolve first.',
            default => 'An issue detected during the site crawl that affects search visibility or user experience.',
        };
    }

    /**
     * Every open finding relevant to one page — both findings ABOUT it
     * (matched by affected_url_hash) and findings SOURCED from it
     * (broken_external/external_redirect, where affected_url is the
     * off-site target, not this page — those are matched by page_id
     * instead). Powers the Page Health panel: one unified "everything
     * wrong with this page" view instead of bouncing between single-issue
     * fix links that all used to land on the same context-free page.
     *
     * @return list<array<string,mixed>>
     */
    public function pageFindings(string $websiteId, string $url, string $pageId): array
    {
        $hash = WebsitePage::hashUrl($url);

        $rows = $this->findingsBase($websiteId)
            ->where(function (Builder $q) use ($hash, $pageId): void {
                $q->where('affected_url_hash', $hash)
                    ->orWhere(function (Builder $q2) use ($pageId): void {
                        $q2->where('page_id', $pageId)->whereIn('type', ['broken_external', 'external_redirect']);
                    });
            })
            ->with('page:id,url')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->get();

        return $rows->map(fn (CrawlFinding $f): array => [
            'type' => $f->type,
            'category' => $f->category,
            'severity' => $f->severity,
            'label' => $this->typeLabel($f->type),
            'description' => $this->describe($f),
            'guidance' => $this->fixGuidance($f->type),
            'detail' => $f->detail ?? [],
            'is_outbound' => in_array($f->type, ['broken_external', 'external_redirect'], true),
            'gsc_sourced' => $this->isGscSourced($f->type),
            'source_url' => $f->page?->url,
            'affected_url' => $f->affected_url,
        ])->all();
    }

    /**
     * Full sitewide audit, source data for the Site Audit PDF export. One row
     * per finding TYPE across every category (not scoped to one category like
     * typeBreakdown()), bucketed Errors/Warnings/Notices the way every major
     * audit tool (Semrush included) frames severity for a non-technical reader,
     * plus a "Start here" priority shortlist so the client isn't left to figure
     * out which of ~30 issue types to tackle first on their own.
     *
     * @return array<string,mixed>
     */
    public function auditExport(string $websiteId): array
    {
        return $this->remember('auditExport', $websiteId, fn (): array => $this->computeAuditExport($websiteId));
    }

    private function computeAuditExport(string $websiteId): array
    {
        $summary = $this->summary($websiteId);
        $cutoff = Carbon::now()->subDays(7);

        $rows = $this->findingsBase($websiteId)
            ->select('type', DB::raw('COUNT(*) as c'),
                DB::raw("SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as crit"),
                DB::raw("SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END) as high"),
                DB::raw("SUM(CASE WHEN severity='medium' THEN 1 ELSE 0 END) as med"))
            ->groupBy('type')->get();

        // "New since the last week" per type — the same trend signal Semrush's
        // own export shows (a delta sub-number under each issue's count) so a
        // recurring client report reads as "X new this week", not just a
        // restated total that looks unchanged.
        $newCounts = $this->findingsBase($websiteId)
            ->where('first_seen_at', '>=', $cutoff)
            ->select('type', DB::raw('COUNT(*) as c'))->groupBy('type')->pluck('c', 'type');

        $items = [];
        foreach ($rows as $r) {
            $sev = 'low';
            if ((int) $r->crit > 0) {
                $sev = 'critical';
            } elseif ((int) $r->high > 0) {
                $sev = 'high';
            } elseif ((int) $r->med > 0) {
                $sev = 'medium';
            }

            $samples = $this->findingsBase($websiteId)
                ->where('type', $r->type)->orderByDesc('id')->limit(10)->pluck('affected_url')->all();

            $items[] = [
                'type' => $r->type,
                'label' => $this->typeLabel($r->type),
                'count' => (int) $r->c,
                'new_count' => (int) ($newCounts[$r->type] ?? 0),
                'severity' => $sev,
                'about' => $this->auditAbout($r->type),
                'fix' => $this->fixGuidance($r->type),
                'gsc_sourced' => $this->isGscSourced($r->type),
                'sample_urls' => $samples,
            ];
        }

        $tier = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn (array $a, array $b): int => ($tier[$a['severity']] <=> $tier[$b['severity']]) ?: ($b['count'] <=> $a['count']));

        $errors = array_values(array_filter($items, fn (array $i): bool => in_array($i['severity'], ['critical', 'high'], true)));
        $warnings = array_values(array_filter($items, fn (array $i): bool => $i['severity'] === 'medium'));
        $notices = array_values(array_filter($items, fn (array $i): bool => $i['severity'] === 'low'));

        return [
            'health_score' => $summary['health_score'] ?? null,
            'health_grade' => $this->healthGrade($summary['health_score'] ?? null),
            'pages_crawled' => $summary['pages_total'] ?? null,
            'crawled_at' => $summary['last_crawled_at'] ?? null,
            // Top 5 highest-volume critical/high issues — a genuine shortlist,
            // not a restatement of Errors (capped well below its real count).
            'priority' => array_slice($errors, 0, 5),
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $notices,
            'total_issues' => array_sum(array_column($items, 'count')),
        ];
    }

    /** Plain letter grade for the health score — easier for a non-technical
     *  reader to anchor on than a bare 0–100 number. */
    private function healthGrade(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
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
