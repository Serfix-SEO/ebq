<?php

namespace App\Jobs\Content;

use App\Models\DomainMetric;
use App\Models\Website;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Batched DataForSEO Labs "bulk traffic estimation" for a content plan's
 * competitor domains. Fetches organic/paid traffic (ETV), keyword counts and
 * whatever else the endpoint returns for EVERY competitor in ONE flat-priced
 * task (up to 1,000 targets) instead of one call per domain — the cheap way to
 * back the "monthly searches" teaser card on the keyword step.
 *
 * "Store whatever is provided": the raw per-domain metrics blob lands on the
 * shared {@see DomainMetric} asset (`dfs_metrics` JSON + `dfs_metrics_refreshed_at`),
 * so any subsystem touching that domain reuses it for 30 days without re-billing.
 *
 * Runs ASYNC (never blocks the wizard render) and is idempotent: domains with a
 * fresh blob are skipped, so a repeated dispatch costs nothing.
 */
class EnrichCompetitorDomainMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Reuse the DataForSEO 30-day freshness window used elsewhere for domain_metrics. */
    private const FRESH_DAYS = 30;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  list<string>  $domains  competitor domains (any form; normalized here)
     */
    public function __construct(public string $websiteId, public array $domains)
    {
        $this->onQueue('content');
    }

    public function handle(DataForSeoBacklinkClient $dfs, DataForSeoSpendMeter $spend): void
    {
        if (! $dfs->isConfigured()) {
            return;
        }

        // Admin-owned sites route to the free mock host and are NEVER persisted —
        // the shared domain_metrics asset must only hold real data (same policy
        // as ContentSetupInsights::dfsMetrics()).
        $website = Website::query()->find($this->websiteId);
        $sandbox = (bool) $website?->user?->is_admin;

        // Normalize + de-dupe; drop domains already fresh so we only pay for what
        // we don't have.
        $hosts = [];
        foreach ($this->domains as $domain) {
            $host = $this->normalizeHost((string) $domain);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        $hosts = array_keys($hosts);
        if ($hosts === []) {
            return;
        }

        if (! $sandbox) {
            $fresh = DomainMetric::query()
                ->whereIn('domain', $hosts)
                ->whereNotNull('dfs_metrics_refreshed_at')
                ->where('dfs_metrics_refreshed_at', '>', now()->subDays(self::FRESH_DAYS))
                ->pluck('domain')
                ->all();
            $hosts = array_values(array_diff($hosts, $fresh));
        }
        if ($hosts === []) {
            return;
        }

        if (! $sandbox && $spend->exhausted()) {
            return; // monthly breaker tripped — try again next window
        }

        $dfs->resetCost();
        $metrics = $dfs->useSandbox($sandbox)->bulkTrafficEstimation($hosts);
        $dfs->useSandbox(false);

        if (! $sandbox) {
            $spend->add($dfs->totalCost());
        }

        if ($metrics === [] || $sandbox) {
            return; // nothing to persist (mock data is never cached)
        }

        foreach ($metrics as $host => $blob) {
            $existing = DomainMetric::query()->where('domain', $host)->first();
            DomainMetric::query()->updateOrCreate(
                ['domain' => $host],
                [
                    'dfs_metrics' => $blob,
                    'dfs_metrics_refreshed_at' => now(),
                    'last_seen_at' => now(),
                    'first_seen_at' => $existing?->first_seen_at ?? now(),
                ]
            );
        }
    }

    private function normalizeHost(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST) ?: $domain;

        return strtolower(preg_replace('/^www\./', '', (string) $host) ?: (string) $host);
    }
}
