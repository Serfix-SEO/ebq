<?php

namespace App\Services\Competitive;

use App\Models\DomainMetric;
use App\Services\DataForSeoBacklinkClient;
use App\Services\MozLinksClient;
use App\Services\Content\MozSpendMeter;
use App\Services\Reports\DataForSeoSpendMeter;

/**
 * Shared authority enrichment for a single domain, written to the app-wide
 * {@see DomainMetric} asset. This is the "refined" competitor analysis the
 * Content Autopilot wizard uses (real DataForSEO referring-domains/backlinks +
 * Moz DA/PA), extracted so the SEO Site Explorer competitor discovery can reuse
 * the SAME data instead of its old OpenPageRank-only number (which undercounts
 * referring domains 10-100x).
 *
 * Every value is 30-day cached on `domain_metrics`, so a domain enriched for one
 * product is free for the other within the window. Both paid providers are guarded
 * by their monthly spend breakers and degrade to the last cached value (never throw,
 * never block a render). Admin/sandbox callers hit the mock host and are NEVER
 * persisted — the shared asset must only ever hold real data other users rely on.
 *
 * NOTE: topical relevance (an LLM call) is deliberately NOT here — it is opt-in
 * per the caveat that the SEO explorer must not rack up per-domain LLM spend.
 * See {@see \App\Jobs\Competitive\ClassifyCompetitorTopicJob}.
 */
class CompetitorEnricher
{
    /** Same DataForSEO/Moz freshness window used across domain_metrics. */
    public const CACHE_TTL_DAYS = 30;

    public function __construct(
        private readonly DataForSeoBacklinkClient $dfs,
        private readonly DataForSeoSpendMeter $dfsSpend,
        private readonly MozLinksClient $moz,
        private readonly MozSpendMeter $mozSpend,
    ) {
    }

    /**
     * Full authority snapshot for one domain (DataForSEO refdomains/backlinks/rank
     * + Moz DA/PA). Reads the 30-day cache first; only bills for what is stale and
     * only while the relevant spend breaker has headroom.
     *
     * @return array{referring_domains:?int, backlinks:?int, dfs_rank:?int, moz_da:?int, moz_pa:?int}
     */
    public function enrich(string $domain, bool $sandbox = false): array
    {
        $dfs = $this->dfsMetrics($domain, $sandbox);
        $moz = $this->mozMetrics($domain, $sandbox);

        return [
            'referring_domains' => $dfs['referring_domains'],
            'backlinks' => $dfs['backlinks'],
            'dfs_rank' => $dfs['rank'],
            'moz_da' => $moz['domain_authority'],
            'moz_pa' => $moz['page_authority'],
        ];
    }

    /**
     * DataForSEO referring-domains + backlinks + domain rank, 30-day cached on the
     * shared asset. Mirrors ContentSetupInsights::dfsMetrics() — kept identical so
     * both products read/write the same rows.
     *
     * @return array{referring_domains:?int, backlinks:?int, rank:?int}
     */
    public function dfsMetrics(string $domain, bool $sandbox = false): array
    {
        $empty = ['referring_domains' => null, 'backlinks' => null, 'rank' => null];

        $host = $this->normalizeHost($domain);
        if ($host === '') {
            return $empty;
        }

        $existing = DomainMetric::query()->where('domain', $host)->first();
        $fresh = $existing?->dfs_refreshed_at !== null
            && $existing->dfs_refreshed_at->gt(now()->subDays(self::CACHE_TTL_DAYS));
        if ($fresh) {
            return ['referring_domains' => $existing->dfs_referring_domains, 'backlinks' => $existing->dfs_backlinks, 'rank' => $existing->dfs_rank];
        }

        $stale = $existing !== null
            ? ['referring_domains' => $existing->dfs_referring_domains, 'backlinks' => $existing->dfs_backlinks, 'rank' => $existing->dfs_rank]
            : $empty;

        if (! $this->dfs->isConfigured() || (! $sandbox && $this->dfsSpend->exhausted())) {
            return $stale;
        }

        $this->dfs->resetCost();
        $summary = $this->dfs->useSandbox($sandbox)->summary($domain);
        $this->dfs->useSandbox(false);

        $referring = isset($summary['referring_domains']) ? (int) $summary['referring_domains'] : null;
        $backlinks = isset($summary['backlinks']) ? (int) $summary['backlinks'] : null;
        $rank = isset($summary['rank']) && is_numeric($summary['rank']) ? (int) $summary['rank'] : null;

        if ($sandbox) {
            return ['referring_domains' => $referring, 'backlinks' => $backlinks, 'rank' => $rank]; // mock — never cached/billed
        }

        $this->dfsSpend->add($this->dfs->totalCost());

        DomainMetric::query()->updateOrCreate(
            ['domain' => $host],
            [
                'dfs_referring_domains' => $referring,
                'dfs_backlinks' => $backlinks,
                'dfs_rank' => $rank,
                'dfs_refreshed_at' => now(),
                'last_seen_at' => now(),
                'first_seen_at' => $existing?->first_seen_at ?? now(),
            ]
        );

        return ['referring_domains' => $referring, 'backlinks' => $backlinks, 'rank' => $rank];
    }

    /**
     * Moz DA/PA for one domain, 30-day cached on the shared asset. Mirrors
     * ContentSetupInsights::mozMetrics(). Guarded by the free-tier row cap.
     *
     * @return array{domain_authority:?int, page_authority:?int}
     */
    public function mozMetrics(string $domain, bool $sandbox = false): array
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

        // Sandbox never bills or persists Moz (mirrors the DFS sandbox policy).
        if ($sandbox || ! $this->moz->isConfigured() || $this->mozSpend->exhausted()) {
            return $stale;
        }

        $metrics = $this->moz->urlMetrics($domain) ?? [];
        $this->mozSpend->add(1);
        $da = $metrics['domain_authority'] ?? null;
        $pa = $metrics['page_authority'] ?? null;

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

    /**
     * Organic-traffic estimation for a batch of domains in ONE flat-priced
     * DataForSEO Labs call (site + all competitors together). Stores the compact
     * monthly series (for the chart) AND the full raw blob (ranking-distribution
     * buckets, keyword movement, paid footprint — "save everything") on the shared
     * `domain_metrics` asset, 30-day cached. Skips domains already fresh so a repeat
     * discovery run is free. Admin/sandbox hits the mock host and never persists.
     *
     * @param  list<string>  $domains
     */
    public function enrichTraffic(array $domains, bool $sandbox = false): void
    {
        if (! $this->dfs->isConfigured()) {
            return;
        }

        $hosts = [];
        foreach ($domains as $domain) {
            $host = $this->normalizeHost((string) $domain);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        $hosts = array_keys($hosts);
        if ($hosts === []) {
            return;
        }

        // Only pay for what's stale (unless sandbox, which never bills/persists).
        if (! $sandbox) {
            $fresh = DomainMetric::query()
                ->whereIn('domain', $hosts)
                ->whereNotNull('dfs_traffic_refreshed_at')
                ->where('dfs_traffic_refreshed_at', '>', now()->subDays(self::CACHE_TTL_DAYS))
                ->pluck('domain')
                ->all();
            $hosts = array_values(array_diff($hosts, $fresh));
        }
        if ($hosts === []) {
            return;
        }

        if (! $sandbox && $this->dfsSpend->exhausted()) {
            return; // monthly breaker tripped — next run
        }

        $this->dfs->resetCost();
        $blobs = $this->dfs->useSandbox($sandbox)->historicalBulkTrafficEstimation($hosts);
        $this->dfs->useSandbox(false);

        if (! $sandbox) {
            $this->dfsSpend->add($this->dfs->totalCost());
        }
        if ($blobs === [] || $sandbox) {
            return; // mock data is never cached
        }

        foreach ($blobs as $host => $blob) {
            $existing = DomainMetric::query()->where('domain', $host)->first();
            DomainMetric::query()->updateOrCreate(
                ['domain' => $host],
                [
                    'dfs_traffic_series' => self::extractSeries($blob),
                    'dfs_traffic' => $blob,
                    'dfs_traffic_refreshed_at' => now(),
                    'last_seen_at' => now(),
                    'first_seen_at' => $existing?->first_seen_at ?? now(),
                ]
            );
        }
    }

    /**
     * DEEP organic-traffic history for ONE domain (the site being explored) via
     * DataForSEO Labs historical_rank_overview + a wide `date_from` — years of
     * monthly data for the SAME flat call price as the 12-month bulk endpoint.
     * Used for the exploration report chart; competitor overlays keep the cheaper
     * bulk path. Upgrades a shallow (bulk 12-month) cache to the deep series.
     *
     * Same 30-day cache / spend-meter / sandbox rules as the bulk path.
     */
    public function enrichTrafficDeep(string $domain, bool $sandbox = false): void
    {
        if (! $this->dfs->isConfigured()) {
            return;
        }
        $host = $this->normalizeHost($domain);
        if ($host === '') {
            return;
        }

        if (! $sandbox) {
            $existing = DomainMetric::query()->where('domain', $host)->first();
            $fresh = $existing?->dfs_traffic_refreshed_at !== null
                && $existing->dfs_traffic_refreshed_at->gt(now()->subDays(self::CACHE_TTL_DAYS));
            // Skip only if fresh AND already deep (>= 24 months); a fresh-but-
            // shallow bulk cache gets upgraded to the full history.
            $deep = is_array($existing?->dfs_traffic_series) && count($existing->dfs_traffic_series) >= 24;
            if ($fresh && $deep) {
                return;
            }
            if ($this->dfsSpend->exhausted()) {
                return;
            }
        }

        $months = max(12, (int) config('services.competitive.traffic_history_months', 48));
        $from = now()->subMonths($months)->format('Y-m-d');

        $this->dfs->resetCost();
        $blob = $this->dfs->useSandbox($sandbox)->historicalRankOverview($domain, $from);
        $this->dfs->useSandbox(false);

        if (! $sandbox) {
            $this->dfsSpend->add($this->dfs->totalCost());
        }
        if ($blob === null || $sandbox) {
            return;
        }

        $series = self::extractSeries($blob);
        if ($series === []) {
            return; // don't clobber existing good data with an empty pull
        }

        $existing = DomainMetric::query()->where('domain', $host)->first();
        DomainMetric::query()->updateOrCreate(
            ['domain' => $host],
            [
                'dfs_traffic_series' => $series,
                'dfs_traffic' => $blob,
                'dfs_traffic_refreshed_at' => now(),
                'last_seen_at' => now(),
                'first_seen_at' => $existing?->first_seen_at ?? now(),
            ]
        );
    }

    /**
     * Compact monthly organic series from a DataForSEO historical-traffic blob:
     * [{month: 'YYYY-MM', visits: int, keywords: ?int}, …] ascending by month.
     * Tolerant of the exact nesting DataForSEO returns (organic list of monthly
     * points, each carrying a year+month and an `etv`/`count`).
     *
     * @param  array<string, mixed>  $blob
     * @return list<array{month:string, visits:int, keywords:?int}>
     */
    public static function extractSeries(array $blob): array
    {
        // Two DataForSEO shapes: the BULK endpoint nests the monthly list at
        // metrics.organic (etv at the top of each point); the per-domain
        // historical_rank_overview returns an `items` list where each month
        // carries metrics.organic.{etv,count}. Handle both.
        if (isset($blob['items']) && is_array($blob['items'])) {
            $organic = $blob['items'];
        } else {
            $metrics = $blob['metrics'] ?? $blob;
            $organic = $metrics['organic'] ?? [];
        }
        if (! is_array($organic) || $organic === []) {
            return [];
        }

        $series = [];
        foreach ($organic as $point) {
            if (! is_array($point)) {
                continue;
            }
            $year = $point['year'] ?? null;
            $month = $point['month'] ?? null;
            if (! is_numeric($year) || ! is_numeric($month)) {
                continue;
            }
            $etv = $point['etv'] ?? ($point['metrics']['organic']['etv'] ?? null);
            $count = $point['count'] ?? ($point['metrics']['organic']['count'] ?? null);
            $series[] = [
                'month' => sprintf('%04d-%02d', (int) $year, (int) $month),
                'visits' => is_numeric($etv) ? (int) round((float) $etv) : 0,
                'keywords' => is_numeric($count) ? (int) $count : null,
            ];
        }

        usort($series, static fn ($a, $b) => strcmp($a['month'], $b['month']));

        return $series;
    }

    /**
     * Read the cached organic-traffic series for a domain (chart data), or [] when
     * unenriched. Pure DB read — never bills.
     *
     * @return list<array{month:string, visits:int, keywords:?int}>
     */
    public function trafficSeries(string $domain): array
    {
        $host = $this->normalizeHost($domain);
        if ($host === '') {
            return [];
        }
        $series = DomainMetric::query()->where('domain', $host)->value('dfs_traffic_series');

        return is_array($series) ? $series : [];
    }

    /** Identical host normalization used by domain_metrics writers everywhere. */
    public function normalizeHost(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST) ?: $domain;

        return strtolower(preg_replace('/^www\./', '', (string) $host) ?: (string) $host);
    }
}
