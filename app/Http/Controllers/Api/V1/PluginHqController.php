<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunCustomPageAudit;
use App\Jobs\TrackKeywordRankJob;
use App\Models\CustomPageAudit;
use App\Models\PageIndexingStatus;
use App\Models\RankTrackingKeyword;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\PageAuditService;
use App\Services\BacklinkProspectingService;
use App\Services\CrossSiteBenchmarkService;
use App\Services\Google\GoogleClientFactory;
use App\Services\PluginInsightResolver;
use App\Services\RankCache;
use App\Services\ReportCache;
use App\Services\ReportDataService;
use App\Support\Audit\SerpGlCatalog;
use App\Support\RankTrackerConfig;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\URL;
use App\Services\SerpFeatureTrackerService;
use App\Services\TopicalAuthorityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * "EBQ HQ" admin-page API. Powers the top-level WordPress admin menu the
 * plugin renders — overview, SEO performance, keywords, pages, index status,
 * and the insights opportunity feed (cannibalizations / striking distance /
 * content decay / indexing fails / quick wins).
 *
 * Every method runs under the existing `website.api:read:insights` middleware
 * (per-website Sanctum token) and reuses the same service layer that powers
 * the EBQ.io Livewire dashboards. No new domain logic — this is the API
 * surface, not a parallel implementation.
 */
class PluginHqController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reports,
        private readonly PluginInsightResolver $insightResolver,
    ) {
    }

    /**
     * Top-level KPIs + insight counts. Used by the HQ Overview tab.
     *   GET /api/v1/hq/overview?range=30d
     *
     * Cached on a per-website + range basis with a 24h sanity TTL.
     * Real invalidation is event-driven via ReportCache::flushWebsite()
     * from the nightly GSC sync and the rank-tracker job.
     */
    public function overview(Request $request): JsonResponse
    {
        $website = $this->website($request);
        [$start, $end, $prevStart, $prevEnd, $rangeKey] = $this->resolveRange($request);

        $payload = Cache::remember(
            sprintf('hq:overview:v1:%s:%s:%d:%d', $website->id, $rangeKey, ReportCache::version($website->id), RankCache::version($website->id)),
            now()->addHours(24),
            fn () => $this->buildOverviewPayload($website, $start, $end, $prevStart, $prevEnd, $rangeKey),
        );

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverviewPayload(
        Website $website,
        string $start,
        string $end,
        string $prevStart,
        string $prevEnd,
        string $rangeKey,
    ): array {
        $current = $this->aggregateGsc($website->id, $start, $end);
        $previous = $this->aggregateGsc($website->id, $prevStart, $prevEnd);

        $rankingKeywords = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->where('position', '<=', 100)
            ->where('query', '!=', '')
            ->distinct('query')
            ->count('query');

        $trackedKeywords = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->where('is_active', true)
            ->count();

        $positionSlabs = $this->positionDistribution($website->id, $start, $end);
        $trackerSlabs = $this->trackerPositionDistribution($website->id);

        // Light line-chart data so the overview can render its own sparkline
        // without a follow-up fetch.
        $sparkline = $this->dailyClicks($website->id, $start, $end);

        // topMovers computes both gainers + losers in a single query and
        // returns them keyed by direction; cheaper than two calls.
        $movers = $this->topMoversBoth($website->id, $start, $end, 5);

        return [
            'website_id' => $website->id,
            'domain' => $website->domain,
            'range' => [
                'key' => $rangeKey,
                'start' => $start,
                'end' => $end,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
            ],
            'kpi' => [
                'clicks' => $this->kpiTriple($current['clicks'], $previous['clicks']),
                'impressions' => $this->kpiTriple($current['impressions'], $previous['impressions']),
                'avg_position' => $this->kpiTriple(
                    $current['avg_position'],
                    $previous['avg_position'],
                    invert: true,
                    decimals: 1,
                ),
                'ctr' => $this->kpiTriple(
                    $current['ctr'] * 100,
                    $previous['ctr'] * 100,
                    decimals: 2,
                    suffix: '%',
                ),
                'ranking_keywords' => $this->kpiTriple($rankingKeywords, null),
                'tracked_keywords' => $this->kpiTriple($trackedKeywords, null),
            ],
            'position_distribution' => $positionSlabs,
            'tracker_distribution' => $trackerSlabs,
            'sparkline' => $sparkline,
            'insight_counts' => $this->reports->insightCounts($website->id),
            'top_winning_keywords' => $movers['gainers'],
            'top_losing_keywords' => $movers['losers'],
        ];
    }

    /**
     * Time-series for the SEO Performance line chart.
     *   GET /api/v1/hq/performance?range=30d
     */
    public function performance(Request $request): JsonResponse
    {
        $website = $this->website($request);
        [$start, $end, , , $rangeKey] = $this->resolveRange($request);

        $rows = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('date, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => $row->position !== null ? round((float) $row->position, 2) : null,
                'ctr' => $row->impressions > 0 ? round(($row->clicks / $row->impressions) * 100, 2) : 0.0,
            ])
            ->all();

        return response()->json([
            'range' => ['key' => $rangeKey, 'start' => $start, 'end' => $end],
            'series' => $rows,
        ]);
    }

    /**
     * Tracked keywords list with current position, change, and 30-day GSC
     * metrics for each. Sortable columns map to the table headers in the WP
     * admin UI.
     *   GET /api/v1/hq/keywords?sort=current_position&dir=asc&page=1&search=
     */
    public function keywords(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $sort = $request->query('sort', 'current_position');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $search = trim((string) $request->query('search', ''));

        $allowedSorts = ['keyword', 'current_position', 'best_position', 'position_change', 'last_checked_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'current_position';
        }

        $query = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->where('is_active', true);

        if ($search !== '') {
            $query->where('keyword', 'LIKE', '%' . addcslashes($search, '\\%_') . '%');
        }

        // current_position can be null when never checked — push nulls AHEAD
        // of the position list so a freshly-added keyword (which always has
        // null position until the first cron run) is visible at the top
        // instead of buried on the last page.
        if ($sort === 'current_position' || $sort === 'best_position' || $sort === 'position_change') {
            $query->orderByRaw("$sort IS NULL DESC"); // unchecked keywords first
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy($sort, $dir);
        }
        // Always tiebreak on id DESC so the most recently added keyword
        // surfaces first within any equal-rank group (NULLs especially).
        $query->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        $tz = config('app.timezone');
        $rangeEnd = Carbon::yesterday($tz)->toDateString();
        $rangeStart = Carbon::yesterday($tz)->subDays(29)->toDateString();

        $keywords = collect($paginator->items())->pluck('keyword')->map(fn ($k) => mb_strtolower($k))->all();
        $gsc = collect();
        if (! empty($keywords)) {
            $gsc = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereBetween('date', [$rangeStart, $rangeEnd])
                ->whereIn('query', $keywords)
                ->selectRaw('LOWER(`query`) AS qkey, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position')
                ->groupBy('qkey')
                ->get()
                ->keyBy('qkey');
        }

        $items = collect($paginator->items())->map(function (RankTrackingKeyword $k) use ($gsc) {
            $key = mb_strtolower($k->keyword);
            $g = $gsc->get($key);
            return [
                'id' => $k->id,
                'keyword' => $k->keyword,
                'target_url' => $k->target_url,
                'current_position' => $k->current_position !== null ? (float) $k->current_position : null,
                'best_position' => $k->best_position !== null ? (float) $k->best_position : null,
                'position_change' => $k->position_change !== null ? (float) $k->position_change : null,
                'current_url' => $k->current_url,
                'last_checked_at' => $k->last_checked_at?->toIso8601String(),
                // last_status lets the UI distinguish "queued / never checked"
                // from "checked but not ranking in top N" — both have a null
                // current_position but mean very different things to the user.
                'last_status' => (string) ($k->last_status ?? ''),
                'last_error' => $k->last_error ? mb_substr((string) $k->last_error, 0, 200) : null,
                'depth' => (int) ($k->depth ?? 100),
                'gsc_clicks' => $g ? (int) $g->clicks : 0,
                'gsc_impressions' => $g ? (int) $g->impressions : 0,
                'gsc_position' => $g ? round((float) $g->position, 2) : null,
                'tags' => $k->tags ?? [],
            ];
        });

        return response()->json([
            'data' => $items->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    /**
     * Create (or upsert) a tracked keyword from the WP plugin. Mirrors the
     * Livewire RankTrackingManager::addKeyword form so anything you can
     * configure on EBQ.io you can configure from WP.
     *
     *   POST /api/v1/hq/keywords
     *   body: { keyword, target_domain?, target_url?, search_engine?, search_type?,
     *           country?, language?, location?, device?, depth?, tbs?,
     *           autocorrect?, safe_search?, competitors[], tags[], notes?,
     *           check_interval_hours? }
     */
    public function storeKeyword(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $data = $request->validate([
            'keyword'              => 'required|string|min:1|max:500',
            'target_url'           => 'nullable|string|max:2048',
            'search_engine'        => ['nullable', Rule::in(['google'])],
            'search_type'          => ['nullable', Rule::in(['organic', 'news', 'images', 'videos', 'shopping', 'maps', 'scholar'])],
            'country'              => 'nullable|string|size:2',
            'language'             => 'nullable|string|min:2|max:10',
            'device'               => ['nullable', Rule::in(['desktop', 'mobile'])],
            'autocorrect'          => 'nullable|boolean',
            'safe_search'          => 'nullable|boolean',
            'competitors'          => 'nullable|array|max:20',
            'competitors.*'        => 'string|max:255',
            'notes'                => 'nullable|string|max:2000',
        ]);

        $domain = (string) $website->domain;
        $targetUrl = RankTrackerConfig::normalizeTargetUrl($domain, $data['target_url'] ?? null);
        if (! empty($data['target_url']) && $targetUrl === null) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_target_url',
                'message' => 'Target URL must be a path on your connected domain.',
            ], 422);
        }

        $defaults = [
            'target_domain'        => $domain,
            'search_engine'        => $data['search_engine'] ?? 'google',
            'search_type'          => $data['search_type'] ?? 'organic',
            'country'              => strtolower($data['country'] ?? 'us'),
            'language'             => strtolower($data['language'] ?? 'en'),
            'device'               => $data['device'] ?? 'desktop',
            'depth'                => RankTrackerConfig::DEFAULT_DEPTH,
            'check_interval_hours' => RankTrackerConfig::checkIntervalHours(),
            'autocorrect'          => $data['autocorrect'] ?? false,
            'safe_search'          => $data['safe_search'] ?? false,
        ];

        $keyword = RankTrackingKeyword::updateOrCreate(
            [
                'website_id'    => $website->id,
                'keyword_hash'  => RankTrackingKeyword::hashKeyword($data['keyword']),
                'search_engine' => $defaults['search_engine'],
                'search_type'   => $defaults['search_type'],
                'country'       => $defaults['country'],
                'language'      => $defaults['language'],
                'device'        => $defaults['device'],
                'location'      => null,
            ],
            [
                'user_id'              => $website->user_id,
                'keyword'              => trim($data['keyword']),
                'target_domain'        => trim($defaults['target_domain']),
                'target_url'           => $targetUrl,
                'depth'                => $defaults['depth'],
                'tbs'                  => null,
                'autocorrect'          => (bool) $defaults['autocorrect'],
                'safe_search'          => (bool) $defaults['safe_search'],
                'competitors'          => $data['competitors'] ?? [],
                'tags'                 => [],
                'notes'                => $data['notes'] ?? null,
                'check_interval_hours' => $defaults['check_interval_hours'],
                'is_active'            => true,
                'next_check_at'        => Carbon::now(),
            ]
        );

        // Queue an immediate first check so the row populates within minutes.
        TrackKeywordRankJob::dispatch($keyword->id, $keyword->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);

        return response()->json([
            'ok' => true,
            'keyword' => [
                'id'      => $keyword->id,
                'keyword' => $keyword->keyword,
                'country' => $keyword->country,
                'device'  => $keyword->device,
            ],
        ], 201);
    }

    /**
     * Update a small set of mutable fields on an existing keyword (pause,
     * tags, notes, check interval). Anything that would change the unique
     * key (engine/type/country/language/device/location) requires creating
     * a new tracked keyword instead.
     *
     *   PATCH /api/v1/hq/keywords/{id}
     */
    public function updateKeyword(Request $request, string $id): JsonResponse
    {
        $website = $this->website($request);
        $keyword = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->findOrFail($id);

        $data = $request->validate([
            'is_active'  => 'sometimes|boolean',
            'notes'      => 'sometimes|nullable|string|max:2000',
            'target_url' => 'sometimes|nullable|string|max:2048',
        ]);

        if (array_key_exists('target_url', $data)) {
            $normalized = RankTrackerConfig::normalizeTargetUrl((string) $website->domain, $data['target_url']);
            if ($data['target_url'] !== null && $data['target_url'] !== '' && $normalized === null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'invalid_target_url',
                    'message' => 'Target URL must be a path on your connected domain.',
                ], 422);
            }
            $data['target_url'] = $normalized;
        }

        $keyword->fill($data)->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Force an immediate re-check. Same job the EBQ Livewire UI dispatches.
     *   POST /api/v1/hq/keywords/{id}/recheck
     */
    public function recheckKeyword(Request $request, string $id): JsonResponse
    {
        $website = $this->website($request);
        $keyword = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->findOrFail($id);

        TrackKeywordRankJob::dispatch($keyword->id, $keyword->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);
        $keyword->forceFill(['last_status' => 'queued', 'next_check_at' => Carbon::now()])->save();

        return response()->json(['ok' => true]);
    }

    /**
     *   DELETE /api/v1/hq/keywords/{id}
     */
    public function deleteKeyword(Request $request, string $id): JsonResponse
    {
        $website = $this->website($request);
        $keyword = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->findOrFail($id);
        $keyword->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GSC keyword candidates the user could promote into Rank Tracker — the
     * "auto-add" pool. Returns queries the site already shows up for in GSC
     * but that aren't yet tracked, ordered by impressions.
     *   GET /api/v1/hq/keywords/candidates
     */
    public function keywordCandidates(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $limit = max(10, min(100, (int) $request->query('limit', 25)));

        $tz = config('app.timezone');
        $end = Carbon::yesterday($tz)->toDateString();
        $start = Carbon::yesterday($tz)->subDays(89)->toDateString();

        $existing = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->pluck('keyword')
            ->map(fn ($k) => mb_strtolower((string) $k))
            ->all();

        $rows = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->where('query', '!=', '')
            ->selectRaw('query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit($limit * 4) // over-fetch so we can filter out tracked ones
            ->get();

        $out = [];
        foreach ($rows as $r) {
            if (in_array(mb_strtolower((string) $r->query), $existing, true)) continue;
            $out[] = [
                'keyword' => (string) $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
            ];
            if (count($out) >= $limit) break;
        }

        return response()->json(['data' => $out]);
    }

    /**
     * 30-day position history for a single tracked keyword.
     *   GET /api/v1/hq/keywords/{id}/history
     */
    public function keywordHistory(Request $request, string $id): JsonResponse
    {
        $website = $this->website($request);
        $keyword = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->findOrFail($id);

        $since = Carbon::now()->subDays(90)->toDateTimeString();
        $snapshots = $keyword->snapshots()
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->get(['checked_at', 'position', 'url']);

        return response()->json([
            'keyword' => $keyword->keyword,
            'series' => $snapshots->map(fn ($s) => [
                'at' => $s->checked_at?->toIso8601String(),
                'position' => $s->position !== null ? (float) $s->position : null,
                'url' => $s->url,
            ])->all(),
        ]);
    }

    /**
     * Keyword deep-dive — every signal we hold on one query, mirroring the
     * portal's /keywords/{query} page (KeywordDetailService is shared with
     * the Livewire component, so the two can never drift):
     * keyword-intelligence metric (volume/CPC/competition/12-mo trend), GSC
     * 28d totals + 90d daily series, top pages / countries / devices,
     * rank-tracker state + latest SERP snapshot, related searches + PAA,
     * opportunity flags, CTR-curve click projection (never $ figures).
     *   GET /api/v1/hq/keyword-detail?query=…
     */
    public function keywordDetail(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $query = trim((string) $request->query('query', ''));
        if ($query === '' || mb_strlen($query) > 255) {
            return response()->json(['error' => 'invalid_query'], 422);
        }

        $signals = app(\App\Services\KeywordDetailService::class)->signals($website->id, $query);

        $metric = $signals['metric'];
        $tracker = $signals['tracker'];
        $snapshot = $signals['tracker_latest_snapshot'];

        return response()->json([
            'query' => $query,
            'metric' => $metric ? [
                'search_volume' => $metric->search_volume !== null ? (int) $metric->search_volume : null,
                'cpc' => $metric->cpc !== null ? (float) $metric->cpc : null,
                'currency' => $metric->currency ?: 'USD',
                'competition' => $metric->competition !== null ? (float) $metric->competition : null,
                'trend_class' => $metric->trend_class,
                'trend_12m' => is_array($metric->trend_12m)
                    ? array_values(array_filter($metric->trend_12m, fn ($r) => is_array($r) && isset($r['value'])))
                    : [],
            ] : null,
            'gsc_totals' => $signals['gsc_totals'],
            'gsc_daily' => $signals['gsc_daily'],
            'top_pages' => $signals['top_pages'],
            'countries' => $signals['countries'],
            'devices' => $signals['devices'],
            'tracker' => $tracker ? [
                'id' => $tracker->id,
                'keyword' => $tracker->keyword,
                'current_position' => $tracker->current_position !== null ? (float) $tracker->current_position : null,
                'best_position' => $tracker->best_position !== null ? (float) $tracker->best_position : null,
                'position_change' => $tracker->position_change !== null ? (float) $tracker->position_change : null,
                'current_url' => $tracker->current_url,
                'target_url' => $tracker->target_url,
                'last_checked_at' => $tracker->last_checked_at?->toIso8601String(),
                'last_status' => (string) ($tracker->last_status ?? ''),
                'depth' => (int) ($tracker->depth ?? 100),
                'country' => $tracker->country,
                'device' => $tracker->device,
                'is_active' => (bool) $tracker->is_active,
            ] : null,
            'latest_snapshot' => $snapshot ? [
                'position' => $snapshot->position !== null ? (float) $snapshot->position : null,
                'url' => $snapshot->url,
                'checked_at' => $snapshot->checked_at?->toIso8601String(),
            ] : null,
            'related_searches' => $signals['related_searches'],
            'paa' => $signals['paa'],
            'flags' => $signals['flags'],
            'projected_clicks' => $signals['projections']['projected_clicks'],
        ]);
    }

    /**
     * GSC keywords directory — every query the site has impressions for in
     * the chosen range, with clicks/impressions/CTR/position aggregates.
     * Marks each one with `is_tracked` so the UI can show a "Track this"
     * shortcut for queries not yet in Rank Tracker.
     *   GET /api/v1/hq/gsc-keywords?range=30d&sort=impressions&dir=desc&page=1&search=
     */
    public function gscKeywords(Request $request): JsonResponse
    {
        $website = $this->website($request);
        [$start, $end, , , $rangeKey] = $this->resolveRange($request);

        $sort = (string) $request->query('sort', 'impressions');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $allowedSorts = ['clicks', 'impressions', 'position', 'ctr', 'query'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'impressions';
        }

        $base = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->where('query', '!=', '');

        if ($search !== '') {
            $base->where('query', 'LIKE', '%' . addcslashes($search, '\\%_') . '%');
        }

        $total = (clone $base)->distinct('query')->count('query');

        $orderBy = match ($sort) {
            'query' => 'query',
            'ctr' => '(SUM(clicks) / NULLIF(SUM(impressions), 0))',
            'position' => 'AVG(position)',
            default => "SUM($sort)",
        };

        $rows = (clone $base)
            ->selectRaw('query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position')
            ->groupBy('query')
            ->orderByRaw("$orderBy $dir")
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        // Mark which queries are already in Rank Tracker so the UI can flag
        // a "Track" button only for the rest.
        $tracked = RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->whereIn(\DB::raw('LOWER(keyword)'), $rows->pluck('query')->map(fn ($q) => mb_strtolower((string) $q))->all())
            ->pluck('keyword')
            ->map(fn ($k) => mb_strtolower((string) $k))
            ->flip();

        $items = $rows->map(fn ($r) => [
            'query'       => (string) $r->query,
            'clicks'      => (int) $r->clicks,
            'impressions' => (int) $r->impressions,
            'position'    => $r->position !== null ? round((float) $r->position, 2) : null,
            'ctr'         => $r->impressions > 0 ? round(($r->clicks / $r->impressions) * 100, 2) : 0.0,
            'is_tracked'  => $tracked->has(mb_strtolower((string) $r->query)),
        ])->all();

        return response()->json([
            'range' => ['key' => $rangeKey, 'start' => $start, 'end' => $end],
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    /**
     * Site-pages directory with per-page GSC aggregates over the chosen range.
     *   GET /api/v1/hq/pages?range=30d&sort=clicks&dir=desc&page=1&search=
     */
    public function pages(Request $request): JsonResponse
    {
        $website = $this->website($request);
        [$start, $end, , , $rangeKey] = $this->resolveRange($request);

        $sort = (string) $request->query('sort', 'clicks');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $allowedSorts = ['clicks', 'impressions', 'position', 'ctr'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'clicks';
        }

        $base = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->where('page', '!=', '');

        if ($search !== '') {
            $base->where('page', 'LIKE', '%' . addcslashes($search, '\\%_') . '%');
        }

        // Merge www./non-www host variants into one logical page. Sites that
        // migrated hosts have BOTH variants in GSC history; on longer ranges
        // (180d) that rendered as apparent duplicate rows in the plugin's
        // Pages table (which displays the path only).
        $normalizedPage = "REPLACE(page, '://www.', '://')";

        $total = (clone $base)->selectRaw("COUNT(DISTINCT {$normalizedPage}) AS c")->value('c');

        $rows = (clone $base)
            ->selectRaw("{$normalizedPage} AS page, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position")
            ->groupByRaw($normalizedPage)
            ->orderByRaw($sort === 'ctr' ? '(SUM(clicks) / NULLIF(SUM(impressions), 0)) ' . $dir : "$sort $dir")
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        // Crawl overlay: open-issue count per URL when the page is in the
        // crawl inventory (null = not crawled — the plugin hides the badge).
        $issueCounts = app(\App\Services\Crawler\CrawlReportService::class)
            ->findingCountsForUrls($website->id, $rows->pluck('page')->map(fn ($p) => (string) $p)->all());

        $rows = $rows
            ->map(fn ($row) => [
                'page' => (string) $row->page,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => $row->position !== null ? round((float) $row->position, 2) : null,
                'ctr' => $row->impressions > 0 ? round(($row->clicks / $row->impressions) * 100, 2) : 0.0,
                'crawl_issues' => $issueCounts[(string) $row->page] ?? null,
            ])
            ->all();

        return response()->json([
            'range' => ['key' => $rangeKey, 'start' => $start, 'end' => $end],
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    /**
     * Index status directory.
     *
     * Verdict source order: an explicit URL Inspection verdict (PASS / FAIL /
     * PARTIAL / NEUTRAL written by the nightly job or the Refresh status
     * button) wins. Otherwise we derive `PASS` from Search Console
     * performance data — any URL that has ≥1 impression in the GSC keyword
     * window is, by definition, indexed. This keeps the directory full
     * without burning the 2k/day URL Inspection quota.
     *
     *   GET /api/v1/hq/index-status?status=PASS|PARTIAL|FAIL&page=1
     */
    public function indexStatus(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $status = strtoupper((string) ($request->input('status', $request->query('status', ''))));
        $perPage = max(10, min(100, (int) ($request->input('per_page', $request->query('per_page', 25)))));
        $page = max(1, (int) ($request->input('page', $request->query('page', 1))));
        $search = trim((string) ($request->input('search', $request->query('search', ''))));
        $sitemapUrls = $this->parseSitemapUrls($request->input('sitemap_urls', []));

        $windowFrom = $website->gscKeywordWindowStartDate();
        $windowDays = $website->effectiveGscKeywordLookbackDays();

        // "Has Google ever surfaced this URL?" — full historical sweep, no
        // date filter. A page indexed by Google but with no impressions in
        // the recent keyword window still belongs in the directory.
        $indexedPages = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->where('page', '!=', '')
            ->where('impressions', '>', 0)
            ->select('page')
            ->distinct()
            ->pluck('page')
            ->all();
        $indexedPagesNormSet = [];
        foreach ($indexedPages as $gscPage) {
            $indexedPagesNormSet[UrlNormalizer::normalize($gscPage)] = true;
        }

        // Recent-window impressions only feed the per-row "Impressions (Nd)"
        // display — they're not used for the indexed/not-indexed decision.
        $recentImpressionsByPage = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->whereDate('date', '>=', $windowFrom)
            ->where('page', '!=', '')
            ->selectRaw('page, SUM(impressions) AS impressions')
            ->groupBy('page')
            ->pluck('impressions', 'page');

        $recentImpressionsByNorm = [];
        foreach ($recentImpressionsByPage as $gscPage => $impressions) {
            $normKey = UrlNormalizer::normalize((string) $gscPage);
            $recentImpressionsByNorm[$normKey] = ($recentImpressionsByNorm[$normKey] ?? 0) + (int) $impressions;
        }

        $pisRows = PageIndexingStatus::query()
            ->where('website_id', $website->id)
            ->get();

        $pisByNormalized = $this->insightResolver->indexingStatusesByNormalizedPage($pisRows);

        $sitemapNormSet = [];
        foreach ($sitemapUrls as $sitemapUrl) {
            if ($sitemapUrl === '') {
                continue;
            }
            $sitemapNormSet[UrlNormalizer::normalize($sitemapUrl)] = true;
        }

        $pages = collect($this->insightResolver->dedupePageUrlsForIndexStatus($indexedPages, $pisByNormalized, $sitemapUrls));

        $merged = $pages->map(function (string $pageUrl) use ($pisByNormalized, $recentImpressionsByNorm, $indexedPagesNormSet, $sitemapNormSet, $windowDays) {
            $norm = UrlNormalizer::normalize($pageUrl);
            /** @var PageIndexingStatus|null $pis */
            $pis = $pisByNormalized->get($norm);
            $recentImpressions = (int) ($recentImpressionsByNorm[$norm] ?? 0);
            $hasGscImpressions = isset($indexedPagesNormSet[$norm]);
            $inSitemap = isset($sitemapNormSet[$norm]);

            $explicitVerdict = $pis?->google_verdict;
            if (is_string($explicitVerdict) && $explicitVerdict !== '') {
                $verdict = strtoupper($explicitVerdict);
                $verdictSource = 'url_inspection';
            } elseif ($hasGscImpressions) {
                $verdict = 'PASS';
                $verdictSource = 'impressions';
            } else {
                $verdict = null;
                $verdictSource = null;
            }

            return [
                'page' => $pageUrl,
                'verdict' => $verdict,
                'verdict_source' => $verdictSource,
                'coverage_state' => $pis?->google_coverage_state,
                'indexing_state' => $pis?->google_indexing_state,
                'last_crawl_at' => $pis?->google_last_crawl_at?->toIso8601String(),
                'last_checked_at' => $pis?->last_google_status_checked_at?->toIso8601String(),
                'last_reindex_requested_at' => $pis?->last_reindex_requested_at?->toIso8601String(),
                'impressions' => $recentImpressions,
                'impressions_window_days' => $windowDays,
                'in_sitemap' => $inSitemap,
                'has_gsc_impressions' => $hasGscImpressions,
                '_sort_priority' => $this->indexStatusSortPriority($verdict, $hasGscImpressions, $inSitemap),
                '_sort_checked_at' => $pis?->last_google_status_checked_at?->getTimestamp() ?? 0,
            ];
        });

        $verdictCounts = [
            'PASS' => 0,
            'PARTIAL' => 0,
            'FAIL' => 0,
            'NEUTRAL' => 0,
            'UNKNOWN' => 0,
        ];
        foreach ($merged as $row) {
            $key = $row['verdict'] ?? 'UNKNOWN';
            if (! array_key_exists($key, $verdictCounts)) {
                $key = 'UNKNOWN';
            }
            $verdictCounts[$key]++;
        }

        $filtered = $merged;

        if (in_array($status, ['PASS', 'PARTIAL', 'FAIL', 'NEUTRAL'], true)) {
            $filtered = $filtered->filter(fn (array $r) => $r['verdict'] === $status)->values();
        } elseif ($status === 'UNKNOWN') {
            $filtered = $filtered->filter(fn (array $r) => $r['verdict'] === null)->values();
        } elseif ($status === 'NEEDS_INDEX') {
            $filtered = $filtered->filter(function (array $r) {
                if (in_array($r['verdict'], ['FAIL', 'PARTIAL'], true)) {
                    return true;
                }

                return $r['verdict'] === null && ! ($r['has_gsc_impressions'] ?? false);
            })->values();
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $filtered = $filtered->filter(fn (array $r) => str_contains(mb_strtolower($r['page']), $needle))->values();
        }

        $sorted = $filtered
            ->sortBy('page')
            ->sortBy('_sort_checked_at')
            ->sortBy('_sort_priority')
            ->values();

        $total = $sorted->count();
        $sliced = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $data = $sliced->map(function (array $r) {
            unset($r['_sort_checked_at'], $r['_sort_priority']);

            return $r;
        })->all();

        return response()->json([
            'verdict_counts' => $verdictCounts,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'status' => $status ?: null,
                'sitemap_url_count' => count($sitemapUrls),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseSitemapUrls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $urls = [];
        foreach ($raw as $url) {
            if (! is_string($url)) {
                continue;
            }
            $trimmed = trim($url);
            if ($trimmed !== '') {
                $urls[] = $trimmed;
            }
        }

        return array_values(array_unique($urls));
    }

    private function indexStatusSortPriority(?string $verdict, bool $hasGscImpressions, bool $inSitemap): int
    {
        if ($verdict === 'FAIL') {
            return 0;
        }
        if ($verdict === 'PARTIAL') {
            return 1;
        }
        if ($verdict === null && ! $hasGscImpressions) {
            return $inSitemap ? 2 : 3;
        }
        if ($verdict === 'NEUTRAL') {
            return 4;
        }

        return 5;
    }

    /**
     * Submit (or resubmit) a single URL to the Google Indexing API. Mirrors
     * the EBQ.io Page Detail "Request reindex" button so the WP plugin can
     * trigger reindex requests without bouncing out to the SaaS UI.
     *
     * Authenticates with the website-owner's Google account; the Indexing
     * API itself caps at ~200 requests / day / project, which Google
     * enforces — we pass the error through.
     *
     *   POST /api/v1/hq/index-status/submit  { "url": "https://..." }
     */
    public function indexStatusSubmit(Request $request, GoogleClientFactory $googleClientFactory): JsonResponse
    {
        $website = $this->website($request);
        $url = trim((string) $request->input('url', ''));

        if ($url === '' || ! $website->isAuditUrlForThisSite($url)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_url',
                'message' => 'URL is missing or does not belong to this website.',
            ], 422);
        }

        $account = $website->gscAccountResolved();
        if (! $account) {
            return response()->json([
                'ok' => false,
                'error' => 'not_connected',
                'message' => 'Connect a Google account in Serfix to submit URLs for indexing.',
            ], 400);
        }

        try {
            $client = $googleClientFactory->make($account);
            $accessToken = (string) ($client->getAccessToken()['access_token'] ?? '');
            if ($accessToken === '') {
                return response()->json([
                    'ok' => false,
                    'error' => 'no_access_token',
                    'message' => 'Google access token is missing — reconnect Google in Serfix.',
                    'needs_google_reconnect' => true,
                ], 400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'token_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $storedPage = $this->insightResolver->resolveStoredPageUrl($website, $url);

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
                'url' => $storedPage,
                'type' => 'URL_UPDATED',
            ]);

        if (! $response->successful()) {
            $msg = (string) data_get($response->json(), 'error.message', 'Google rejected the request.');
            $needsReconnect = str_contains(strtolower($msg), 'insufficient');

            return response()->json([
                'ok' => false,
                'error' => 'google_error',
                'message' => $msg,
                'status' => $response->status(),
                'needs_google_reconnect' => $needsReconnect,
            ], 502);
        }

        $notifyTime = data_get($response->json(), 'urlNotificationMetadata.latestUpdate.notifyTime');
        $parsed = is_string($notifyTime) ? Carbon::parse($notifyTime) : now();

        PageIndexingStatus::query()->updateOrCreate(
            ['website_id' => $website->id, 'page' => $storedPage],
            ['last_reindex_requested_at' => $parsed],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Reindex request submitted to Google. Processing is not guaranteed and may take time.',
            'page' => $storedPage,
            'last_reindex_requested_at' => $parsed->toIso8601String(),
        ]);
    }

    /**
     * Live URL Inspection recheck — mirrors EBQ.io Page Detail "Refresh status".
     * Fetches the latest verdict / coverage / crawl time from Search Console.
     *
     *   POST /api/v1/hq/index-status/recheck  { "url": "https://..." }
     */
    public function indexStatusRecheck(Request $request, GoogleClientFactory $googleClientFactory): JsonResponse
    {
        $website = $this->website($request);
        $url = trim((string) $request->input('url', ''));

        if ($url === '' || ! $website->isAuditUrlForThisSite($url)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_url',
                'message' => 'URL is missing or does not belong to this website.',
            ], 422);
        }

        if ($website->gsc_site_url === '') {
            return response()->json([
                'ok' => false,
                'error' => 'no_gsc_property',
                'message' => 'Connect a Search Console property for this site in Serfix.',
            ], 400);
        }

        $account = $website->gscAccountResolved();
        if (! $account) {
            return response()->json([
                'ok' => false,
                'error' => 'not_connected',
                'message' => 'Connect a Google account in Serfix to recheck indexing status.',
            ], 400);
        }

        try {
            $client = $googleClientFactory->make($account);
            $accessToken = (string) ($client->getAccessToken()['access_token'] ?? '');
            if ($accessToken === '') {
                return response()->json([
                    'ok' => false,
                    'error' => 'no_access_token',
                    'message' => 'Google access token is missing — reconnect Google in Serfix.',
                    'needs_google_reconnect' => true,
                ], 400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'token_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $storedPage = $this->insightResolver->resolveStoredPageUrl($website, $url);

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $storedPage,
                'siteUrl' => $website->gsc_site_url,
                'languageCode' => 'en-US',
            ]);

        if (! $response->successful()) {
            $msg = (string) data_get($response->json(), 'error.message', 'Failed to fetch indexing status from Google.');
            $needsReconnect = str_contains(strtolower($msg), 'insufficient');

            return response()->json([
                'ok' => false,
                'error' => 'google_error',
                'message' => $needsReconnect ? $msg.' Reconnect Google to grant required Search Console scope.' : $msg,
                'status' => $response->status(),
                'needs_google_reconnect' => $needsReconnect,
            ], 502);
        }

        $indexStatus = (array) data_get($response->json(), 'inspectionResult.indexStatusResult', []);
        $lastCrawlAt = data_get($indexStatus, 'lastCrawlTime');
        $verdict = data_get($indexStatus, 'verdict');
        $checkedAt = now();

        $row = PageIndexingStatus::query()->updateOrCreate(
            ['website_id' => $website->id, 'page' => $storedPage],
            [
                'last_google_status_checked_at' => $checkedAt,
                'google_verdict' => is_string($verdict) ? $verdict : null,
                'google_coverage_state' => data_get($indexStatus, 'coverageState'),
                'google_indexing_state' => data_get($indexStatus, 'indexingState'),
                'google_last_crawl_at' => is_string($lastCrawlAt) ? Carbon::parse($lastCrawlAt) : null,
                'google_status_payload' => $indexStatus,
            ],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Google indexing status refreshed.',
            'page' => $storedPage,
            'verdict' => is_string($verdict) && $verdict !== '' ? strtoupper($verdict) : null,
            'verdict_source' => 'url_inspection',
            'coverage_state' => $row->google_coverage_state,
            'indexing_state' => $row->google_indexing_state,
            'last_crawl_at' => $row->google_last_crawl_at?->toIso8601String(),
            'last_checked_at' => $checkedAt->toIso8601String(),
            'last_reindex_requested_at' => $row->last_reindex_requested_at?->toIso8601String(),
        ]);
    }

    /**
     * Opportunity feed — cannibalizations, striking-distance keywords, content
     * decay, indexing fails with traffic, and quick wins. Each `type` returns
     * up to 50 rows; the WP UI renders a tab per type.
     *   GET /api/v1/hq/insights/{type}?limit=50
     */
    public function insights(Request $request, string $type): JsonResponse
    {
        $website = $this->website($request);
        $limit = max(5, min(100, (int) $request->query('limit', 25)));

        $payload = match ($type) {
            'cannibalization' => ['rows' => $this->reports->cannibalizationReport($website->id, null, null, $limit)],
            'striking' => ['rows' => $this->reports->strikingDistance($website->id, null, null, $limit)],
            'decay' => $this->reports->contentDecay($website->id, $limit),
            'index_fails' => ['rows' => $this->reports->indexingFailsWithTraffic($website->id, 14, $limit)],
            'quick_wins' => ['rows' => $this->reports->quickWins($website->id, $limit)],
            'audit_performance' => ['rows' => app(\App\Services\AuditPerformanceService::class)->underperformingPages($website->id, 28, $limit)],
            'backlink_impact' => ['rows' => app(\App\Services\BacklinkImpactService::class)->impactByTargetPage($website->id, 28, $limit)],
            default => abort(404),
        };

        return response()->json([
            'type' => $type,
            'website_domain' => $website->domain,
            'payload' => $payload,
        ]);
    }

    /**
     * Insight category counts for the HQ Reports tab tiles.
     *   GET /api/v1/hq/insight-counts
     */
    public function insightCounts(Request $request): JsonResponse
    {
        $website = $this->website($request);

        return response()->json([
            'counts' => $this->reports->insightCounts($website->id),
        ]);
    }

    /**
     * Growth report preview — same payload as EBQ Reports → Custom report.
     *   GET /api/v1/hq/growth-report?report_type=weekly&start_date=&end_date=&country=
     */
    public function growthReport(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $dates = $this->resolveGrowthReportRequest($request);

        if (isset($dates['error'])) {
            return response()->json($dates, 422);
        }

        $country = $dates['country'] !== '' ? strtoupper($dates['country']) : null;

        return response()->json([
            'ok' => true,
            'domain' => $website->domain,
            'report_type' => $dates['report_type'],
            'start_date' => $dates['start_date'],
            'end_date' => $dates['end_date'],
            'country' => $country,
            'report' => $this->reports->generate(
                $website->id,
                $dates['start_date'],
                $dates['end_date'],
                $country,
            ),
        ]);
    }

    /**
     * Email growth report to configured recipients (MOAT: mail + data on EBQ).
     *   POST /api/v1/hq/growth-report/send
     */
    public function growthReportSend(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $dates = $this->resolveGrowthReportRequest($request);

        if (isset($dates['error'])) {
            return response()->json($dates, 422);
        }

        $rateKey = 'hq-growth-report:'.$website->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'Too many report emails sent. Try again later.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }

        $recipients = $website->getReportRecipientUsers();
        if ($recipients->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'no_recipients',
                'message' => 'No report recipients configured for this website in Serfix.',
            ], 422);
        }

        $owner = $website->user;
        if ($owner === null) {
            return response()->json([
                'ok' => false,
                'error' => 'no_owner',
                'message' => 'Website owner not found.',
            ], 422);
        }

        try {
            $emails = [];
            foreach ($recipients as $recipient) {
                app(\App\Services\Reports\ReportMailDispatcher::class)->send(
                    $recipient,
                    $website,
                    $dates['start_date'],
                    $dates['end_date'],
                    $dates['report_type'],
                );
                $emails[] = $recipient->email;
            }

            RateLimiter::hit($rateKey, 3600);

            return response()->json([
                'ok' => true,
                'message' => ucfirst($dates['report_type']).' report for '.$website->domain.' sent to '.implode(', ', $emails).'.',
                'recipients' => $emails,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => 'send_failed',
                'message' => 'Could not send the report. Check mail configuration on Serfix.',
            ], 502);
        }
    }

    /**
     * GSC keyword + SERP country suggestions for the HQ SEO Analysis form.
     *   GET /api/v1/hq/page-audit/suggestions?page_url=https://…
     */
    public function pageAuditSuggestions(Request $request, PageAuditService $pageAuditService): JsonResponse
    {
        $website = $this->website($request);
        $pageUrl = $this->normalizeAuditPageUrl((string) $request->query('page_url', ''));

        if ($pageUrl === '') {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_url',
                'message' => 'Enter a page URL on your connected domain.',
            ], 422);
        }

        if (! $website->isAuditUrlForThisSite($pageUrl)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_domain',
                'message' => 'The URL must use your website domain ('.$website->domain.') or a subdomain of it.',
            ], 422);
        }

        $keywords = $this->insightResolver->focusKeywordSuggestions($website, $pageUrl, 12);

        $peek = $pageAuditService->peekSerpCountryChoiceNeeded($website->id, $pageUrl, true);
        $recommendedGl = 'us';
        $hint = '';
        if (($peek['ok'] ?? false) === true) {
            $recommendedGl = (string) ($peek['recommended_gl'] ?? 'us');
            if (! SerpGlCatalog::isAllowedGl($recommendedGl)) {
                $recommendedGl = 'us';
            }
            $hint = (string) ($peek['recommendation_hint'] ?? '');
        }

        return response()->json([
            'ok' => true,
            'page_url' => $pageUrl,
            'keywords' => $keywords,
            'country' => $this->serpCountryOptionsPayload($recommendedGl, $hint),
        ]);
    }

    /**
     * SERP country catalog for the HQ SEO Analysis form (no page URL required).
     *   GET /api/v1/hq/page-audit/countries
     */
    public function pageAuditCountries(Request $request): JsonResponse
    {
        $this->website($request);

        return response()->json([
            'ok' => true,
            'country' => $this->serpCountryOptionsPayload('us', ''),
        ]);
    }

    /**
     * Queue a full page audit from the WordPress HQ SEO Analysis tab.
     * Mirrors CustomAudit::queueAudit — first POST without confirm_country
     * returns needs_country; second POST with serp_country_gl queues the job.
     *
     *   POST /api/v1/hq/page-audit
     */
    public function pageAuditQueue(Request $request, PageAuditService $pageAuditService): JsonResponse
    {
        $website = $this->website($request);
        $owner = $website->owner;
        if ($owner === null) {
            return response()->json(['ok' => false, 'error' => 'no_owner'], 404);
        }

        $data = $request->validate([
            'page_url' => 'required|string|max:2000',
            'target_keyword' => 'required|string|min:2|max:200',
            'serp_country_gl' => 'nullable|string|size:2',
            'confirm_country' => 'nullable|boolean',
        ]);

        $pageUrl = $this->normalizeAuditPageUrl($data['page_url']);
        $keyword = trim($data['target_keyword']);

        if ($pageUrl === '' || ! $website->isAuditUrlForThisSite($pageUrl)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_domain',
                'message' => 'The URL must use your website domain ('.$website->domain.') or a subdomain of it.',
            ], 422);
        }

        $confirmCountry = (bool) ($data['confirm_country'] ?? false);

        if (! $confirmCountry) {
            $peek = $pageAuditService->peekSerpCountryChoiceNeeded($website->id, $pageUrl, true);
            if (! ($peek['ok'] ?? false)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'peek_failed',
                    'message' => $peek['error'] ?? 'Could not read the page to detect locale.',
                ], 422);
            }

            $recommendedGl = (string) ($peek['recommended_gl'] ?? 'us');
            if (! SerpGlCatalog::isAllowedGl($recommendedGl)) {
                $recommendedGl = 'us';
            }

            return response()->json([
                'ok' => true,
                'needs_country' => true,
                'recommended_gl' => $recommendedGl,
                'recommendation_hint' => (string) ($peek['recommendation_hint'] ?? ''),
                'message' => 'Confirm the Google SERP country, then run the analysis again.',
            ]);
        }

        $serpGl = strtolower(trim((string) ($data['serp_country_gl'] ?? '')));
        if (! SerpGlCatalog::isAllowedGl($serpGl)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_country',
                'message' => 'Pick a valid country for the SERP sample.',
            ], 422);
        }

        $active = CustomPageAudit::findActiveFor($website->id, $pageUrl, $owner->id);
        if ($active instanceof CustomPageAudit) {
            return response()->json([
                'ok' => true,
                'already_queued' => true,
                'audit' => $this->formatPageAuditRow($active),
                'message' => 'An analysis for this URL is already queued or running.',
            ]);
        }

        $rateKey = 'custom-audit:'.$owner->id;
        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => "Too many analyses. Try again in {$seconds}s.",
            ], 429);
        }
        RateLimiter::hit($rateKey, 120);

        $audit = CustomPageAudit::queue(
            websiteId: $website->id,
            userId: $owner->id,
            pageUrl: $pageUrl,
            targetKeyword: $keyword,
            serpSampleGl: $serpGl,
            source: CustomPageAudit::SOURCE_HQ_WP,
        );

        RunCustomPageAudit::dispatch($audit->id, $audit->website_id);

        return response()->json([
            'ok' => true,
            'audit' => $this->formatPageAuditRow($audit->fresh(['pageAuditReport'])),
            'message' => 'Analysis queued — open the report on Serfix when it completes.',
        ], 201);
    }

    /**
     * Recent page audits for this website (HQ SEO Analysis history).
     *   GET /api/v1/hq/page-audits
     */
    public function pageAudits(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $rows = CustomPageAudit::query()
            ->where('website_id', $website->id)
            ->portalHistory()
            ->with('pageAuditReport:id,status,result,http_status,response_time_ms,page_size_bytes,audited_at')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (CustomPageAudit $row) => $this->formatPageAuditRow($row))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'audits' => $rows,
            'has_pending' => collect($rows)->contains(fn (array $r) => in_array($r['status'], ['queued', 'running'], true)),
        ]);
    }

    /**
     * Signed URL to view a completed audit on EBQ.io (full report UI — MOAT).
     *   GET /api/v1/hq/page-audits/{id}/report-url
     */
    public function pageAuditReportUrl(Request $request, string $id): JsonResponse
    {
        $website = $this->website($request);

        $audit = CustomPageAudit::query()
            ->where('website_id', $website->id)
            ->with('pageAuditReport')
            ->find($id);

        if ($audit === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        if ($audit->status !== CustomPageAudit::STATUS_COMPLETED || $audit->page_audit_report_id === null) {
            return response()->json([
                'ok' => false,
                'error' => 'not_ready',
                'message' => 'The report is not ready yet.',
            ], 409);
        }

        $publicRoot = (string) config('services.ebq.public_url', 'https://ebq.io');
        $previousRoot = config('app.url');
        if ($publicRoot !== '' && $publicRoot !== rtrim((string) $previousRoot, '/')) {
            URL::forceRootUrl($publicRoot);
        }

        try {
            $signed = URL::temporarySignedRoute(
                'wordpress.embed.page-audit',
                Carbon::now()->addMinutes(20),
                [
                    'website' => $website->id,
                    'report' => (string) $audit->page_audit_report_id,
                ],
            );
        } finally {
            if ($publicRoot !== '' && $publicRoot !== rtrim((string) $previousRoot, '/')) {
                URL::forceRootUrl(rtrim((string) $previousRoot, '/'));
            }
        }

        return response()->json([
            'ok' => true,
            'url' => $signed,
            'expires_at' => Carbon::now()->addMinutes(20)->toIso8601String(),
        ]);
    }

    /**
     * Phase 3 #6 — SERP-feature presence timeline for tracked keywords.
     *   GET /api/v1/hq/serp-features?days=30
     */
    public function serpFeatures(Request $request, SerpFeatureTrackerService $service): JsonResponse
    {
        $website = $this->website($request);
        $days = max(1, min(365, (int) $request->query('days', 30)));
        return response()->json($service->forWebsite($website, $days));
    }

    /**
     * Phase 3 #10 — Backlink prospecting. Caller passes competitor
     * domains (typically pulled from the Pages tab's audit benchmarks).
     *   POST /api/v1/hq/backlink-prospects
     *   body: { competitors: ["domain1.com", "domain2.com"] }
     */
    public function backlinkProspects(Request $request, BacklinkProspectingService $service): JsonResponse
    {
        $website = $this->website($request);
        $data = $request->validate([
            'competitors' => 'required|array|min:1|max:20',
            'competitors.*' => 'string|max:255',
        ]);
        return response()->json($service->prospect($website, $data['competitors']));
    }

    /**
     * Pro-tier draft outreach for a single prospect.
     *   POST /api/v1/hq/backlink-prospects/draft
     *   body: { prospect: {...}, our_page_url, our_page_title, our_page_summary }
     */
    public function backlinkOutreachDraft(Request $request, BacklinkProspectingService $service): JsonResponse
    {
        $website = $this->website($request);
        if ($gate = $website->featureGateInfo('ai_writer')) {
            return response()->json($gate + [
                'message' => 'AI outreach drafting is on Pro.',
            ], 402);
        }

        $data = $request->validate([
            'prospect' => 'required|array',
            'prospect.domain' => 'required|string|max:255',
            'prospect.linked_to' => 'nullable|array',
            'our_page_url' => 'required|string|max:2048',
            'our_page_title' => 'nullable|string|max:300',
            'our_page_summary' => 'nullable|string|max:2000',
        ]);

        return response()->json($service->draftOutreach($data['prospect'], [
            'our_page_url' => $data['our_page_url'],
            'our_page_title' => $data['our_page_title'] ?? '',
            'our_page_summary' => $data['our_page_summary'] ?? '',
            'website_id' => $website->id,
        ]));
    }

    /**
     * Auto-discover competitors from this site's last 30 days of audits
     * and run the prospect engine against them. Idempotent — safe to call
     * on every tab open or from a manual "Refresh from audits" button.
     *   POST /api/v1/hq/outreach-prospects/auto-discover?days=30
     */
    public function outreachProspectsAutoDiscover(Request $request, BacklinkProspectingService $service): JsonResponse
    {
        $website = $this->website($request);
        $days = (int) $request->query('days', 30);
        return response()->json($service->autoDiscoverFromAudits($website, max(1, min(90, $days))));
    }

    /**
     * Persisted prospect list — what the HQ tab loads on open.
     *   GET /api/v1/hq/outreach-prospects?status=new
     */
    public function outreachProspectsList(Request $request, BacklinkProspectingService $service): JsonResponse
    {
        $website = $this->website($request);
        $status = (string) $request->query('status', '');
        return response()->json($service->listSaved($website, $status !== '' ? $status : null));
    }

    /**
     * Update one prospect's status / notes from the HQ tab.
     *   POST /api/v1/hq/outreach-prospects/{id}
     *   body: { status?, notes? }
     */
    public function outreachProspectsUpdate(Request $request, string $id, BacklinkProspectingService $service): JsonResponse
    {
        $website = $this->website($request);
        $data = $request->validate([
            'status' => 'nullable|in:new,drafted,contacted,replied,converted,declined,snoozed',
            'notes' => 'nullable|string|max:4000',
        ]);
        $prospect = $service->updateProspect($website, $id, $data);
        if (! $prospect) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return response()->json(['ok' => true, 'id' => $prospect->id, 'status' => $prospect->status]);
    }

    /**
     * Phase 3 #4 — Topical authority map. Clusters GSC queries by token
     * co-occurrence + page sharing, scores each cluster, surfaces gaps.
     *   GET /api/v1/hq/topical-authority
     */
    public function topicalAuthority(Request $request, TopicalAuthorityService $service): JsonResponse
    {
        $website = $this->website($request);
        return response()->json($service->map($website));
    }

    /**
     * Phase 3 #7 — Cross-site anonymized benchmarks. Compares this
     * site's GSC averages against the global EBQ network cohort and
     * (optionally) a per-country cohort.
     *   GET /api/v1/hq/benchmarks?country=us
     */
    public function crossSiteBenchmarks(Request $request, CrossSiteBenchmarkService $service): JsonResponse
    {
        $website = $this->website($request);
        $country = (string) $request->query('country', '');
        return response()->json($service->forWebsite($website, $country !== '' ? $country : null));
    }

    /* ─── Helpers ──────────────────────────────────────────── */

    private function normalizeAuditPageUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        return $raw;
    }

    /**
     * @return array{recommended_gl: string, hint: string, options: list<array{code: string, label: string}>}
     */
    private function serpCountryOptionsPayload(string $recommendedGl, string $hint = ''): array
    {
        $gl = strtolower(trim($recommendedGl));
        if (! SerpGlCatalog::isAllowedGl($gl)) {
            $gl = 'us';
        }

        $countries = [];
        foreach (SerpGlCatalog::selectOptions() as $code => $label) {
            $countries[] = ['code' => $code, 'label' => $label];
        }

        return [
            'recommended_gl' => $gl,
            'hint' => $hint,
            'options' => $countries,
        ];
    }

    private function formatPageAuditRow(CustomPageAudit $row): array
    {
        $out = [
            'id' => $row->id,
            'status' => (string) $row->status,
            'page_url' => (string) $row->page_url,
            'target_keyword' => (string) $row->target_keyword,
            'serp_country_gl' => (string) ($row->serp_sample_gl ?? ''),
            'page_audit_report_id' => $row->page_audit_report_id,
            'error_message' => $row->error_message,
            'created_at' => $row->created_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
        ];

        if ($row->started_at && $row->finished_at) {
            $out['duration_sec'] = max(0, $row->started_at->diffInSeconds($row->finished_at));
        }

        if ($row->isCompleted() && $row->relationLoaded('pageAuditReport') && $row->pageAuditReport) {
            $summary = \App\Support\Audit\PageAuditReportSummary::fromReport($row->pageAuditReport);
            if ($summary !== null) {
                $out['summary'] = $summary;
            }
        }

        return $out;
    }

    private function website(Request $request): Website
    {
        $w = $request->attributes->get('api_website');
        abort_unless($w instanceof Website, 500, 'Website context missing');
        return $w;
    }

    /**
     * @return array{report_type: string, start_date: string, end_date: string, country: string}|array{error: string, message: string}
     */
    private function resolveGrowthReportRequest(Request $request): array
    {
        $reportType = (string) ($request->input('report_type') ?? $request->query('report_type', 'weekly'));
        if (! in_array($reportType, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            return ['error' => 'invalid_report_type', 'message' => 'Invalid report type.'];
        }

        $tz = config('app.timezone');
        $endDate = (string) ($request->input('end_date') ?? $request->query('end_date', ''));
        $startDate = (string) ($request->input('start_date') ?? $request->query('start_date', ''));

        if ($endDate === '' || $startDate === '') {
            $end = Carbon::yesterday($tz)->toDateString();
            $start = match ($reportType) {
                'daily' => $end,
                'weekly' => Carbon::parse($end, $tz)->subDays(6)->toDateString(),
                'monthly' => Carbon::parse($end, $tz)->subDays(29)->toDateString(),
                default => Carbon::parse($end, $tz)->subDays(6)->toDateString(),
            };
            $endDate = $end;
            $startDate = $start;
        }

        if (Carbon::parse($startDate)->gt(Carbon::parse($endDate))) {
            return ['error' => 'invalid_range', 'message' => 'Start date must be before or equal to end date.'];
        }

        $country = strtoupper(trim((string) ($request->input('country') ?? $request->query('country', ''))));

        return [
            'report_type' => $reportType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'country' => $country,
        ];
    }

    /**
     * Returns [$start, $end, $prevStart, $prevEnd, $rangeKey] as date strings.
     */
    private function resolveRange(Request $request): array
    {
        $key = (string) $request->query('range', '30d');
        $days = match ($key) {
            '7d' => 7,
            '90d' => 90,
            '180d' => 180,
            default => 30,
        };
        $tz = config('app.timezone');
        $end = Carbon::yesterday($tz);
        $start = $end->copy()->subDays($days - 1);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return [
            $start->toDateString(),
            $end->toDateString(),
            $prevStart->toDateString(),
            $prevEnd->toDateString(),
            $key === '7d' || $key === '30d' || $key === '90d' || $key === '180d' ? $key : '30d',
        ];
    }

    /**
     * @return array{clicks:int, impressions:int, ctr:float, avg_position:float|null}
     */
    private function aggregateGsc(string $websiteId, string $start, string $end): array
    {
        $row = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position')
            ->first();

        $clicks = (int) ($row->clicks ?? 0);
        $impressions = (int) ($row->impressions ?? 0);
        $position = $row && $row->position !== null ? (float) $row->position : null;
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'avg_position' => $position];
    }

    /**
     * Build the {value, prev, change_pct, direction} triple. `invert: true`
     * for metrics where lower = better (avg position) so the up/down arrow
     * reflects user expectation, not raw delta sign.
     */
    private function kpiTriple($current, $previous, bool $invert = false, int $decimals = 0, string $suffix = ''): array
    {
        $current = $current === null ? null : ($decimals > 0 ? round((float) $current, $decimals) : (int) $current);
        $previous = $previous === null ? null : ($decimals > 0 ? round((float) $previous, $decimals) : (int) $previous);

        $changePct = null;
        $direction = 'flat';
        if ($previous !== null && $current !== null && $previous != 0) {
            $changePct = round((($current - $previous) / abs($previous)) * 100, 1);
            if ($changePct > 0.5) $direction = $invert ? 'down' : 'up';
            elseif ($changePct < -0.5) $direction = $invert ? 'up' : 'down';
        }

        return [
            'value' => $current,
            'previous' => $previous,
            'change_pct' => $changePct,
            'direction' => $direction,
            'suffix' => $suffix,
        ];
    }

    /**
     * Counts of unique queries by SERP-position slab over the date window.
     * The bucket math runs in MySQL so PHP never sees the per-query rows;
     * critical on sites with tens of thousands of unique queries.
     *
     * @return array{top_3:int, top_10:int, top_50:int, top_100:int}
     */
    private function positionDistribution(string $websiteId, string $start, string $end): array
    {
        $row = SearchConsoleData::query()
            ->fromSub(
                fn ($q) => $q
                    ->from('search_console_data')
                    ->selectRaw('AVG(position) AS pos')
                    ->where('website_id', $websiteId)
                    ->whereBetween('date', [$start, $end])
                    ->where('query', '!=', '')
                    ->groupBy('query'),
                't'
            )
            ->selectRaw('
                SUM(CASE WHEN pos <=  3 THEN 1 ELSE 0 END) AS top_3,
                SUM(CASE WHEN pos >  3 AND pos <= 10 THEN 1 ELSE 0 END) AS top_10,
                SUM(CASE WHEN pos > 10 AND pos <= 50 THEN 1 ELSE 0 END) AS top_50,
                SUM(CASE WHEN pos > 50 AND pos <= 100 THEN 1 ELSE 0 END) AS top_100
            ')
            ->first();

        return [
            'top_3'   => (int) ($row->top_3   ?? 0),
            'top_10'  => (int) ($row->top_10  ?? 0),
            'top_50'  => (int) ($row->top_50  ?? 0),
            'top_100' => (int) ($row->top_100 ?? 0),
        ];
    }

    /**
     * Tracker-side position distribution — counts of *tracked* keywords (the
     * ones the user explicitly added to Rank Tracker) by current SERP rank
     * slab. This is the "true rank" view, distinct from GSC's averaged
     * report. `pending` covers keywords queued but not yet checked.
     *
     * @return array{top_3:int, top_10:int, top_50:int, top_100:int, deep:int, pending:int}
     */
    private function trackerPositionDistribution(string $websiteId): array
    {
        $rows = RankTrackingKeyword::query()
            ->where('website_id', $websiteId)
            ->where('is_active', true)
            ->get(['current_position']);

        $buckets = ['top_3' => 0, 'top_10' => 0, 'top_50' => 0, 'top_100' => 0, 'deep' => 0, 'pending' => 0];
        foreach ($rows as $r) {
            if ($r->current_position === null) {
                $buckets['pending']++;
                continue;
            }
            $pos = (float) $r->current_position;
            if ($pos <= 3) $buckets['top_3']++;
            elseif ($pos <= 10) $buckets['top_10']++;
            elseif ($pos <= 50) $buckets['top_50']++;
            elseif ($pos <= 100) $buckets['top_100']++;
            else $buckets['deep']++;
        }
        return $buckets;
    }

    /**
     * Daily click sparkline points for the given range — used by the Overview
     * card. Missing days fill as zeros so the path doesn't have gaps.
     *
     * @return list<array{date:string, clicks:int}>
     */
    private function dailyClicks(string $websiteId, string $start, string $end): array
    {
        $rows = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('date, SUM(clicks) AS clicks')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('clicks', 'date')
            ->all();

        $out = [];
        $cursor = Carbon::parse($start);
        $stop = Carbon::parse($end);
        while ($cursor <= $stop) {
            $d = $cursor->toDateString();
            $out[] = ['date' => $d, 'clicks' => (int) ($rows[$d] ?? 0)];
            $cursor->addDay();
        }
        return $out;
    }

    /**
     * Top movers — keywords whose average position improved (gainers) or
     * regressed (losers) most over the range vs the immediately preceding
     * period. Pure GSC-derived; doesn't require Rank Tracker entries.
     *
     * Single SQL pass: cur LEFT JOIN prev on query. We compute the delta
     * server-side, materialise both ordered lists in one query, then split
     * in PHP. Replaces the previous "four GROUP BYs + whereIn" approach
     * that exploded on large sites.
     *
     * @return array{gainers: list<array<string, mixed>>, losers: list<array<string, mixed>>}
     */
    private function topMoversBoth(string $websiteId, string $start, string $end, int $limit): array
    {
        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
        $prevEnd = Carbon::parse($start)->subDay()->toDateString();
        $prevStart = Carbon::parse($prevEnd)->subDays($days - 1)->toDateString();

        // One materialised result set, sorted in MySQL, then sliced in PHP.
        // We over-fetch (limit * 2) and split by sign of delta because the
        // gainer set is naturally bounded by HAVING SUM(impressions) >= 50.
        $rows = SearchConsoleData::query()
            ->fromSub(
                fn ($q) => $q
                    ->from('search_console_data')
                    ->selectRaw('query, AVG(position) AS pos, SUM(clicks) AS clicks, SUM(impressions) AS impressions')
                    ->where('website_id', $websiteId)
                    ->whereBetween('date', [$start, $end])
                    ->where('query', '!=', '')
                    ->groupBy('query')
                    ->havingRaw('SUM(impressions) >= 50'),
                'cur'
            )
            ->leftJoinSub(
                SearchConsoleData::query()
                    ->from('search_console_data')
                    ->selectRaw('query, AVG(position) AS pos')
                    ->where('website_id', $websiteId)
                    ->whereBetween('date', [$prevStart, $prevEnd])
                    ->where('query', '!=', '')
                    ->groupBy('query'),
                'prev',
                'prev.query',
                '=',
                'cur.query'
            )
            ->selectRaw('cur.query, cur.pos AS cur_pos, cur.clicks, cur.impressions, prev.pos AS prev_pos, (prev.pos - cur.pos) AS delta')
            ->whereNotNull('prev.pos')
            ->get();

        $gainers = $rows->sortByDesc('delta')->take($limit);
        $losers  = $rows->sortBy('delta')->take($limit);

        $shape = fn ($row) => [
            'keyword' => (string) $row->query,
            'position' => round((float) $row->cur_pos, 1),
            'previous_position' => round((float) $row->prev_pos, 1),
            'delta' => round((float) $row->delta, 1),
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
        ];

        return [
            'gainers' => $gainers->map($shape)->values()->all(),
            'losers'  => $losers->map($shape)->values()->all(),
        ];
    }
}
