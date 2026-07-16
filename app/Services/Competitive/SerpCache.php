<?php

namespace App\Services\Competitive;

use App\Models\CompetitorBacklink;
use App\Models\SerpCacheEntry;
use App\Services\SerperSearchClient;
use Illuminate\Support\Carbon;

/**
 * Read-through cache for organic SERP results, shared across ALL clients.
 *
 * A SERP is a client-agnostic fact, so every competitive consumer (gap
 * verification, opportunity scoring, competitor discovery) goes through here:
 * a fresh cache hit returns stored data with NO Serper call — meaning no spend
 * and no quota consumption — so a keyword one client checked is free for the
 * next until the TTL (default 7 days) lapses.
 *
 * The cached `payload` is a normalized slice compatible with the raw Serper
 * shape its consumers already read: `organic` (top-10 with position + link)
 * plus the SERP-feature markers used by {@see OpportunityScoreService::scoreFromSerp}.
 */
class SerpCache
{
    public function __construct(private SerperSearchClient $serper)
    {
    }

    /**
     * Fresh cached SERP for a keyword, or null — NEVER calls Serper. Lets
     * consumers (gap verification) process every already-cached keyword for
     * free and spend their live-call budget only on true cache misses.
     *
     * @return array<string, mixed>|null
     */
    public function cached(string $keyword, string $gl): ?array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        $gl = strtolower(trim($gl)) ?: 'us';

        $row = SerpCacheEntry::query()
            ->where('query_hash', SerpCacheEntry::hash($kw, $gl))
            ->where('gl', $gl)
            ->first();

        return ($row !== null && $row->isFresh()) ? $row->payload : null;
    }

    /**
     * How many of the given keywords have a FRESH cached SERP (one query, no
     * API calls) — used to size verification passes before running them.
     *
     * @param  list<string>  $keywords
     */
    public function freshCount(array $keywords, string $gl): int
    {
        $gl = strtolower(trim($gl)) ?: 'us';
        $hashes = array_map(
            static fn (string $k): string => SerpCacheEntry::hash(trim($k), $gl),
            array_values(array_filter(array_map('strval', $keywords), static fn ($k) => trim((string) $k) !== '')),
        );
        if ($hashes === []) {
            return 0;
        }

        return SerpCacheEntry::query()
            ->whereIn('query_hash', $hashes)
            ->where('gl', $gl)
            ->where('expires_at', '>', Carbon::now())
            ->count();
    }

    /**
     * Organic SERP for a keyword in a country. Returns the normalized payload,
     * or null if we have nothing (cache miss + live fetch failed with no stale
     * fallback). Propagates QuotaExceededException from the live call so callers
     * can surface the plan-cap message — a cache hit never reaches that path.
     *
     * @return array<string, mixed>|null
     */
    public function organic(string $keyword, string $gl, ?string $websiteId = null, ?string $ownerUserId = null, string $source = 'serp_cache'): ?array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return null;
        }
        $gl = strtolower(trim($gl)) ?: 'us';
        $hash = SerpCacheEntry::hash($kw, $gl);

        $row = SerpCacheEntry::query()->where('query_hash', $hash)->where('gl', $gl)->first();
        if ($row !== null && $row->isFresh()) {
            return $row->payload; // free reuse — no API call, no quota spend
        }

        $json = $this->serper->query([
            'q' => $kw,
            'type' => 'organic',
            'gl' => $gl,
            'num' => 10,
            '__website_id' => $websiteId,
            '__owner_user_id' => $ownerUserId,
            '__source' => $source,
        ]);

        if (! is_array($json)) {
            // Live fetch failed — serve stale rather than nothing, if we have it.
            return $row?->payload;
        }

        $payload = $this->normalize($json);

        $ttl = max(1, (int) config('services.competitive.serp_cache_days', 7));
        $now = Carbon::now();
        SerpCacheEntry::updateOrCreate(
            ['query_hash' => $hash, 'gl' => $gl],
            [
                'query' => mb_substr($kw, 0, 255),
                'payload' => $payload,
                'fetched_at' => $now,
                'expires_at' => $now->copy()->addDays($ttl),
            ]
        );

        return $payload;
    }

    /**
     * Reduce a raw Serper response to the slice our consumers need, keeping the
     * shape they already parse (organic[].position/link, plus feature markers
     * present-only so `isset`/`!empty` checks stay correct).
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function normalize(array $json): array
    {
        $organic = is_array($json['organic'] ?? null) ? $json['organic'] : [];
        $slim = [];
        foreach (array_slice($organic, 0, 10) as $idx => $r) {
            if (! is_array($r)) {
                continue;
            }
            $link = (string) ($r['link'] ?? $r['url'] ?? '');
            $slim[] = [
                'position' => (int) ($r['position'] ?? ($idx + 1)),
                'link' => $link,
                'domain' => CompetitorBacklink::extractDomain($link),
            ];
        }

        $payload = ['organic' => $slim];
        // Feature markers — set only when present so isset()/!empty() stay valid.
        if (! empty($json['answerBox'])) {
            $payload['answerBox'] = true;
        }
        if (! empty($json['knowledgeGraph'])) {
            $payload['knowledgeGraph'] = true;
        }
        if (! empty($json['ads'])) {
            $payload['ads'] = true;
        }
        if (! empty($json['shopping'])) {
            $payload['shopping'] = true;
        }
        if (is_array($json['peopleAlsoAsk'] ?? null) && $json['peopleAlsoAsk'] !== []) {
            // Preserve count (what scoreFromSerp uses) without storing bulk text.
            $payload['peopleAlsoAsk'] = array_fill(0, min(10, count($json['peopleAlsoAsk'])), 1);
        }

        return $payload;
    }
}
