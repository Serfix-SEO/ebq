<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KeywordApiRequest;
use App\Models\KeywordMetric;
use App\Models\Website;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordFinder\KeywordIdeasMonthlyCache;
use App\Services\KeywordMetricsService;
use App\Support\KeywordFinderLocations;
use App\Support\KeywordProviderConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keyword Finder API for the WordPress plugin — discovery (seed/url ideas)
 * and volume lookups over the self-hosted Keyword Planner fleet. Mirrors
 * the portal's Livewire orchestration (KeywordIdeaFinder / KeywordVolumeFinder):
 * cache-first (monthly ideas cache / 30-day keyword_metrics), async dispatch
 * via KeywordFinderPool otherwise, caller polls the request endpoint.
 *
 * The fleet is capacity-constrained (single-tab nodes), so real dispatches
 * are rate-limited per website per day — cache hits are always free.
 * Tenancy: KeywordApiRequest rows are stamped with the token's website id
 * and polls only return rows belonging to that website.
 */
class PluginKeywordFinderController extends Controller
{
    private const MAX_SEEDS = 20;

    private const MAX_KEYWORDS = 100;

    /** Real fleet dispatches allowed per website per day (cache hits free). */
    private const DISPATCHES_PER_DAY = 10;

    public function __construct(
        private readonly KeywordFinderPool $pool,
        private readonly KeywordMetricsService $metrics,
    ) {
    }

    /**
     * POST /v1/hq/keyword-finder/ideas — keyword discovery from seeds or a URL.
     * Monthly-cache hit → results inline; otherwise dispatches and returns a
     * request_id to poll.
     */
    public function ideas(Request $request): JsonResponse
    {
        $website = $this->website($request);

        if ($unavailable = $this->providerUnavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'mode' => ['nullable', 'in:seeds,website'],
            'seeds' => ['required_if:mode,seeds', 'array', 'max:'.self::MAX_SEEDS],
            'seeds.*' => ['string', 'max:120'],
            'url' => ['required_if:mode,website', 'nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'in:site,page'],
            'location' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:60'],
        ]);

        $mode = $validated['mode'] ?? 'seeds';
        $opts = [
            'location' => $this->location($validated['location'] ?? null),
            'language' => $this->language($validated['language'] ?? null),
        ];

        if ($mode === 'website') {
            $opts['url'] = trim((string) ($validated['url'] ?? ''));
            $opts['scope'] = ($validated['scope'] ?? 'site') === 'page' ? 'page' : 'site';
        } else {
            $seeds = $this->cleanSeeds($validated['seeds'] ?? []);
            if ($seeds === []) {
                return response()->json(['ok' => false, 'error' => 'no_seeds', 'message' => 'Enter at least one seed keyword.'], 422);
            }
            $opts['seeds'] = $seeds;
        }

        // Same seeds/URL + location/language this calendar month → instant
        // shared-cache answer, no fleet load, doesn't count against the limit.
        [$payloadMode, $normalized] = $this->pool->buildIdeasPayload($opts);
        $cached = KeywordIdeasMonthlyCache::get(KeywordIdeasMonthlyCache::key($payloadMode, $normalized));
        if ($cached !== null) {
            return response()->json([
                'status' => KeywordApiRequest::STATUS_COMPLETED,
                'from_cache' => true,
                'results' => array_map($this->normalizeRow(...), array_values(array_filter($cached, 'is_array'))),
            ]);
        }

        if ($limited = $this->hitDispatchLimit($website)) {
            return $limited;
        }

        $req = $this->pool->dispatchIdeas($opts, userId: null, websiteId: $website->id);

        return $this->dispatchResponse($req);
    }

    /**
     * POST /v1/hq/keyword-finder/volume — volume/competition for a known
     * keyword list. Fresh keyword_metrics rows are served inline; only the
     * misses trigger a fleet dispatch.
     */
    public function volume(Request $request): JsonResponse
    {
        $website = $this->website($request);

        if ($unavailable = $this->providerUnavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'keywords' => ['required', 'array', 'max:'.self::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:60'],
        ]);

        $list = $this->cleanSeeds($validated['keywords'], self::MAX_KEYWORDS);
        if ($list === []) {
            return response()->json(['ok' => false, 'error' => 'no_keywords', 'message' => 'Enter at least one keyword.'], 422);
        }

        $location = $this->location($validated['location'] ?? null);
        $country = KeywordFinderLocations::cacheKey($location);

        $have = $this->metrics->metricsForMany($list, $country);
        $missing = array_values(array_filter(
            $list,
            fn (string $kw): bool => ! (($row = $have[KeywordMetric::hashKeyword($kw)] ?? null) && $row->isFresh()),
        ));

        if ($missing === []) {
            return response()->json([
                'status' => KeywordApiRequest::STATUS_COMPLETED,
                'from_cache' => true,
                'results' => $this->volumeRows($list, $have),
            ]);
        }

        if ($limited = $this->hitDispatchLimit($website)) {
            return $limited;
        }

        // Same trick as the portal's volume finder: an ideas dispatch returns
        // volumes for the seeds themselves, and the webhook warms
        // keyword_metrics under $country for the completion re-read.
        $req = $this->pool->dispatchIdeas(
            ['seeds' => $missing, 'location' => $location, 'language' => $this->language($validated['language'] ?? null)],
            userId: null,
            websiteId: $website->id,
            countryKey: $country,
        );

        return $this->dispatchResponse($req);
    }

    /**
     * GET /v1/hq/keyword-finder/requests/{requestId} — poll an in-flight
     * lookup. Pass keywords= (comma/newline separated) to get volume-shaped
     * rows for exactly those keywords instead of the full ideas set.
     */
    public function show(Request $request, string $requestId): JsonResponse
    {
        $website = $this->website($request);

        $req = KeywordApiRequest::query()
            ->where('request_id', $requestId)
            ->where('website_id', $website->id)
            ->first();

        if ($req === null) {
            return response()->json(['ok' => false, 'error' => 'not_found', 'message' => 'Unknown request.'], 404);
        }

        if (! $req->isFinished()) {
            return response()->json(['status' => $req->status]);
        }

        if ($req->status === KeywordApiRequest::STATUS_FAILED) {
            return response()->json([
                'status' => $req->status,
                'message' => 'The lookup failed. Please try again.',
            ]);
        }

        $rows = array_values(array_filter($req->result['results'] ?? [], 'is_array'));

        // Warm the shared monthly ideas cache like the portal does. The key is
        // recomputed from the stored request row (never client-supplied — an
        // arbitrary key would let one tenant poison the shared cache).
        if ($req->type === KeywordApiRequest::TYPE_IDEAS && $req->mode !== null && $rows !== []) {
            KeywordIdeasMonthlyCache::put(
                KeywordIdeasMonthlyCache::key($req->mode, is_array($req->payload) ? $req->payload : []),
                $rows,
            );
        }

        // Volume-style poll: re-read metrics for just the requested keywords
        // (the webhook cached every returned keyword under country_key).
        $keywordsParam = trim((string) $request->query('keywords', ''));
        if ($keywordsParam !== '') {
            $list = $this->cleanSeeds(preg_split('/[\n,]+/', $keywordsParam) ?: [], self::MAX_KEYWORDS);
            $country = (string) ($req->payload['country_key'] ?? 'global');

            return response()->json([
                'status' => $req->status,
                'results' => $this->volumeRows($list, $this->metrics->metricsForMany($list, $country)),
            ]);
        }

        return response()->json([
            'status' => $req->status,
            'results' => array_map($this->normalizeRow(...), $rows),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function dispatchResponse(KeywordApiRequest $req): JsonResponse
    {
        if ($req->status === KeywordApiRequest::STATUS_FAILED) {
            return response()->json([
                'ok' => false,
                'error' => 'lookup_failed',
                'message' => 'The keyword service is unavailable right now. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'status' => $req->status,
            'request_id' => $req->request_id,
        ], 202);
    }

    private function providerUnavailable(): ?JsonResponse
    {
        if (KeywordProviderConfig::usingKeywordFinder()) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'error' => 'unavailable',
            'message' => 'Keyword discovery is not available right now. Please try again later.',
        ], 503);
    }

    private function hitDispatchLimit(Website $website): ?JsonResponse
    {
        $key = 'plugin-kwf:'.$website->id;
        if (RateLimiter::tooManyAttempts($key, self::DISPATCHES_PER_DAY)) {
            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'You have reached today\'s keyword lookup limit. Please try again tomorrow.',
            ], 429);
        }
        RateLimiter::hit($key, 86400);

        return null;
    }

    /**
     * @param  list<string>  $list
     * @param  array<string, KeywordMetric>  $have
     * @return list<array<string, mixed>>
     */
    private function volumeRows(array $list, array $have): array
    {
        $out = [];
        foreach ($list as $kw) {
            $row = $have[KeywordMetric::hashKeyword($kw)] ?? null;
            $out[] = [
                'keyword' => $kw,
                'volume' => $row?->search_volume,
                'competition' => $row?->competition,
                'trend' => is_array($row?->trend_12m) ? $row->trend_12m : [],
                'from_cache' => $row !== null,
            ];
        }

        usort($out, fn (array $a, array $b): int => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));

        return $out;
    }

    /**
     * Same stable row shape the portal's Keyword Discovery table uses.
     * Bid range is raw Google Ads data (never any derived $ projection).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function normalizeRow(array $r): array
    {
        $vol = isset($r['avgMonthlySearches']) && is_numeric($r['avgMonthlySearches']) ? (int) $r['avgMonthlySearches'] : null;
        $idx = isset($r['competitionIndex']) && is_numeric($r['competitionIndex']) ? (int) $r['competitionIndex'] : null;
        $compStr = is_string($r['competition'] ?? null) && $r['competition'] !== '' ? $r['competition'] : null;
        $level = $compStr !== null
            ? strtolower($compStr)
            : ($idx === null ? 'unknown' : ($idx < 34 ? 'low' : ($idx < 67 ? 'medium' : 'high')));

        return [
            'keyword' => (string) ($r['keyword'] ?? ''),
            'volume' => $vol,
            'competition_index' => $idx,
            'competition' => $compStr ?? ucfirst($level),
            'comp_level' => $level,
            'low_bid' => isset($r['lowTopOfPageBid']) && is_numeric($r['lowTopOfPageBid']) ? (float) $r['lowTopOfPageBid'] : null,
            'high_bid' => isset($r['highTopOfPageBid']) && is_numeric($r['highTopOfPageBid']) ? (float) $r['highTopOfPageBid'] : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    private function cleanSeeds(array $raw, int $cap = self::MAX_SEEDS): array
    {
        $seen = [];
        $out = [];
        foreach ($raw as $p) {
            $s = is_string($p) ? trim($p) : '';
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }

    private function location(?string $location): string
    {
        $location = trim((string) $location);

        return in_array($location, KeywordFinderLocations::locationNames(), true) ? $location : 'United States';
    }

    private function language(?string $language): string
    {
        $language = trim((string) $language);

        return in_array($language, KeywordFinderLocations::languageOptions(), true) ? $language : 'English';
    }

    private function website(Request $request): Website
    {
        $w = $request->attributes->get('api_website');
        abort_unless($w instanceof Website, 500, 'Website context missing');
        abort_unless((bool) ($w->effectiveFeatureFlags()['hq'] ?? false), 403, 'This feature is not available on your plan.');

        return $w;
    }
}
