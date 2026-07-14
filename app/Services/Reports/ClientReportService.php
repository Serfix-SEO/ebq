<?php

namespace App\Services\Reports;

use App\Models\Website;
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

        return [
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
            'top_pages' => $this->topPages(is_array($raw['domain_pages'] ?? null) ? $raw['domain_pages'] : []),
            'competitors' => $this->competitorRows(is_array($raw['competitors'] ?? null) ? $raw['competitors'] : [], $domain, $opr),
            'traffic' => null,
        ];
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
        if ($website === null || ! $website->hasGsc()) {
            return $payload;
        }

        $payload['traffic'] = $this->trafficFor($website);

        return $payload;
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
