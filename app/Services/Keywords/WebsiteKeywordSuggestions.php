<?php

namespace App\Services\Keywords;

use App\Jobs\EnrichEmptyReportJob;
use App\Jobs\GenerateWebsiteReport;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\ReportFreshnessGate;

/**
 * Keyword-server / competitor keyword suggestions for a website, reused from
 * the Site Explorer report pipeline. The /keywords page falls back to these
 * when a site has no usable GSC data (not connected, no rows, or only scrap
 * auth/nav queries) so the page is never a dead end.
 *
 * Source of truth is the shared `WebsiteReportSnapshot.payload` for the site's
 * domain — the report enrichment already puts real keyword-server keywords
 * (`keywords`) and competitor-borrowed opportunities (`keyword_opportunities`)
 * there. If that hasn't been produced yet, we kick the SAME pipeline and report
 * a `processing` state; the page polls until it lands.
 */
class WebsiteKeywordSuggestions
{
    public function __construct(
        private ReportFreshnessGate $gate,
        private DataForSeoBacklinkClient $dfs,
    ) {
    }

    /**
     * @return array{status: string, domain: string, keywords: list<array<string, mixed>>,
     *               opportunities: list<array<string, mixed>>, source: ?string,
     *               opportunity_source: ?string}
     *         status: 'ready' | 'processing' | 'unavailable'
     */
    public function for(Website $website): array
    {
        $domain = (string) ($website->normalized_domain ?: $website->domain);
        $none = ['status' => 'unavailable', 'domain' => $domain, 'keywords' => [], 'opportunities' => [], 'source' => null, 'opportunity_source' => null];
        if ($domain === '') {
            return $none;
        }

        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $keywords = is_array($payload['keywords'] ?? null) ? $payload['keywords'] : [];
        $opportunities = is_array($payload['keyword_opportunities'] ?? null) ? $payload['keyword_opportunities'] : [];

        if ($keywords !== [] || $opportunities !== []) {
            return [
                'status' => 'ready',
                'domain' => $domain,
                'keywords' => $keywords,
                'opportunities' => $opportunities,
                'source' => $payload['meta']['sources']['keywords'] ?? 'estimated',
                'opportunity_source' => $payload['meta']['opportunity_source'] ?? null,
            ];
        }

        // Nothing yet — kick the same pipeline the report uses, freshness-gated
        // and job-deduped so repeat page loads never double-bill.
        if (! $this->dfs->isConfigured()) {
            return $none;
        }

        if ($snapshot === null || empty($snapshot->payload)) {
            // No report (or an empty/no_data/enriching row) → full generate;
            // its summary-null path runs the new-site keyword+competitor
            // enrichment, its ready path backfills keywords from the fleet.
            if (! $this->gate->isFresh($domain)) {
                GenerateWebsiteReport::dispatch($domain);
            }
        } else {
            // A full report exists but carries no keyword section yet — backfill
            // just the keywords from the self-hosted fleet (free), no re-bill.
            EnrichEmptyReportJob::dispatch($domain, keywordsOnly: true);
        }

        return ['status' => 'processing', 'domain' => $domain, 'keywords' => [], 'opportunities' => [], 'source' => null, 'opportunity_source' => null];
    }
}
