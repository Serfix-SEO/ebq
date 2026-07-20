<?php

namespace App\Services\Content;

use App\Jobs\GenerateWebsiteReport;
use App\Models\ContentPlan;
use App\Models\DomainMetric;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\MozLinksClient;
use App\Services\Reports\ClientReportService;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Support\Facades\Cache;

/**
 * "Your competitors and their authority" data for the Content Autopilot setup
 * wizard, modeled on the reference: your referring domains vs competitor
 * median + gap, plus a limited top-competitor table.
 *
 * Data flow (one-time per site, then cached 30 days):
 *  1. Read the shared report snapshot (WebsiteReportSnapshot::forDomain) — the
 *     site's OWN referring domains + Trust/Citation + the discovered competitor
 *     list. Read-only, no paid call.
 *  2. Enrich each competitor's referring-domains + backlinks total via the
 *     SAME DataForSEO `/backlinks/summary/live` call used for the site's own
 *     domain ({@see DataForSeoBacklinkClient::summary()}) — matters because
 *     the site's own referring-domains number already comes from DataForSEO;
 *     an earlier version enriched competitors via free OpenPageRank instead,
 *     which undercounted referring domains 10-100x vs this real index (e.g.
 *     one real competitor: OPR said 90, DataForSEO says ~5,800) and made the
 *     median/gap comparison badly wrong — sometimes showing the client AHEAD
 *     when they were actually far behind. Stored on the shared `domain_metrics`
 *     asset (`dfs_referring_domains`/`dfs_backlinks`/`dfs_refreshed_at`, 30-day
 *     freshness), guarded by the app-wide {@see DataForSeoSpendMeter}.
 *  3. Enrich each competitor's Moz DA/PA ({@see MozLinksClient}), also stored
 *     on `domain_metrics` (`moz_da`/`moz_pa`/`moz_refreshed_at`) — the SAME
 *     global per-domain table other subsystems read (backlinks, prospecting,
 *     etc.), so a domain touched once anywhere is never re-fetched elsewhere
 *     within 30 days. Guarded by {@see MozSpendMeter} — the account is
 *     free-tier (50 rows/month total), so this must stay small.
 *  4. If no usable snapshot exists yet, {@see ensureGenerating()} dispatches
 *     the standard paid report generation ONCE (spend-metered; sandbox on
 *     staging) and the wizard polls until it lands.
 *
 * The user can also manually add/remove competitor domains from the wizard;
 * those are stored on `ContentPlan::competitor_overrides` and merged on top
 * of this class's output by {@see withOverrides()} — never written into the
 * cached snapshot above.
 */
class ContentSetupInsights
{
    private const CACHE_TTL_DAYS = 30;

    private const MAX_COMPETITORS = 5;

    public function __construct(
        private readonly ClientReportService $reports,
        private readonly DataForSeoBacklinkClient $dfs,
        private readonly DataForSeoSpendMeter $dfsSpend,
        private readonly MozLinksClient $moz,
        private readonly MozSpendMeter $mozSpend,
    ) {}

    /**
     * @return array{
     *   my_referring_domains:int, my_authority:?int,
     *   competitors:list<array{domain:string, referring_domains:?int, backlinks:?int, authority:?int, da:?int, pa:?int}>,
     *   median:?int, gap:?float, behind:bool
     * }|null  null when no usable snapshot exists yet (caller should generate)
     */
    public function competitorAuthority(Website $website): ?array
    {
        return Cache::remember(
            'content:setup-insights:v1:'.$website->id,
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->build($website)
        );
    }

    /** True when the wizard should show a "generating" state + poll. */
    public function isGenerating(Website $website): bool
    {
        if ($this->competitorAuthority($website) !== null) {
            return false;
        }
        // SERP competitor discovery still running → keep polling.
        if (Cache::has('content:serp-comp:'.$website->id)) {
            return true;
        }
        if (! Cache::has('content:comp-gen:'.$website->id)) {
            return false;
        }
        // The lock lives 30 min, but generation is really over once the report
        // has FINALIZED (partial/ready/no_data). Without this, a low-authority
        // site whose snapshot has no competitors would spin the whole 30 min.
        $snap = WebsiteReportSnapshot::forDomain($website->normalized_domain ?: $website->domain);

        return $snap === null || $snap->status === 'enriching';
    }

    /**
     * Kick off a one-time real report generation for the site's own domain so
     * the competitor data exists. Guarded so it fires at most once per 30 min.
     */
    public function ensureGenerating(Website $website): void
    {
        if ($this->competitorAuthority($website) !== null) {
            return; // already have data
        }
        $domain = $website->normalized_domain ?: $website->domain;

        // (1) Backlink report — FORCE the paid attempt (a "partial"/young-site
        // snapshot may already exist from the free-feed path, which the freshness
        // gate treats as fresh and would skip). Cache::add = atomic once-per-30min.
        if (Cache::add('content:comp-gen:'.$website->id, 1, now()->addMinutes(30))) {
            // sandbox for admins (+ forced on staging via env); non-admins bill
            // real spend, which the report pipeline meters.
            $sandbox = (bool) $website->user?->is_admin;
            GenerateWebsiteReport::dispatch($domain, true, $sandbox);
        }

        // (2) SERP competitor discovery from the content plan's target keywords —
        // finds competitors for a fresh site with no backlink footprint. Async so
        // it never blocks the wizard render; the flag drives the polling state.
        if (Cache::add('content:serp-comp:'.$website->id, 1, now()->addMinutes(30))) {
            \App\Jobs\Content\DiscoverContentCompetitorsJob::dispatch($website->id);
        }
    }

    /** Clear the cache so a fresh snapshot is re-read (called after generation). */
    public function forget(Website $website): void
    {
        Cache::forget('content:setup-insights:v1:'.$website->id);
    }

    /**
     * A single manually-added competitor's row (DataForSEO referring domains
     * + backlinks, Moz DA/PA), fetched live — the manual list is small and
     * user-controlled, so it isn't worth folding into the 30-day cached
     * snapshot build.
     *
     * @return array{domain:string, referring_domains:?int, backlinks:?int, authority:?int, da:?int, pa:?int, manual:true}
     */
    public function metricsForDomain(string $domain, bool $sandbox = false): array
    {
        $domain = trim($domain);
        $dfs = $this->dfsMetrics($domain, $sandbox);
        $moz = $this->mozMetrics($domain);

        return [
            'domain' => $domain,
            'referring_domains' => $dfs['referring_domains'],
            'backlinks' => $dfs['backlinks'],
            'authority' => null,
            'da' => $moz['domain_authority'],
            'pa' => $moz['page_authority'],
            'manual' => true,
        ];
    }

    /**
     * Apply the plan's manual competitor add/remove overrides on top of the
     * derived/cached insights. Never mutates the 30-day insights cache
     * itself — adds are fetched live and removes just filter, each call.
     * Works even when the base insights are still null (no report snapshot
     * yet) so a manually-added competitor shows immediately.
     */
    public function withOverrides(?array $insights, ContentPlan $plan): ?array
    {
        // Same admin-sandboxes / non-admin-bills-real-money policy as the
        // report generation path (ensureGenerating()) — never bill an
        // admin's own testing.
        $sandbox = (bool) $plan->website?->user?->is_admin;
        $overrides = (array) ($plan->competitor_overrides ?? []);
        $removed = array_values(array_unique(array_map(
            static fn ($d) => strtolower(trim((string) $d)), (array) ($overrides['removed'] ?? [])
        )));
        $added = array_values(array_diff(array_unique(array_map(
            static fn ($d) => strtolower(trim((string) $d)), (array) ($overrides['added'] ?? [])
        )), $removed));

        if ($insights === null && $added === []) {
            return $insights;
        }
        $insights ??= [
            'my_referring_domains' => 0, 'my_authority' => null,
            'competitors' => [], 'median' => null, 'gap' => null, 'behind' => false,
        ];

        if ($removed !== []) {
            $insights['competitors'] = array_values(array_filter(
                $insights['competitors'],
                static fn ($c) => ! in_array(strtolower($c['domain']), $removed, true)
            ));
        }

        $existing = array_map(static fn ($c) => strtolower($c['domain']), $insights['competitors']);
        foreach ($added as $domain) {
            if ($domain === '' || in_array($domain, $existing, true)) {
                continue;
            }
            $insights['competitors'][] = $this->metricsForDomain($domain, $sandbox);
            $existing[] = $domain;
        }

        return $insights;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function normalizeHost(string $domain): string
    {
        return strtolower(preg_replace('/^www\./', '', (string) (
            parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST) ?: $domain
        )));
    }

    /**
     * Referring domains + backlinks total for one domain, from the SAME
     * DataForSEO endpoint (`/backlinks/summary/live`, $0.024/request) used
     * for the site's own numbers elsewhere in the app — deliberately NOT a
     * free proxy (OpenPageRank), because mixing an accurate paid figure for
     * "your referring domains" against an undercounted free figure for
     * competitors made the median/gap comparison misleading. 30-day cached
     * on the shared `domain_metrics` asset; guarded by the app-wide
     * {@see DataForSeoSpendMeter} (same breaker every other paid DFS call
     * respects). A domain DataForSEO has no data for (very small/obscure
     * sites) legitimately returns null — shown as "—", not backfilled from
     * a weaker source.
     *
     * @param  bool  $sandbox  Admin-owned site → route to DataForSEO's free
     *                         mock host (same policy as ensureGenerating()),
     *                         and never persist the mock response into the
     *                         shared domain_metrics asset — that table must
     *                         only ever hold real data other users rely on.
     * @return array{referring_domains:?int, backlinks:?int}
     */
    private function dfsMetrics(string $domain, bool $sandbox = false): array
    {
        $empty = ['referring_domains' => null, 'backlinks' => null];

        $host = $this->normalizeHost($domain);
        if ($host === '') {
            return $empty;
        }

        $existing = DomainMetric::query()->where('domain', $host)->first();
        $fresh = $existing?->dfs_refreshed_at !== null
            && $existing->dfs_refreshed_at->gt(now()->subDays(self::CACHE_TTL_DAYS));
        if ($fresh) {
            return ['referring_domains' => $existing->dfs_referring_domains, 'backlinks' => $existing->dfs_backlinks];
        }

        $stale = $existing !== null
            ? ['referring_domains' => $existing->dfs_referring_domains, 'backlinks' => $existing->dfs_backlinks]
            : $empty;

        if (! $this->dfs->isConfigured() || (! $sandbox && $this->dfsSpend->exhausted())) {
            return $stale;
        }

        $this->dfs->resetCost();
        $summary = $this->dfs->useSandbox($sandbox)->summary($domain);
        $this->dfs->useSandbox(false); // reset — this client instance may be reused elsewhere this request

        $referring = isset($summary['referring_domains']) ? (int) $summary['referring_domains'] : null;
        $backlinks = isset($summary['backlinks']) ? (int) $summary['backlinks'] : null;

        if ($sandbox) {
            return ['referring_domains' => $referring, 'backlinks' => $backlinks]; // mock data — never cached/billed
        }

        $this->dfsSpend->add($this->dfs->totalCost());

        // Global asset — any subsystem touching this domain reads the same
        // fresh DataForSEO value for 30 days instead of re-billing.
        DomainMetric::query()->updateOrCreate(
            ['domain' => $host],
            [
                'dfs_referring_domains' => $referring,
                'dfs_backlinks' => $backlinks,
                'dfs_refreshed_at' => now(),
                'last_seen_at' => now(),
                'first_seen_at' => $existing?->first_seen_at ?? now(),
            ]
        );

        return ['referring_domains' => $referring, 'backlinks' => $backlinks];
    }

    /**
     * DA/PA for one domain, 30-day cached per-domain (independent of the
     * insights cache so manual adds/removes don't cost extra Moz calls).
     * Guarded by the free-tier row cap; never throws, never blocks the page.
     *
     * @return array{domain_authority:?int, page_authority:?int}
     */
    private function mozMetrics(string $domain): array
    {
        $empty = ['domain_authority' => null, 'page_authority' => null];

        $host = $this->normalizeHost($domain);
        if ($host === '') {
            return $empty;
        }

        $existing = DomainMetric::query()->where('domain', $host)->first();
        $fresh = $existing?->moz_refreshed_at !== null
            && $existing->moz_refreshed_at->gt(now()->subDays(self::CACHE_TTL_DAYS));
        if ($fresh) {
            return ['domain_authority' => $existing->moz_da, 'page_authority' => $existing->moz_pa];
        }

        $stale = $existing !== null
            ? ['domain_authority' => $existing->moz_da, 'page_authority' => $existing->moz_pa]
            : $empty;

        if (! $this->moz->isConfigured() || $this->mozSpend->exhausted()) {
            return $stale; // better than nothing; don't record — retry later
        }

        $metrics = $this->moz->urlMetrics($domain) ?? [];
        $this->mozSpend->add(1);
        $da = $metrics['domain_authority'] ?? null;
        $pa = $metrics['page_authority'] ?? null;

        // Global asset (like domain_metrics' CC/OPR columns) — any subsystem
        // touching this domain reads the same fresh Moz value for 30 days.
        DomainMetric::query()->updateOrCreate(
            ['domain' => $host],
            [
                'moz_da' => $da,
                'moz_pa' => $pa,
                'moz_refreshed_at' => now(),
                'last_seen_at' => now(),
                'first_seen_at' => $existing?->first_seen_at ?? now(),
            ]
        );

        return ['domain_authority' => $da, 'page_authority' => $pa];
    }

    private function build(Website $website): ?array
    {
        $domain = $website->normalized_domain ?: $website->domain;
        if (! $domain) {
            return null;
        }

        try {
            $snapshot = WebsiteReportSnapshot::forDomain($domain);
        } catch (\Throwable) {
            return null;
        }
        if ($snapshot === null || empty($snapshot->payload)) {
            return null;
        }

        try {
            $payload = $this->reports->withTraffic($snapshot->payload, $website);
        } catch (\Throwable) {
            return null;
        }

        $competitorRows = array_values(array_filter((array) ($payload['competitors'] ?? []), 'is_array'));
        $myReferring = (int) ($payload['totals']['referring_domains'] ?? $snapshot->referring_domains ?? 0);

        // Fresh / low-authority site: the backlink report finds no competitors.
        // Fall back to SERP competitors discovered ASYNC by
        // DiscoverContentCompetitorsJob (the content plan's TARGET keywords are
        // genuine searches, so whoever ranks for them is a real competitor even
        // when the site has no backlink footprint yet). Read-only here — never
        // run SERP inline, or the step's 5s poll would re-bill it every tick.
        if ($competitorRows === []) {
            $competitorRows = array_values(array_filter(
                (array) Cache::get('content:serp-competitors:'.$website->id, []),
                'is_array'
            ));
        }

        // Still nothing AND no own referring domains → signal "generate a report".
        if ($competitorRows === [] && $myReferring === 0) {
            return null;
        }

        $myAuthority = isset($payload['scores']['citation']) ? (int) $payload['scores']['citation'] : null;

        // Same admin-sandboxes / non-admin-bills-real-money policy as
        // ensureGenerating() — never bill an admin's own testing.
        $sandbox = (bool) $website->user?->is_admin;

        // Top competitors by shared keywords, capped.
        usort($competitorRows, fn ($a, $b) => (int) ($b['shared_keywords'] ?? 0) <=> (int) ($a['shared_keywords'] ?? 0));
        $competitorRows = array_slice($competitorRows, 0, self::MAX_COMPETITORS);

        // A fresh site (no own referring domains) shows just the competitor LIST —
        // the DA/PA/backlink columns and the authority comparison are hidden in
        // the UI, so skip the per-competitor DataForSEO + Moz lookups entirely
        // (no wasted spend).
        $fresh = $myReferring < 1;

        $competitors = [];
        foreach ($competitorRows as $c) {
            $cd = trim((string) ($c['domain'] ?? ''));
            if ($cd === '' || $cd === $domain) {
                continue;
            }
            $dfs = $fresh ? ['referring_domains' => null, 'backlinks' => null] : $this->dfsMetrics($cd, $sandbox);
            $moz = $fresh ? ['domain_authority' => null, 'page_authority' => null] : $this->mozMetrics($cd);
            $competitors[] = [
                'domain' => $cd,
                'referring_domains' => $dfs['referring_domains'],
                'backlinks' => $dfs['backlinks'],
                'authority' => isset($c['cs']) ? (int) $c['cs'] : null,
                'da' => $moz['domain_authority'],
                'pa' => $moz['page_authority'],
            ];
        }

        // Fire a ONE-TIME batched DataForSEO Labs traffic-estimation for all of
        // this plan's competitor domains → stored on the shared domain_metrics
        // asset (dfs_metrics) for the "monthly searches" teaser + future reuse.
        // build() only runs on a 30-day cache miss, so this dispatches at most
        // once per freshness window — no per-poll re-billing.
        $competitorDomains = array_values(array_filter(array_map(
            static fn ($c) => (string) ($c['domain'] ?? ''), $competitors
        )));
        if ($competitorDomains !== []) {
            \App\Jobs\Content\EnrichCompetitorDomainMetricsJob::dispatch($website->id, $competitorDomains);
        }

        // Rank strongest referring-domains first for the table.
        usort($competitors, fn ($a, $b) => ($b['referring_domains'] ?? -1) <=> ($a['referring_domains'] ?? -1));

        $refs = array_values(array_filter(array_column($competitors, 'referring_domains'), 'is_int'));
        sort($refs);
        $n = count($refs);
        $median = $n === 0 ? null
            : ($n % 2 ? (int) $refs[intdiv($n, 2)] : (int) round(($refs[$n / 2 - 1] + $refs[$n / 2]) / 2));

        // Gap as a multiplier (like the reference's "13.6x"): how many times
        // more referring domains the median competitor has than you.
        $gap = ($median !== null && $median > 0 && $myReferring > 0)
            ? round($median / max(1, $myReferring), 1) : null;

        return [
            'my_referring_domains' => $myReferring,
            'my_authority' => $myAuthority,
            'competitors' => $competitors,
            'median' => $median,
            'gap' => $gap,
            'behind' => $median !== null && $median > $myReferring,
        ];
    }
}
