<?php

namespace App\Services\LinkGraph;

use App\Services\OpenPageRankClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tier-1 passive link-graph collection: deposits the outgoing external links
 * of any page we ALREADY fetched into the append-only edge store. Zero extra
 * fetching — pure byproduct of crawls/enrichment that run anyway.
 *
 * Semantics:
 *  - Nodes are registrable domains (eTLD+1) — matches CC/OPR granularity.
 *  - One edge per (from_domain, to_domain, source); re-sightings bump
 *    last_seen_at, dofollow is sticky-true (any dofollow sighting wins).
 *  - from_url records the first page we saw the link on (page-level
 *    granularity is additive later).
 *  - NEVER throws: a recorder failure must not break the parent crawl/job.
 */
class EdgeRecorder
{
    public const SOURCE_OWN_CRAWL = 'own_crawl';

    public const SOURCE_ENRICHMENT = 'enrichment';

    /** Backlink rows bought from the provider index (report generations + anchor drill-downs). */
    public const SOURCE_PROVIDER = 'provider';

    /** Edges recorded per page — spam pages can carry thousands of outlinks. */
    private const MAX_LINKS_PER_PAGE = 200;

    private const GENERIC_ANCHORS = ['click here', 'here', 'read more', 'more', 'learn more', 'website',
        'link', 'this', 'visit', 'visit website', 'source', 'homepage', 'home'];

    /**
     * @param  string  $fromUrl  the page the links were found on
     * @param  list<array{href: string, anchor?: string, nofollow?: bool}>  $links
     */
    public function record(string $fromUrl, array $links, string $source = self::SOURCE_OWN_CRAWL): int
    {
        try {
            return $this->recordUnsafe($fromUrl, $links, $source);
        } catch (\Throwable $e) {
            Log::warning('EdgeRecorder: failed', ['from' => $fromUrl, 'message' => $e->getMessage()]);

            return 0;
        }
    }

    private function recordUnsafe(string $fromUrl, array $links, string $source): int
    {
        $fromHost = strtolower((string) parse_url($fromUrl, PHP_URL_HOST));
        if ($fromHost === '') {
            return 0;
        }
        $fromDomain = OpenPageRankClient::registrable($fromHost);

        // Collapse to one edge per target domain (first sighting on the page
        // wins for anchor/url detail; dofollow true if ANY link is dofollow).
        $targets = [];
        foreach (array_slice($links, 0, self::MAX_LINKS_PER_PAGE) as $link) {
            $href = (string) ($link['href'] ?? '');
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host === '' || ! str_contains($host, '.')) {
                continue;
            }
            $toDomain = OpenPageRankClient::registrable($host);
            if ($toDomain === $fromDomain) {
                continue; // internal in registrable terms
            }
            $dofollow = ! (bool) ($link['nofollow'] ?? false);
            if (! isset($targets[$toDomain])) {
                $targets[$toDomain] = [
                    'dofollow' => $dofollow,
                    'anchor_class' => $this->anchorClass((string) ($link['anchor'] ?? ''), $toDomain),
                ];
            } else {
                $targets[$toDomain]['dofollow'] = $targets[$toDomain]['dofollow'] || $dofollow;
            }
        }
        if ($targets === []) {
            return 0;
        }

        $domainIds = $this->domainIds(array_merge([$fromDomain], array_keys($targets)));
        $fromId = $domainIds[$fromDomain] ?? null;
        if ($fromId === null) {
            return 0;
        }
        $fromUrlId = $this->urlId($fromId, $fromUrl);

        $now = now();
        $written = 0;
        foreach ($targets as $toDomain => $meta) {
            $toId = $domainIds[$toDomain] ?? null;
            if ($toId === null) {
                continue;
            }
            $existing = DB::table('link_edges')
                ->where(['from_domain_id' => $fromId, 'to_domain_id' => $toId, 'source' => $source])
                ->first(['id', 'dofollow']);
            if ($existing === null) {
                DB::table('link_edges')->insert([
                    'from_domain_id' => $fromId,
                    'to_domain_id' => $toId,
                    'from_url_id' => $fromUrlId,
                    'to_url_id' => null,
                    'dofollow' => $meta['dofollow'],
                    'anchor_class' => $meta['anchor_class'],
                    'source' => $source,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } else {
                DB::table('link_edges')->where('id', $existing->id)->update([
                    'last_seen_at' => $now,
                    'dofollow' => ((bool) $existing->dofollow) || $meta['dofollow'],
                ]);
            }
            $written++;
        }

        return $written;
    }

    /**
     * INBOUND edges from provider backlink rows: each row is a link FROM some
     * page TO $targetDomain. Persists every backlink we ever paid for into
     * the permanent graph (dedup per from-domain, first/last seen, dofollow
     * sticky-true) — report payloads get overwritten on regeneration, this
     * table never does. NEVER throws.
     *
     * @param  list<array<string, mixed>>  $rows  payload backlink rows (url_from, anchor, dofollow)
     */
    public function recordInbound(string $targetDomain, array $rows, string $source = self::SOURCE_PROVIDER): int
    {
        try {
            $toDomain = OpenPageRankClient::registrable(strtolower(trim($targetDomain)));
            if ($toDomain === '' || $rows === []) {
                return 0;
            }

            // Collapse rows to one edge per source domain.
            $sources = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $urlFrom = (string) ($row['url_from'] ?? '');
                $host = strtolower((string) parse_url($urlFrom, PHP_URL_HOST));
                if ($host === '' || ! str_contains($host, '.')) {
                    continue;
                }
                $fromDomain = OpenPageRankClient::registrable($host);
                if ($fromDomain === $toDomain) {
                    continue;
                }
                $dofollow = ! empty($row['dofollow']);
                if (! isset($sources[$fromDomain])) {
                    $sources[$fromDomain] = [
                        'url' => $urlFrom,
                        'dofollow' => $dofollow,
                        'anchor_class' => $this->anchorClass((string) ($row['anchor'] ?? ''), $toDomain),
                    ];
                } else {
                    $sources[$fromDomain]['dofollow'] = $sources[$fromDomain]['dofollow'] || $dofollow;
                }
            }
            if ($sources === []) {
                return 0;
            }

            $domainIds = $this->domainIds(array_merge([$toDomain], array_keys($sources)));
            $toId = $domainIds[$toDomain] ?? null;
            if ($toId === null) {
                return 0;
            }

            $now = now();
            $written = 0;
            foreach ($sources as $fromDomain => $meta) {
                $fromId = $domainIds[$fromDomain] ?? null;
                if ($fromId === null) {
                    continue;
                }
                $existing = DB::table('link_edges')
                    ->where(['from_domain_id' => $fromId, 'to_domain_id' => $toId, 'source' => $source])
                    ->first(['id', 'dofollow']);
                if ($existing === null) {
                    DB::table('link_edges')->insert([
                        'from_domain_id' => $fromId,
                        'to_domain_id' => $toId,
                        'from_url_id' => $this->urlId($fromId, $meta['url']),
                        'to_url_id' => null,
                        'dofollow' => $meta['dofollow'],
                        'anchor_class' => $meta['anchor_class'],
                        'source' => $source,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);
                } else {
                    DB::table('link_edges')->where('id', $existing->id)->update([
                        'last_seen_at' => $now,
                        'dofollow' => ((bool) $existing->dofollow) || $meta['dofollow'],
                    ]);
                }
                $written++;
            }

            return $written;
        } catch (\Throwable $e) {
            Log::warning('EdgeRecorder: recordInbound failed', ['target' => $targetDomain, 'message' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function domainIds(array $names): array
    {
        $names = array_values(array_unique(array_filter($names)));
        DB::table('link_domains')->insertOrIgnore(array_map(fn ($n) => ['name' => $n], $names));

        return DB::table('link_domains')->whereIn('name', $names)->pluck('id', 'name')->all();
    }

    private function urlId(int $domainId, string $url): ?int
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $full = $path.($query !== '' ? '?'.$query : '');
        $hash = sha1($full, true);

        DB::table('link_urls')->insertOrIgnore([
            'domain_id' => $domainId, 'path' => mb_substr($full, 0, 2048), 'path_hash' => $hash,
        ]);

        $id = DB::table('link_urls')->where(['domain_id' => $domainId, 'path_hash' => $hash])->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function anchorClass(string $anchor, string $toDomain): string
    {
        $a = mb_strtolower(trim($anchor));
        if ($a === '') {
            return 'empty';
        }
        if (str_contains($a, $toDomain) || preg_match('#^(https?://|www\.)#', $a)) {
            return 'naked';
        }
        if (in_array($a, self::GENERIC_ANCHORS, true)) {
            return 'generic';
        }

        return 'text';
    }
}
