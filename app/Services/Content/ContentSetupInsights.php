<?php

namespace App\Services\Content;

use App\Models\DiscoveredCompetitor;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ClientReportService;

/**
 * Read-only authority/competitor data for the Content Autopilot setup wizard.
 * Reads the SHARED report snapshot + local competitor cache only — never
 * triggers a paid DataForSEO call (uses WebsiteReportSnapshot::forDomain() +
 * ClientReportService::withTraffic(), the pure read path). Returns null when
 * no snapshot exists yet, so the wizard degrades to a "still analyzing" state.
 */
class ContentSetupInsights
{
    public function __construct(private readonly ClientReportService $reports) {}

    /**
     * @return array{
     *   my_referring_domains:int, my_authority:?int, my_trust:?int,
     *   competitors:list<array{domain:string, authority:?int, shared_keywords:?int}>,
     *   median:?int, gap:?float, behind:bool
     * }|null
     */
    public function competitorAuthority(Website $website): ?array
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

        $myReferring = (int) ($payload['totals']['referring_domains'] ?? $snapshot->referring_domains ?? 0);
        $myAuthority = isset($payload['scores']['citation']) ? (int) $payload['scores']['citation'] : null;
        $myTrust = isset($payload['scores']['trust']) ? (int) $payload['scores']['trust'] : null;

        $daCache = [];
        try {
            $daCache = DiscoveredCompetitor::query()
                ->where('website_id', $website->id)
                ->pluck('domain_authority', 'competitor_domain')
                ->all();
        } catch (\Throwable) {
            // competitor cache optional
        }

        $competitors = [];
        foreach ((array) ($payload['competitors'] ?? []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $cd = trim((string) ($c['domain'] ?? ''));
            if ($cd === '' || $cd === $domain) {
                continue;
            }
            $authority = isset($c['cs']) ? (int) $c['cs']
                : (isset($daCache[$cd]) ? (int) $daCache[$cd] : null);
            $competitors[] = [
                'domain' => $cd,
                'authority' => $authority,
                'shared_keywords' => isset($c['shared_keywords']) ? (int) $c['shared_keywords'] : null,
            ];
            if (count($competitors) >= 8) {
                break;
            }
        }

        // Rank strongest-authority first for the table.
        usort($competitors, fn ($a, $b) => ($b['authority'] ?? -1) <=> ($a['authority'] ?? -1));

        $auths = array_values(array_filter(array_column($competitors, 'authority'), 'is_numeric'));
        sort($auths);
        $n = count($auths);
        $median = $n === 0 ? null
            : ($n % 2 ? (int) $auths[intdiv($n, 2)] : (int) round(($auths[$n / 2 - 1] + $auths[$n / 2]) / 2));

        $gap = ($median !== null && $myAuthority !== null) ? $myAuthority - $median : null;

        return [
            'my_referring_domains' => $myReferring,
            'my_authority' => $myAuthority,
            'my_trust' => $myTrust,
            'competitors' => $competitors,
            'median' => $median,
            'gap' => $gap,
            'behind' => $gap !== null && $gap < 0,
        ];
    }
}
