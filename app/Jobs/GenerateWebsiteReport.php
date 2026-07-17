<?php

namespace App\Jobs;

use App\Models\WebsiteReportSnapshot;
use App\Services\ClientActivityLogger;
use App\Services\DataForSeoBacklinkClient;
use App\Services\MozLinksClient;
use App\Services\OpenPageRankClient;
use App\Services\ReportFreshnessGate;
use App\Services\Reports\BacklinkSampleAggregator;
use App\Services\Reports\ClientReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a domain's backlink profile (DataForSEO) + authority (Moz), assembles
 * the report payload, and upserts the shared per-domain snapshot.
 *
 * Constraints:
 * - tries=1        : DataForSEO/Moz cost money per call — never auto-retry-bill.
 * - uniqueFor=1800 : one pending generation per domain at a time (dedupes the
 *                    "two users analyze the same domain" race).
 *
 * Freshness-gated: no-ops when a fresh snapshot already exists (unless $force),
 * so it is safe to dispatch on every authed analyze. Bounded top-N pulls keep
 * per-report cost flat (~$0.25) regardless of site size.
 */
class GenerateWebsiteReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $domain,
        public readonly bool $force = false,
        public readonly bool $sandbox = false,
    ) {
        $this->onQueue(\App\Support\Queues::DEFAULT);
    }

    public function uniqueId(): string
    {
        return 'generate_website_report:'.WebsiteReportSnapshot::normalizeDomain($this->domain);
    }

    public function handle(
        DataForSeoBacklinkClient $dfs,
        MozLinksClient $moz,
        OpenPageRankClient $opr,
        ReportFreshnessGate $gate,
        ClientReportService $service,
        ClientActivityLogger $logger,
    ): void {
        $normalized = WebsiteReportSnapshot::normalizeDomain($this->domain);
        if ($normalized === '') {
            return;
        }

        // Admin/forced testing → free sandbox host (mock data, no billing), and
        // a namespaced snapshot key so it never overwrites production data.
        $sandbox = $this->sandbox || (bool) config('services.dataforseo.force_sandbox');
        $storeKey = WebsiteReportSnapshot::keyFor($normalized, $sandbox);

        if (! $this->force && $gate->isFresh($normalized, $sandbox)) {
            return;
        }

        if (! $dfs->isConfigured()) {
            Log::warning('GenerateWebsiteReport: DataForSEO not configured', ['domain' => $normalized]);

            return;
        }

        $dfs->useSandbox($sandbox);

        try {
            $summary = $dfs->summary($normalized);
            if ($summary === null) {
                // No provider data for this domain (young site). Instead of the
                // old terminal dead end, hand off to the enrichment pipeline
                // which assembles a PARTIAL report from free/cheap signals
                // (Open PageRank, Moz, self-hosted keyword fleet, SERP
                // competitor tally). `no_data` stays the terminal marker only
                // when enrichment is disabled/ineligible. The summary call
                // itself still cost real money (DataForSEO bills per request
                // regardless of whether it found data).
                Log::warning('GenerateWebsiteReport: empty DataForSEO summary', ['domain' => $normalized]);

                $eligible = ! $sandbox && (bool) config('services.report.enrichment.enabled');
                if ($eligible && (bool) config('services.report.enrichment.attached_only')) {
                    $eligible = \App\Models\Website::query()
                        ->where('normalized_domain', $normalized)
                        ->exists();
                }

                WebsiteReportSnapshot::updateOrCreate(
                    ['normalized_domain' => $storeKey],
                    [
                        'status' => $eligible ? 'enriching' : 'no_data',
                        'payload' => null,
                        'enrichment_state' => $eligible
                            ? ['stage' => 'bootstrap', 'started_at' => now()->toIso8601String()]
                            : null,
                        'fetched_at' => now(),
                        'domain_authority' => null, 'page_authority' => null, 'spam_score' => null,
                        'rank' => null, 'referring_domains' => null, 'backlinks_total' => null,
                        'dataforseo_cost_usd' => $dfs->totalCost(),
                    ],
                );
                $this->logGenerationCost($logger, $normalized, $dfs->totalCost(), $sandbox);

                if ($eligible) {
                    EnrichEmptyReportJob::dispatch($normalized);
                }

                return;
            }

            // Complete-profile shortcut: the backlinks sample is fetched
            // `mode=as_is`, top row_limit by rank — when the domain's TOTAL live
            // links fit inside that cap, the sample IS the complete profile, and
            // the referring_domains / anchors / domain_pages endpoints are just
            // paid server-side GROUP BYs over the same rows. Aggregate locally
            // instead (exact same output, 3 fewer paid calls — ~53% of targets).
            $backlinks = $dfs->backlinksSample($normalized);
            $rowLimit = (int) config('services.dataforseo.row_limit', 1000);
            $sampleComplete = $backlinks !== []
                && ($total = (int) ($summary['backlinks'] ?? 0)) > 0
                && $total <= $rowLimit;
            $agg = $sampleComplete ? new BacklinkSampleAggregator() : null;

            $raw = [
                'summary' => $summary,
                'history' => $dfs->history($normalized),
                'referring_domains' => $agg ? $agg->referringDomains($backlinks) : $dfs->referringDomains($normalized),
                'anchors' => $agg ? $agg->anchors($backlinks) : $dfs->anchors($normalized),
                'domain_pages' => $agg ? $agg->domainPages($backlinks) : $dfs->domainPages($normalized),
                // Organic SERP competitors (shared ranking keywords) — solid;
                // fall back to backlink-intersection competitors if Labs is empty.
                'competitors' => $dfs->labsCompetitors($normalized) ?: $dfs->competitors($normalized),
                'backlinks' => $backlinks,
                // Moz only on the client's own domain (1 free-tier row).
                'moz' => $moz->isConfigured() ? $moz->urlMetrics($normalized) : null,
            ];

            // Open PageRank enrichment — one bulk call for the main domain +
            // the DISPLAYED competitors / referring domains / backlink sources
            // (~60 domains → 1 request). Powers Popularity rank + per-row scores.
            $oprDomains = [$normalized];
            foreach (array_slice($raw['competitors'], 0, 10) as $c) {
                $oprDomains[] = (string) ($c['domain'] ?? ($c['target'] ?? ''));
            }
            foreach (array_slice($raw['referring_domains'], 0, 25) as $rd) {
                $oprDomains[] = (string) ($rd['domain'] ?? '');
            }
            foreach (array_slice($raw['backlinks'], 0, 25) as $b) {
                $src = (string) ($b['domain_from'] ?? ($b['url_from'] ?? ''));
                $oprDomains[] = $src;
                // Subdomain sources (forum.example.com) are ranked by OPR at the
                // registrable-domain level (example.com) — query that too.
                $oprDomains[] = OpenPageRankClient::registrable($src);
            }
            $raw['opr'] = $opr->metricsFor($oprDomains);

            $payload = $service->assemble($normalized, $raw);

            // Stamp the topical-relevance section as pending BEFORE the
            // snapshot write, so the UI can show a progress card instead of
            // nothing while EnrichTopicalTrustJob (dispatched below) fetches
            // homepages + classifies. The job replaces this stub with the
            // real section, or clears it when classification isn't possible.
            if (! $sandbox
                && (bool) config('services.report.topical_trust.enabled', true)
                && count($payload['top_referring_domains'] ?? []) >= 3) {
                $payload['topical_trust'] = ['pending' => true, 'queued_at' => now()->toIso8601String()];
            }

            // Real cumulative cost of every DataForSEO call made for THIS
            // generation (summary/history/referring_domains/anchors/
            // domain_pages/competitors/backlinks) — Moz + Open PageRank are
            // free-tier, no cost to add. Replaces the flat
            // `services.report.generation_cost_usd` estimate the admin
            // usage page used before real tracking existed.
            $cost = $dfs->totalCost();

            WebsiteReportSnapshot::updateOrCreate(
                ['normalized_domain' => $storeKey],
                array_merge($service->headlineColumns($payload), [
                    'payload' => $payload,
                    'status' => 'ready',
                    'fetched_at' => now(),
                    'dataforseo_cost_usd' => $cost,
                ]),
            );
            $this->logGenerationCost($logger, $normalized, $cost, $sandbox);

            // Harvest domain intelligence into the accumulating asset store
            // (main domain + referring domains + competitors). Free byproduct;
            // internally try/caught so it can never break the report.
            if (! $sandbox) {
                app(\App\Services\DomainIntel\DomainMetricsRecorder::class)
                    ->recordReport($normalized, $payload);

                // Permanent per-link storage: report payloads are OVERWRITTEN
                // on regeneration, so every provider backlink row is also
                // deposited into the append-only link graph (dedup per source
                // domain, first/last seen) — paid for once, kept forever.
                app(\App\Services\LinkGraph\EdgeRecorder::class)
                    ->recordInbound($normalized, $payload['backlinks'] ?? []);
            }

            // Full reports get a keywords section too — sourced from the
            // self-hosted keyword fleet (free), merged into the payload
            // asynchronously once discovery completes.
            if (! $sandbox && (bool) config('services.report.enrichment.enabled')) {
                EnrichEmptyReportJob::dispatch($normalized, keywordsOnly: true);
            }

            // Topical relevance section — one LLM call over the top referring
            // domains' homepage snippets. Additive & guarded; failure or kill
            // switch just means the section stays absent.
            if (! $sandbox && (bool) config('services.report.topical_trust.enabled', true)) {
                EnrichTopicalTrustJob::dispatch($normalized);
            }
        } catch (Throwable $e) {
            Log::warning('GenerateWebsiteReport: failed', [
                'domain' => $normalized,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Real-cost ledger entry for a single generation — the accurate source
     * the admin Site Explorer Usage page sums over a date range (unlike the
     * snapshot's `dataforseo_cost_usd`, which only ever holds the LATEST
     * generation's cost and gets overwritten on the next regeneration).
     * System-triggered (no specific user — the generation may have been
     * dispatched by any of several users racing on the same domain, or a
     * cron/admin action), and skipped entirely for sandbox (never billed).
     */
    private function logGenerationCost(ClientActivityLogger $logger, string $domain, float $cost, bool $sandbox): void
    {
        if ($sandbox) {
            return;
        }

        $logger->log('site_explorer.generation', meta: [
            'domain' => $domain,
            'cost_usd' => $cost,
        ]);
    }
}
