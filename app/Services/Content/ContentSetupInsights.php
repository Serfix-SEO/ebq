<?php

namespace App\Services\Content;

use App\Jobs\GenerateWebsiteReport;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\OpenPageRankClient;
use App\Services\Reports\ClientReportService;
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
 *  2. Enrich each competitor's referring-domains count via OpenPageRank
 *     (FREE bulk endpoint, 100 domains/call) — the snapshot's competitor rows
 *     don't carry referring_domains.
 *  3. If no usable snapshot exists yet, {@see ensureGenerating()} dispatches
 *     the standard paid report generation ONCE (spend-metered; sandbox on
 *     staging) and the wizard polls until it lands.
 */
class ContentSetupInsights
{
    private const CACHE_TTL_DAYS = 30;

    private const MAX_COMPETITORS = 5;

    public function __construct(
        private readonly ClientReportService $reports,
        private readonly OpenPageRankClient $opr,
    ) {}

    /**
     * @return array{
     *   my_referring_domains:int, my_authority:?int,
     *   competitors:list<array{domain:string, referring_domains:?int, authority:?int}>,
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
        return Cache::has('content:comp-gen:'.$website->id)
            && $this->competitorAuthority($website) === null;
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
        $lock = 'content:comp-gen:'.$website->id;
        if (Cache::has($lock)) {
            return;
        }
        Cache::put($lock, 1, now()->addMinutes(30));

        $domain = $website->normalized_domain ?: $website->domain;
        // FORCE the paid attempt: a "partial" / young-site snapshot may already
        // exist from the free-feed path, which the freshness gate treats as
        // fresh and would skip. The wizard explicitly wants the deeper
        // (backlink/competitor) data, so bypass the gate this one time.
        // sandbox for admins (+ forced on staging via env); non-admins bill
        // real spend, which the report pipeline meters. One-time per site.
        $sandbox = (bool) $website->user?->is_admin;
        GenerateWebsiteReport::dispatch($domain, true, $sandbox);
    }

    /** Clear the cache so a fresh snapshot is re-read (called after generation). */
    public function forget(Website $website): void
    {
        Cache::forget('content:setup-insights:v1:'.$website->id);
    }

    // ── internals ───────────────────────────────────────────────────────

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

        // No competitor list AND no own referring domains → nothing to show;
        // signal the caller to generate a real report.
        if ($competitorRows === [] && $myReferring === 0) {
            return null;
        }

        $myAuthority = isset($payload['scores']['citation']) ? (int) $payload['scores']['citation'] : null;

        // Top competitors by shared keywords, capped.
        usort($competitorRows, fn ($a, $b) => (int) ($b['shared_keywords'] ?? 0) <=> (int) ($a['shared_keywords'] ?? 0));
        $competitorRows = array_slice($competitorRows, 0, self::MAX_COMPETITORS);

        // Enrich referring domains via OpenPageRank (free bulk).
        $domains = array_values(array_filter(array_map(
            static fn ($c) => trim((string) ($c['domain'] ?? '')),
            $competitorRows
        )));
        $oprMetrics = [];
        try {
            $oprMetrics = $domains !== [] ? $this->opr->metricsFor($domains) : [];
        } catch (\Throwable) {
            $oprMetrics = [];
        }

        $competitors = [];
        foreach ($competitorRows as $c) {
            $cd = trim((string) ($c['domain'] ?? ''));
            if ($cd === '' || $cd === $domain) {
                continue;
            }
            $host = ltrim(strtolower($cd), 'www.');
            $referring = $oprMetrics[$cd]['referring_domains'] ?? $oprMetrics[$host]['referring_domains'] ?? null;
            $competitors[] = [
                'domain' => $cd,
                'referring_domains' => $referring !== null ? (int) $referring : null,
                'authority' => isset($c['cs']) ? (int) $c['cs'] : null,
            ];
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
