<?php

namespace App\Services\Reports;

/**
 * Local (free) replacements for three DataForSEO aggregation endpoints —
 * `referring_domains`, `anchors`, `domain_pages` — computed from the raw
 * `backlinks/live` sample instead of paid API calls.
 *
 * ONLY valid when the sample is the COMPLETE live-link profile, i.e.
 * `summary.backlinks <= row_limit` (the sample is fetched `mode=as_is`, top
 * `row_limit` by rank — for a small site that is every live link). Then each
 * aggregation below is exactly what the corresponding endpoint would return:
 * those endpoints are just server-side GROUP BYs over the same link rows.
 * ~53% of report targets qualify (2026-07-17), saving 3 of the 6 paid
 * backlinks calls per report with zero data loss. The caller
 * ({@see \App\Jobs\GenerateWebsiteReport}) decides completeness; big
 * profiles keep the real endpoints because links beyond the sample can't
 * be aggregated locally.
 *
 * Output arrays mirror the ENDPOINT item shapes (same keys, same sort order)
 * so ClientReportService's payload builders consume them unchanged.
 */
class BacklinkSampleAggregator
{
    /**
     * `backlinks/referring_domains/live` equivalent: one row per referring
     * domain — rank (the domain's own DataForSEO rank, carried on every link
     * row as `domain_from_rank`), live-link count, earliest first_seen.
     * Sorted rank desc, matching the endpoint's `order_by=[rank,desc]`.
     *
     * @param  list<array<string, mixed>>  $backlinks
     * @return list<array<string, mixed>>
     */
    public function referringDomains(array $backlinks): array
    {
        $groups = [];
        foreach ($backlinks as $b) {
            $domain = strtolower(trim((string) ($b['domain_from'] ?? '')));
            if ($domain === '') {
                continue;
            }
            $g = &$groups[$domain];
            $g ??= ['domain' => $domain, 'rank' => null, 'backlinks' => 0, 'first_seen' => null];
            $g['backlinks']++;
            if (is_numeric($b['domain_from_rank'] ?? null)) {
                $g['rank'] = max((int) $b['domain_from_rank'], $g['rank'] ?? 0);
            }
            $seen = (string) ($b['first_seen'] ?? '');
            if ($seen !== '' && ($g['first_seen'] === null || $seen < $g['first_seen'])) {
                $g['first_seen'] = $seen; // ISO timestamps — string compare = chronological
            }
            unset($g);
        }
        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => ($b['rank'] ?? 0) <=> ($a['rank'] ?? 0));

        return $rows;
    }

    /**
     * `backlinks/anchors/live` equivalent: one row per anchor text — link
     * count, distinct referring domains, dofollow count. Includes the empty
     * anchor (image/naked links) as '' like the endpoint does. Sorted
     * backlinks desc, matching the endpoint's `order_by=[backlinks,desc]`.
     *
     * @param  list<array<string, mixed>>  $backlinks
     * @return list<array<string, mixed>>
     */
    public function anchors(array $backlinks): array
    {
        $groups = [];
        foreach ($backlinks as $b) {
            $anchor = trim((string) ($b['anchor'] ?? ''));
            $g = &$groups[$anchor];
            $g ??= ['anchor' => $anchor, 'backlinks' => 0, 'dofollow' => 0, '_domains' => []];
            $g['backlinks']++;
            if (($b['dofollow'] ?? false) === true) {
                $g['dofollow']++;
            }
            $domain = strtolower(trim((string) ($b['domain_from'] ?? '')));
            if ($domain !== '') {
                $g['_domains'][$domain] = true;
            }
            unset($g);
        }
        $rows = array_map(function ($g) {
            $g['referring_domains'] = count($g['_domains']);
            unset($g['_domains']);

            return $g;
        }, array_values($groups));
        usort($rows, fn ($a, $b) => $b['backlinks'] <=> $a['backlinks']);

        return $rows;
    }

    /**
     * `backlinks/domain_pages/live` equivalent: one row per linked-to page —
     * live-link count + distinct referring domains. Emits the FLAT shape
     * (`url`/`referring_domains`/`backlinks`) that
     * {@see ClientReportService::topPages()} already reads as its fallback
     * keys. Strictly BETTER than the old empty-endpoint fallback
     * (deriveTopPagesFromBacklinks), which left referring_domains null.
     * Sorted referring_domains desc (topPages' own display order).
     *
     * @param  list<array<string, mixed>>  $backlinks
     * @return list<array<string, mixed>>
     */
    public function domainPages(array $backlinks): array
    {
        $groups = [];
        foreach ($backlinks as $b) {
            $url = trim((string) ($b['url_to'] ?? ''));
            if ($url === '') {
                continue;
            }
            $g = &$groups[$url];
            $g ??= ['url' => $url, 'backlinks' => 0, '_domains' => []];
            $g['backlinks']++;
            $domain = strtolower(trim((string) ($b['domain_from'] ?? '')));
            if ($domain !== '') {
                $g['_domains'][$domain] = true;
            }
            unset($g);
        }
        $rows = array_map(function ($g) {
            $g['referring_domains'] = count($g['_domains']);
            unset($g['_domains']);

            return $g;
        }, array_values($groups));
        usort($rows, fn ($a, $b) => $b['referring_domains'] <=> $a['referring_domains']);

        return $rows;
    }
}
