<?php

namespace App\Services\Reports;

use App\Models\Website;
use App\Services\ReportCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the one canonical report payload rendered identically by the web
 * share page and the PDF. Two responsibilities:
 *
 *  1. assemble() — turn raw DataForSEO + Moz provider results into the display
 *     payload (gauges, backlink profile, ratios, anchor-type buckets, tables).
 *     This is pure transformation and is what gets cached per-domain in
 *     website_report_snapshots.
 *
 *  2. withTraffic() — merge the per-website GSC/GA traffic strip onto a cached
 *     payload, but ONLY for an authed user viewing their own connected site
 *     (traffic is private and never stored in the shared snapshot).
 */
class ClientReportService
{
    private AuthorityScoreCalculator $scoreCalculator;

    private CcDomainRanks $ccRanks;

    public function __construct(?AuthorityScoreCalculator $scoreCalculator = null, ?CcDomainRanks $ccRanks = null)
    {
        $this->scoreCalculator = $scoreCalculator ?? new AuthorityScoreCalculator();
        $this->ccRanks = $ccRanks ?? new CcDomainRanks();
    }

    /**
     * Add Trust/Citation scores to a payload: stash the domain's Common Crawl
     * web-graph percentiles (no-op when the sidecar isn't imported), then run
     * the pure calculator. Skips everything when the payload already carries
     * current-version scores, so repeated reads cost nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scored(array $payload): array
    {
        // CC sidecar stashes only matter for a full recompute; the augment
        // call itself must run on EVERY read — it cheap-skips current-version
        // payloads but always refreshes the TopicSignal score, whose topical
        // inputs land batch-by-batch AFTER the scores were stamped. (Skipping
        // augment here entirely left TopicSignal permanently "—" on reports
        // whose scores were already current — bug found live 2026-07-16.)
        if ($this->scoreCalculator->needsAugment($payload)) {
            if (! isset($payload['cc']) && is_string($payload['domain'] ?? null)) {
                $cc = $this->ccRanks->scoreFor($payload['domain']);
                if ($cc !== null) {
                    $payload['cc'] = $cc;
                }
            }

            $payload = $this->stashRowCcRanks($payload);
        }

        // Risk interpretation layer: per-row toxicity flags + link_risk
        // summary (feeds the red warning panel + disavow export). Pure math
        // over payload data, recomputed on every read like TopicSignal.
        return (new BacklinkToxicityScorer)->analyze(
            $this->scoreCalculator->augment($payload),
        );
    }

    /**
     * Batch-stash CC web-graph percentiles onto every table row (referring
     * domains / backlink sources / competitors) so the calculator can emit
     * per-row Trust + Citation. One chunked local SQLite lookup for the whole
     * payload; no-op when the sidecar isn't imported.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stashRowCcRanks(array $payload): array
    {
        if (! $this->ccRanks->available()) {
            return $payload;
        }

        $domainOf = function ($row): string {
            if (! is_array($row)) {
                return '';
            }
            $d = strtolower(trim((string) ($row['domain'] ?? '')));
            if ($d !== '') {
                return $d;
            }

            return strtolower((string) parse_url((string) ($row['url_from'] ?? ''), PHP_URL_HOST));
        };

        $sections = ['top_referring_domains', 'backlinks', 'competitors'];
        $domains = [];
        foreach ($sections as $section) {
            foreach ((array) ($payload[$section] ?? []) as $row) {
                $d = $domainOf($row);
                if ($d !== '') {
                    $domains[$d] = true;
                }
            }
        }
        if ($domains === []) {
            return $payload;
        }

        $ranks = $this->ccRanks->lookupMany(array_keys($domains));
        foreach ($sections as $section) {
            if (! is_array($payload[$section] ?? null)) {
                continue;
            }
            foreach ($payload[$section] as $i => $row) {
                $cc = $ranks[$domainOf($row)] ?? null;
                if ($cc !== null) {
                    $payload[$section][$i]['cc_citation'] = $cc['citation_pct'];
                    $payload[$section][$i]['cc_trust'] = $cc['trust_pct'];
                }
            }
        }

        return $payload;
    }

    /**
     * We already FETCH up to `services.dataforseo.row_limit` (1000) rows per
     * endpoint — this used to display only 200 of them, silently dropping
     * data already paid for (e.g. a domain with 369 real referring domains
     * only showed 200). Matches the fetch cap so nothing paid-for is hidden;
     * the report tables scroll (`web-table.blade.php` `scroll` param) rather
     * than growing the page unboundedly.
     */
    private const DISPLAY_ROW_CAP = 1000;

    /**
     * Payload schema version, stamped into `meta.schema`. Bump whenever
     * assemble()/assemblePartial() start emitting a new section so the report
     * view can background-refresh snapshots that predate it (a fresh snapshot
     * is otherwise served as-is, without a freshness check — see
     * ReportViewController::resolve()). v2 (2026-07-15) added keywords,
     * keyword_opportunities, profile_details + rendered top_pages.
     */
    public const PAYLOAD_SCHEMA = 2;

    /**
     * Whether a stored payload was built by the CURRENT report schema. Old
     * payloads (no `meta.schema`) are treated as v1 → stale.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function isPayloadCurrent(?array $payload): bool
    {
        return (int) ($payload['meta']['schema'] ?? 1) >= self::PAYLOAD_SCHEMA;
    }

    /**
     * @param  array<string, mixed>  $raw  keys: summary, history, referring_domains, anchors, domain_pages, competitors, backlinks, moz
     * @return array<string, mixed>
     */
    public function assemble(string $domain, array $raw): array
    {
        $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];
        $moz = is_array($raw['moz'] ?? null) ? $raw['moz'] : [];
        $history = $this->historyRows(is_array($raw['history'] ?? null) ? $raw['history'] : []);

        $backlinksTotal = $this->int($summary['backlinks'] ?? null);
        $nofollow = $this->int($summary['referring_links_attributes']['nofollow'] ?? null) ?? 0;
        $dofollowPct = $backlinksTotal ? (int) round(100 * max(0, $backlinksTotal - $nofollow) / max(1, $backlinksTotal)) : null;

        $lostTotal = array_sum(array_column($history, 'lost'));
        $activeTotal = $backlinksTotal ?? array_sum(array_column($history, 'active'));
        $activePct = ($activeTotal + $lostTotal) > 0
            ? (int) round(100 * $activeTotal / ($activeTotal + $lostTotal))
            : null;

        $rank = $this->int($summary['rank'] ?? null);

        $opr = is_array($raw['opr'] ?? null) ? $raw['opr'] : [];
        $mainOpr = $opr[$this->target($domain)] ?? null;

        return $this->scored([
            'domain' => $domain,
            'popularity' => is_array($mainOpr) ? [
                'rank' => $mainOpr['rank'] ?? null,
                'score' => $mainOpr['score'] ?? null,
                'history' => $mainOpr['history'] ?? [],
            ] : null,
            'gauges' => [
                'domain_authority' => $this->clampScore($moz['domain_authority'] ?? null),
                'page_authority' => $this->clampScore($moz['page_authority'] ?? null),
                'spam_score' => $this->clampScore($moz['spam_score'] ?? ($summary['backlinks_spam_score'] ?? null)),
                'authority_score' => $rank !== null ? (int) round($rank / 10) : null,
            ],
            'totals' => [
                'backlinks' => $backlinksTotal,
                'referring_domains' => $this->int($summary['referring_domains'] ?? null),
                'referring_ips' => $this->int($summary['referring_ips'] ?? null),
                'referring_subnets' => $this->int($summary['referring_subnets'] ?? null),
            ],
            'ratios' => [
                'dofollow_pct' => $dofollowPct,
                'active_pct' => $activePct,
            ],
            'history' => $history,
            'anchor_types' => $this->classifyAnchors(
                is_array($raw['anchors'] ?? null) ? $raw['anchors'] : [],
                $domain,
            ),
            'top_referring_domains' => $this->topReferringDomains(
                is_array($raw['referring_domains'] ?? null) ? $raw['referring_domains'] : [],
                $opr,
            ),
            'anchors' => $this->topAnchors(is_array($raw['anchors'] ?? null) ? $raw['anchors'] : []),
            'backlinks' => $this->backlinkRows(is_array($raw['backlinks'] ?? null) ? $raw['backlinks'] : [], $opr),
            'top_pages' => $this->topPagesWithFallback(
                is_array($raw['domain_pages'] ?? null) ? $raw['domain_pages'] : [],
                is_array($raw['backlinks'] ?? null) ? $raw['backlinks'] : [],
            ),
            'competitors' => $this->competitorRows(is_array($raw['competitors'] ?? null) ? $raw['competitors'] : [], $domain, $opr),
            'profile_details' => $this->profileDetails($summary),
            'traffic' => null,
            'meta' => ['schema' => self::PAYLOAD_SCHEMA],
        ]);
    }

    /**
     * Link-profile breakdown from summary fields we already pay for but never
     * rendered before 2026-07-15: first-seen date, broken links, and the
     * TLD / country / platform / link-type distributions. Never includes any
     * $-value field (no money projections in the UI).
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>|null
     */
    private function profileDetails(array $summary): ?array
    {
        if ($summary === []) {
            return null;
        }

        $topMap = function ($map, int $cap = 10): array {
            if (! is_array($map) || $map === []) {
                return [];
            }
            $out = [];
            foreach ($map as $label => $count) {
                if (! is_numeric($count) || (int) $count <= 0) {
                    continue;
                }
                $out[] = ['label' => (string) $label, 'count' => (int) $count];
            }
            usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            return array_slice($out, 0, $cap);
        };

        $firstSeen = is_string($summary['first_seen'] ?? null) && $summary['first_seen'] !== ''
            ? substr($summary['first_seen'], 0, 10)
            : null;

        $details = [
            'first_seen' => $firstSeen,
            'crawled_pages' => $this->int($summary['crawled_pages'] ?? null),
            'broken_backlinks' => $this->int($summary['broken_backlinks'] ?? null),
            'broken_pages' => $this->int($summary['broken_pages'] ?? null),
            'tlds' => $topMap($summary['referring_links_tld'] ?? null),
            'countries' => $topMap($summary['referring_links_countries'] ?? null),
            'platform_types' => $topMap($summary['referring_links_platform_types'] ?? null),
            'link_types' => $topMap($summary['referring_links_types'] ?? null),
        ];

        $hasAny = $details['first_seen'] !== null
            || $details['broken_backlinks'] !== null
            || $details['tlds'] !== [] || $details['countries'] !== []
            || $details['platform_types'] !== [] || $details['link_types'] !== [];

        return $hasAny ? $details : null;
    }

    /**
     * Partial payload for a domain the backlink index knows nothing about
     * (young site). Same top-level shape as assemble() — every report-view
     * section guard keys off these — with backlink sections empty and the
     * enrichment sections filled in. `meta.sources` tags every section whose
     * data is NOT a direct measurement of the site itself, so the views can
     * badge it honestly.
     *
     * @param  array{opr?: ?array, moz?: ?array, keywords?: list<array<string, mixed>>,
     *               keyword_opportunities?: list<array<string, mixed>>,
     *               competitors?: list<array<string, mixed>>, opportunity_source?: ?string}  $raw
     * @return array<string, mixed>
     */
    public function assemblePartial(string $domain, array $raw): array
    {
        $opr = is_array($raw['opr'] ?? null) ? $raw['opr'] : null;
        $moz = is_array($raw['moz'] ?? null) ? $raw['moz'] : [];
        $competitors = array_values(array_filter($raw['competitors'] ?? [], 'is_array'));

        $sources = [];
        if (! empty($raw['keywords'])) {
            $sources['keywords'] = 'estimated';
        }
        if (! empty($raw['keyword_opportunities'])) {
            $sources['keyword_opportunities'] = 'similar_site';
        }
        if ($competitors !== []) {
            $sources['competitors'] = 'search_results';
        }

        return $this->scored([
            'domain' => $domain,
            'popularity' => $opr !== null ? [
                'rank' => $opr['rank'] ?? null,
                'score' => $opr['score'] ?? null,
                'history' => $opr['history'] ?? [],
            ] : null,
            'gauges' => [
                'domain_authority' => $this->clampScore($moz['domain_authority'] ?? null),
                'page_authority' => $this->clampScore($moz['page_authority'] ?? null),
                'spam_score' => $this->clampScore($moz['spam_score'] ?? null),
                'authority_score' => null,
            ],
            'totals' => [
                'backlinks' => null,
                'referring_domains' => null,
                'referring_ips' => null,
                'referring_subnets' => null,
            ],
            'ratios' => ['dofollow_pct' => null, 'active_pct' => null],
            'history' => [],
            'anchor_types' => ['branded' => 0, 'naked' => 0, 'generic' => 0, 'exact' => 0],
            'top_referring_domains' => [],
            'anchors' => [],
            'backlinks' => [],
            'top_pages' => [],
            'competitors' => $competitors,
            'profile_details' => null,
            'traffic' => null,
            'keywords' => array_values(array_filter($raw['keywords'] ?? [], 'is_array')),
            'keyword_opportunities' => array_values(array_filter($raw['keyword_opportunities'] ?? [], 'is_array')),
            'meta' => [
                'schema' => self::PAYLOAD_SCHEMA,
                'partial' => true,
                'opportunity_source' => $raw['opportunity_source'] ?? null,
                'sources' => $sources,
            ],
        ]);
    }

    /**
     * A realistic MOCK payload for the pre-signup blurred teaser. NO provider
     * call — purely illustrative numbers so the blurred page behind the signup
     * modal looks like a real report. Never persisted.
     *
     * @return array<string, mixed>
     */
    public function sampleTeaserPayload(string $domain): array
    {
        $months = ['2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01'];
        $history = [];
        $base = 15000;
        foreach ($months as $i => $m) {
            $history[] = [
                'month' => $m,
                'total' => $base + $i * 1800,
                'active' => 1200 + $i * 220,
                'lost' => 200 + $i * 20,
                'referring_domains' => 1200 + $i * 130,
            ];
        }

        return [
            'domain' => $domain,
            'popularity' => ['rank' => 1639043, 'score' => 4.7, 'history' => []],
            'gauges' => ['domain_authority' => 41, 'page_authority' => 38, 'spam_score' => 3, 'authority_score' => 47],
            'totals' => ['backlinks' => 24318, 'referring_domains' => 1842, 'referring_ips' => 1203, 'referring_subnets' => 890],
            'ratios' => ['dofollow_pct' => 22, 'active_pct' => 94],
            'history' => $history,
            'anchor_types' => ['branded' => 61, 'naked' => 19, 'generic' => 12, 'exact' => 8],
            'top_referring_domains' => [
                ['domain' => 'github.com', 'rank' => 96, 'backlinks' => 312, 'first_seen' => '2024-03', 'opr_score' => 9.2],
                ['domain' => 'reddit.com', 'rank' => 91, 'backlinks' => 204, 'first_seen' => '2024-05', 'opr_score' => 8.8],
                ['domain' => 'medium.com', 'rank' => 88, 'backlinks' => 176, 'first_seen' => '2024-08', 'opr_score' => 8.5],
                ['domain' => 'quora.com', 'rank' => 85, 'backlinks' => 143, 'first_seen' => '2025-01', 'opr_score' => 8.1],
                ['domain' => 'blogspot.com', 'rank' => 79, 'backlinks' => 128, 'first_seen' => '2025-02', 'opr_score' => 7.4],
            ],
            'anchors' => [
                ['anchor' => 'names for free fire', 'backlinks' => 5000, 'referring_domains' => 400, 'dofollow' => 1200],
                ['anchor' => 'stylish name generator', 'backlinks' => 2000, 'referring_domains' => 150, 'dofollow' => 900],
                ['anchor' => 'nickname maker', 'backlinks' => 1500, 'referring_domains' => 120, 'dofollow' => 700],
            ],
            'backlinks' => [
                ['url_from' => 'https://example-blog.com/best-name-tools', 'url_to' => 'https://'.$domain.'/', 'anchor' => 'names for free fire', 'dofollow' => true, 'rank' => 88, 'opr_score' => 6.1],
                ['url_from' => 'https://forum.example.net/thread/12', 'url_to' => 'https://'.$domain.'/generator', 'anchor' => 'nickname maker', 'dofollow' => false, 'rank' => 61, 'opr_score' => 4.3],
            ],
            'top_pages' => [],
            'competitors' => [
                ['domain' => 'competitor-one.com', 'intersections' => 486, 'avg_position' => 8.4, 'popularity_rank' => 842011, 'opr_score' => 5.6],
                ['domain' => 'competitor-two.com', 'intersections' => 312, 'avg_position' => 12.1, 'popularity_rank' => 1204553, 'opr_score' => 5.1],
            ],
            'traffic' => null,
        ];
    }

    /**
     * Merge the GSC/GA traffic strip onto a payload for an authed owner whose
     * site has GA+GSC connected. No-op (traffic stays null) otherwise.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withTraffic(array $payload, ?Website $website): array
    {
        // Backfill Trust/Citation scores onto payloads cached before the
        // scores existed (or built by an older formula version). Pure math
        // over data already in the payload (+ a local CC-sidecar lookup) —
        // no provider call, no schema bump, no snapshot write-back.
        // withTraffic() is the single read choke point (report view,
        // /backlinks, public share, PDF export), so every old snapshot gains
        // scores on first render.
        $payload = $this->scored($payload);

        if ($website === null || ! $website->hasGsc()) {
            return $payload;
        }

        // Both GSC aggregates (traffic strip + top queries) are heavy GROUP BYs
        // over search_console_data — on a large site the 30-day window can be
        // 500k+ rows and take tens of seconds. Compute BOTH once and cache them
        // together, version-keyed so a GSC sync busts it (mirrors
        // ReportDataService's cached aggregates). Without this the report page
        // re-ran the aggregation on every view.
        $bundle = $this->gscBundle($website);

        $payload['traffic'] = $bundle['traffic'];

        // A GSC-connected site gets its REAL Search Console queries instead of
        // the keyword-planner estimates. Merged at render time only (like the
        // traffic strip) — GSC data is private and must never be written into
        // the cross-tenant shared snapshot.
        if (! empty($bundle['keywords'])) {
            $payload['keywords'] = $bundle['keywords'];
            $payload['meta'] = array_merge($payload['meta'] ?? [], [
                'sources' => array_merge($payload['meta']['sources'] ?? [], ['keywords' => 'gsc']),
            ]);
        }

        return $payload;
    }

    /**
     * The cached per-website GSC merge (traffic strip + top queries). Keyed by
     * ReportCache::version so a new sync invalidates it; 24h TTL as a backstop.
     *
     * @return array{traffic: ?array<string, mixed>, keywords: list<array<string, mixed>>}
     */
    private function gscBundle(Website $website): array
    {
        $key = 'client-report-gsc:'.$website->id.':'.ReportCache::version((string) $website->id);

        return Cache::remember($key, now()->addHours(24), fn (): array => [
            'traffic' => $this->trafficFor($website),
            'keywords' => $this->gscTopQueries($website),
        ]);
    }

    /**
     * The site's top real queries from Search Console (last 30 days, by
     * clicks then impressions). Render-time only — never persisted to the
     * shared snapshot.
     *
     * @return list<array<string, mixed>>
     */
    private function gscTopQueries(Website $website): array
    {
        try {
            $end = now()->subDay()->toDateString();
            $start = now()->subDays(30)->toDateString();

            return DB::table('search_console_data')
                ->where('website_id', $website->id)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('query, COALESCE(SUM(clicks),0) as clicks, COALESCE(SUM(impressions),0) as impressions, COALESCE(AVG(position),0) as position')
                ->groupBy('query')
                ->orderByDesc(DB::raw('SUM(clicks)'))
                ->orderByDesc(DB::raw('SUM(impressions)'))
                ->limit(max(1, (int) config('services.report.enrichment.keyword_rows', 100)))
                ->get()
                ->map(fn ($r) => [
                    'keyword' => (string) $r->query,
                    'clicks' => (int) $r->clicks,
                    'impressions' => (int) $r->impressions,
                    'position' => round((float) $r->position, 1),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Denormalized headline metrics to store alongside the payload JSON for
     * cheap list/sort without decoding.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int|null>
     */
    public function headlineColumns(array $payload): array
    {
        return [
            'domain_authority' => $payload['gauges']['domain_authority'] ?? null,
            'page_authority' => $payload['gauges']['page_authority'] ?? null,
            'spam_score' => $payload['gauges']['spam_score'] ?? null,
            'rank' => isset($payload['gauges']['authority_score']) ? (int) $payload['gauges']['authority_score'] * 10 : null,
            'referring_domains' => $payload['totals']['referring_domains'] ?? null,
            'backlinks_total' => $payload['totals']['backlinks'] ?? null,
        ];
    }

    /**
     * Branded / naked-URL / generic / exact-ish buckets as backlink-weighted
     * percentages. DataForSEO gives anchor strings + counts; the bucketing is
     * ours (compare anchor to the brand + domain).
     *
     * @param  list<array<string, mixed>>  $anchors
     * @return array{branded:int, naked:int, generic:int, exact:int}
     */
    public function classifyAnchors(array $anchors, string $domain): array
    {
        $brandCompact = preg_replace('/[^a-z0-9]/', '', $this->brandToken($domain));
        $hostCompact = preg_replace('/[^a-z0-9]/', '', $this->target($domain));
        $buckets = ['branded' => 0, 'naked' => 0, 'generic' => 0, 'exact' => 0];
        $total = 0;

        $generic = ['click here', 'here', 'read more', 'more', 'website', 'link', 'visit', 'this site', 'homepage', 'home'];

        foreach ($anchors as $row) {
            $text = strtolower(trim((string) ($row['anchor'] ?? '')));
            $compact = preg_replace('/[^a-z0-9]/', '', $text);
            $weight = $this->int($row['backlinks'] ?? null) ?? 1;
            $total += $weight;

            if ($text === '' || in_array($text, $generic, true)) {
                $buckets['generic'] += $weight;
            } elseif (str_contains($text, '://') || str_contains($text, 'www.') || ($hostCompact !== '' && str_contains($compact, $hostCompact))) {
                $buckets['naked'] += $weight;
            } elseif ($brandCompact !== '' && str_contains($compact, $brandCompact)) {
                $buckets['branded'] += $weight;
            } else {
                $buckets['exact'] += $weight;
            }
        }

        if ($total === 0) {
            return $buckets;
        }

        foreach ($buckets as $k => $v) {
            $buckets[$k] = (int) round(100 * $v / $total);
        }

        return $buckets;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function historyRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $year = $this->int($row['year'] ?? null);
            $month = $this->int($row['month'] ?? null);
            $label = ($year && $month) ? sprintf('%04d-%02d', $year, $month) : (string) ($row['date'] ?? '');
            $out[] = [
                'month' => $label,
                'total' => $this->int($row['backlinks'] ?? null) ?? 0,
                'active' => $this->int($row['new_backlinks'] ?? null) ?? 0,
                'lost' => $this->int($row['lost_backlinks'] ?? null) ?? 0,
                'referring_domains' => $this->int($row['referring_domains'] ?? null) ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function topReferringDomains(array $rows, array $opr = []): array
    {
        return array_map(function ($r) use ($opr) {
            $domain = (string) ($r['domain'] ?? '');

            return [
                'domain' => $domain,
                'rank' => $this->int($r['rank'] ?? null),
                'backlinks' => $this->int($r['backlinks'] ?? null),
                'first_seen' => $this->monthOf($r['first_seen'] ?? null),
                'opr_score' => $opr[$this->target($domain)]['score'] ?? null,
            ];
        }, array_slice($rows, 0, self::DISPLAY_ROW_CAP));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function topAnchors(array $rows): array
    {
        return array_map(fn ($r) => [
            'anchor' => (string) ($r['anchor'] ?? ''),
            'backlinks' => $this->int($r['backlinks'] ?? null),
            'referring_domains' => $this->int($r['referring_domains'] ?? null),
            'dofollow' => $this->int($r['dofollow'] ?? null),
        ], array_slice($rows, 0, self::DISPLAY_ROW_CAP));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    /**
     * Top pages from the paid `domain_pages` endpoint; when it returns nothing
     * (common for small domains — was the case for daomarketing.com), derive
     * them from the backlink sample by counting how many backlinks point at
     * each target page. Better than an empty "Top pages by backlinks" section.
     *
     * @param  list<array<string, mixed>>  $domainPages
     * @param  list<array<string, mixed>>  $backlinks
     * @return list<array<string, mixed>>
     */
    private function topPagesWithFallback(array $domainPages, array $backlinks): array
    {
        $pages = $this->topPages($domainPages);
        if ($pages !== []) {
            return $pages;
        }

        return $this->deriveTopPagesFromBacklinks($backlinks);
    }

    /**
     * Derive "top pages by backlinks" from a backlink list (raw or the stored
     * `payload['backlinks']` rows — both carry `url_to`) by counting how many
     * point at each target page. Used as the fallback when DataForSEO's
     * `domain_pages` is empty, and by the enrichment backfill to fill the
     * section on an already-cached report without a costly regeneration.
     *
     * @param  list<array<string, mixed>>  $backlinks
     * @return list<array<string, mixed>>
     */
    public function deriveTopPagesFromBacklinks(array $backlinks): array
    {
        $tally = [];
        foreach ($backlinks as $b) {
            $target = trim((string) ($b['url_to'] ?? ''));
            if ($target === '') {
                continue;
            }
            $tally[$target] ??= 0;
            $tally[$target]++;
        }
        if ($tally === []) {
            return [];
        }

        arsort($tally);
        $out = [];
        foreach (array_slice($tally, 0, 15, true) as $url => $count) {
            $out[] = [
                'url' => $url,
                'referring_domains' => null, // not derivable from the sample
                'backlinks' => $count,
            ];
        }

        return $out;
    }

    private function topPages(array $rows): array
    {
        // DataForSEO nests the actual metrics under `page_summary` — the top
        // level only carries crawl metadata (status_code, server, etc). Prior
        // code read `referring_domains`/`backlinks` off the top level, which
        // are always absent there, so this table rendered real URLs with
        // permanently-null counts and no meaningful order.
        $mapped = array_map(function ($r) {
            $summary = is_array($r['page_summary'] ?? null) ? $r['page_summary'] : [];

            return [
                'url' => (string) ($r['page'] ?? ($r['url'] ?? '')),
                'referring_domains' => $this->int($summary['referring_domains'] ?? ($r['referring_domains'] ?? null)),
                'backlinks' => $this->int($summary['backlinks'] ?? ($r['backlinks'] ?? null)),
            ];
        }, $rows);

        usort($mapped, fn ($a, $b) => ($b['referring_domains'] ?? 0) <=> ($a['referring_domains'] ?? 0));

        return array_slice($mapped, 0, 15);
    }

    /**
     * Individual backlinks — clickable source page, target, anchor, dofollow.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function backlinkRows(array $rows, array $opr = []): array
    {
        $out = [];
        foreach (array_slice($rows, 0, self::DISPLAY_ROW_CAP) as $r) {
            $urlFrom = (string) ($r['url_from'] ?? '');
            if ($urlFrom === '') {
                continue;
            }
            $sourceHost = $this->target((string) ($r['domain_from'] ?? $urlFrom));
            $score = $opr[$sourceHost]['score']
                ?? ($opr[\App\Services\OpenPageRankClient::registrable($sourceHost)]['score'] ?? null);
            $out[] = [
                'url_from' => $urlFrom,
                'url_to' => (string) ($r['url_to'] ?? ''),
                'anchor' => (string) ($r['anchor'] ?? ''),
                'dofollow' => (bool) ($r['dofollow'] ?? false),
                'rank' => $this->int($r['domain_from_rank'] ?? ($r['page_from_rank'] ?? null)),
                'opr_score' => $score,
            ];
        }

        return $out;
    }

    /**
     * Organic SERP competitors (DataForSEO Labs `competitors_domain`): domains
     * ranking for the same keywords. Each item → domain, shared keywords
     * (`intersections`), avg organic position. Filters out the target itself
     * and mega-platforms (YouTube/Google/social) that share keywords with
     * everyone but aren't real competitors. Falls back to the backlinks-endpoint
     * shape (`target`/`rank`) when Labs wasn't used.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function competitorRows(array $rows, string $target, array $opr = []): array
    {
        $self = $this->target($target);
        $skip = ['youtube.com', 'google.com', 'instagram.com', 'facebook.com', 'twitter.com', 'x.com',
            'pinterest.com', 'tiktok.com', 'wikipedia.org', 'reddit.com', 'amazon.com', 'linkedin.com',
            'apple.com', 'microsoft.com', 'play.google.com'];

        $out = [];
        foreach ($rows as $r) {
            $domain = strtolower((string) ($r['domain'] ?? ($r['target'] ?? '')));
            if ($domain === '' || $domain === $self || in_array($domain, $skip, true)) {
                continue;
            }
            $m = $opr[$this->target($domain)] ?? null;
            $out[] = [
                'domain' => $domain,
                'shared_keywords' => $this->int($r['intersections'] ?? ($r['full_domain_intersection'] ?? null)),
                'avg_position' => isset($r['avg_position']) && is_numeric($r['avg_position']) ? round((float) $r['avg_position'], 1) : null,
                // Labs rows carry full metrics — organic keyword count is paid
                // for anyway; surface it. NEVER surface `etv` (a $ projection).
                'organic_keywords' => $this->int($r['metrics']['organic']['count'] ?? null),
                'popularity_rank' => is_array($m) ? ($m['rank'] ?? null) : null,
                'opr_score' => is_array($m) ? ($m['score'] ?? null) : null,
            ];
        }

        // Explicit sort rather than trusting API order — with the row cap now
        // pulling many more candidates, the true top results by relevance
        // should win regardless of where DataForSEO placed them in the response.
        usort($out, fn ($a, $b) => ($b['shared_keywords'] ?? 0) <=> ($a['shared_keywords'] ?? 0));

        return array_slice($out, 0, self::DISPLAY_ROW_CAP);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function trafficFor(Website $website): ?array
    {
        try {
            $end = now()->subDay()->toDateString();
            $start = now()->subDays(30)->toDateString();

            $gsc = DB::table('search_console_data')
                ->where('website_id', $website->id)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('COALESCE(SUM(clicks),0) as clicks, COALESCE(SUM(impressions),0) as impressions, COALESCE(AVG(position),0) as position, COUNT(DISTINCT query) as keywords')
                ->first();

            if ($gsc === null) {
                return null;
            }

            return [
                'clicks' => (int) $gsc->clicks,
                'impressions' => (int) $gsc->impressions,
                'avg_position' => round((float) $gsc->position, 1),
                'keywords' => (int) $gsc->keywords,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function brandToken(string $domain): string
    {
        $host = $this->target($domain);
        $sld = explode('.', $host)[0] ?? $host;

        return strlen($sld) >= 3 ? $sld : '';
    }

    private function target(string $domain): string
    {
        $forParse = str_contains($domain, '://') ? $domain : 'https://'.$domain;
        $host = parse_url($forParse, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? strtolower($host) : strtolower(trim($domain));

        return preg_replace('/^www\./', '', $host) ?: $host;
    }

    private function monthOf(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    /**
     * A 0-100 authority/spam score for the tinyint columns. Non-numeric or
     * out-of-range (some providers/sandbox return -1) → null.
     */
    private function clampScore(mixed $v): ?int
    {
        if (! is_numeric($v)) {
            return null;
        }
        $n = (int) round((float) $v);

        return ($n < 0 || $n > 100) ? null : $n;
    }
}
