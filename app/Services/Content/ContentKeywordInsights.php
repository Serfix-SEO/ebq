<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\KeywordApiRequest;
use App\Models\KeywordMetric;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordResearch\AiKeywordClusterService;
use App\Services\KeywordResearch\KeywordIntentClassifier;
use App\Services\KeywordResearch\KeywordTermGrouper;
use App\Services\KeywordsEverywhereClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Here's the keyword research we did for you" — the Content Autopilot
 * wizard's keyword-research step (step 5), designed to build client trust by
 * showing REAL, digested research rather than a raw keyword dump.
 *
 * Flow:
 *  1. {@see ensureStarted()} fires from a queued job at the end of wizard
 *     step 2 (right after the plan is drafted), so the self-hosted keyword
 *     server (minutes-long turnaround, concurrency 1 — see
 *     infra/keywords/keyword-finder.md) works while the user reads steps 3-4.
 *     Seeds = what the business sells + brand + GSC striking-distance
 *     queries, capped at the server's 20-seed limit. Unmetered: this is
 *     platform-driven research, not a user-initiated Keyword Finder run.
 *  2. {@see get()} builds the presentation payload once the ideas request
 *     completes: topic clusters (LLM-labeled via AiKeywordClusterService,
 *     cached; falls back to algorithmic term groups), search-intent mix,
 *     "questions your audience asks", and volume-vs-competition opportunity
 *     picks — each cross-referenced against the plan's generated topics so
 *     the client sees research feeding directly into their calendar.
 *     Cached 30 days per plan.
 *  3. Degrades gracefully at every layer: keyword server missing/failed/slow
 *     (e.g. staging has no server row; webhooks only reach prod) → after a
 *     grace window the insights are built from the plan's own topics plus
 *     any keyword_metrics volumes already cached. The step NEVER dead-ends.
 *
 * Client copy invariant: no vendor names, no pipeline internals.
 */
class ContentKeywordInsights
{
    private const CACHE_TTL_DAYS = 30;

    /** Fallback payloads may improve once real data lands — cache shorter. */
    private const FALLBACK_TTL_DAYS = 7;

    /** Keyword-server hard cap per ideas request (infra/keywords). */
    private const MAX_SEEDS = 20;

    /** Give the (concurrency-1, minutes-per-job) server this long before falling back. */
    private const PENDING_GRACE_MINUTES = 12;

    public function __construct(
        private readonly KeywordFinderPool $pool,
        private readonly AiKeywordClusterService $clusters,
        private readonly KeywordsEverywhereClient $ke,
    ) {}

    /**
     * Idempotently kick off the background ideas request for a plan.
     * Called from PrepareContentKeywordInsightsJob (never inline in a web
     * request — the dispatch POST can block up to 15s).
     */
    public function ensureStarted(ContentPlan $plan): void
    {
        if (Cache::has($this->insightsKey($plan)) || Cache::has($this->requestKey($plan))) {
            return;
        }
        $lock = 'content:kw-insights:lock:'.$plan->id;
        if (! Cache::add($lock, 1, now()->addMinutes(30))) {
            return;
        }

        $website = $plan->website;
        $seeds = $website !== null ? $this->seeds($plan, $website) : [];
        if ($seeds === []) {
            return; // fallback path will serve from topics once they exist
        }

        try {
            // Geo-target the research to the plan's country when set, so the
            // SERP/volume data reflects the audience the client actually sells
            // to (falls back to worldwide). Language is stored as a full name
            // (e.g. "English"); legacy code plans map en→English/ar→Arabic.
            $language = match (mb_strtolower(trim((string) $plan->language))) {
                '', 'en' => null, // null → keyword server's English default
                'ar' => 'Arabic',
                default => (string) $plan->language,
            };
            $request = $this->pool->dispatchIdeas(
                ['seeds' => $seeds, 'language' => $language],
                $website?->user_id,
                $website?->id,
                countryKey: $plan->country ?: null,
                meter: false, // platform-driven prefill, not the user's quota
            );
            Cache::put($this->requestKey($plan), $request->id, now()->addHours(2));
        } catch (\Throwable $e) {
            Log::warning('ContentKeywordInsights dispatch failed', [
                'plan_id' => $plan->id, 'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The presentation payload, or null while research is still pending.
     *
     * @return array{
     *   stats: array{keywords:int, volume:int, questions:int, clusters:int},
     *   clusters: list<array{label:string, count:int, volume:int, top:list<string>}>,
     *   intents: array<string, int>,
     *   questions: list<array{keyword:string, volume:?int}>,
     *   opportunities: list<array{keyword:string, volume:?int, competition:string, planned:bool}>,
     *   partial: bool
     * }|null
     */
    public function get(ContentPlan $plan): ?array
    {
        $cached = Cache::get($this->insightsKey($plan));
        if ($cached !== null) {
            return $cached;
        }

        $request = $this->request($plan);

        if ($request !== null && $request->status === KeywordApiRequest::STATUS_COMPLETED) {
            $rows = $this->normalizeResults((array) ($request->result ?? []));
            if ($rows !== []) {
                $insights = $this->build($rows, $plan, partial: false);
                Cache::put($this->insightsKey($plan), $insights, now()->addDays(self::CACHE_TTL_DAYS));
                $this->backfillTopicVolumes($plan, $rows);

                return $insights;
            }
        }

        // Failed, produced nothing, or overdue → serve from what we already
        // hold rather than dead-ending the step. No stored request at all
        // (e.g. seeds were empty, job lost) counts as overdue immediately.
        $overdue = $request === null
            || $request->status === KeywordApiRequest::STATUS_FAILED
            || ($request->created_at !== null && $request->created_at->lt(now()->subMinutes(self::PENDING_GRACE_MINUTES)));

        if ($overdue) {
            $rows = $this->fallbackRows($plan);
            if ($rows === []) {
                return null; // no topics yet either — genuinely still preparing
            }
            $insights = $this->build($rows, $plan, partial: true);
            Cache::put($this->insightsKey($plan), $insights, now()->addDays(self::FALLBACK_TTL_DAYS));

            return $insights;
        }

        return null; // pending within the grace window
    }

    /** Clear cached insights + request pointer so a refetch redispatches. */
    public function forget(ContentPlan $plan): void
    {
        Cache::forget($this->insightsKey($plan));
        Cache::forget($this->requestKey($plan));
        Cache::forget('content:kw-insights:lock:'.$plan->id);
    }

    // ── internals ───────────────────────────────────────────────────────

    private function insightsKey(ContentPlan $plan): string
    {
        return 'content:kw-insights:v1:'.$plan->id;
    }

    private function requestKey(ContentPlan $plan): string
    {
        return 'content:kw-insights:req:'.$plan->id;
    }

    private function request(ContentPlan $plan): ?KeywordApiRequest
    {
        $id = Cache::get($this->requestKey($plan));

        return $id ? KeywordApiRequest::query()->find($id) : null;
    }

    /**
     * Seed keywords for the ideas request: what the business sells (the
     * offerings the user just confirmed in step 2), the brand-less domain
     * stem, and GSC striking-distance queries where connected. Short
     * phrases only — the server expands them into the real keyword set.
     *
     * @return list<string>
     */
    private function seeds(ContentPlan $plan, Website $website): array
    {
        $seeds = [];

        foreach ((array) (($plan->offerings ?? [])['sell'] ?? []) as $item) {
            $item = mb_strtolower(trim((string) $item));
            // Offerings are sentences ("PUBG name generator tool for creating
            // stylish usernames"); seed quality is much better on short heads.
            $item = preg_replace('/\s*\(.*?\)\s*/', ' ', $item);
            $words = preg_split('/\s+/', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                continue;
            }
            $seeds[] = implode(' ', array_slice($words, 0, 5));
        }

        try {
            $gsc = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->select('query')
                ->selectRaw('sum(impressions) as impressions')
                ->whereNotNull('query')->where('query', '!=', '')
                ->groupBy('query')
                ->havingRaw('sum(impressions) >= 20')
                ->orderByDesc(DB::raw('sum(impressions)'))
                ->limit(10)
                ->pluck('query')
                ->all();
            foreach ($gsc as $q) {
                $seeds[] = mb_strtolower(trim((string) $q));
            }
        } catch (\Throwable) {
            // GSC absent — offerings alone are fine
        }

        $seeds = array_values(array_unique(array_filter($seeds, static fn ($s) => $s !== '' && mb_strlen($s) <= 80)));

        return array_slice($seeds, 0, self::MAX_SEEDS);
    }

    /**
     * Keyword-server result rows → normalized shape.
     *
     * @return list<array{keyword:string, volume:?int, competition:string, intent:string, is_question:bool}>
     */
    private function normalizeResults(array $result): array
    {
        $rows = [];
        foreach ((array) ($result['results'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($kw === '' || isset($rows[$kw])) {
                continue;
            }
            $volume = is_numeric($row['avgMonthlySearches'] ?? null) ? (int) $row['avgMonthlySearches'] : null;
            $index = is_numeric($row['competitionIndex'] ?? null) ? (int) $row['competitionIndex'] : null;
            $rows[$kw] = [
                'keyword' => $kw,
                'volume' => $volume,
                'competition' => $index === null ? 'unknown' : ($index < 34 ? 'low' : ($index < 67 ? 'medium' : 'high')),
                'intent' => KeywordIntentClassifier::classify($kw),
                'is_question' => KeywordIntentClassifier::isQuestion($kw),
            ];
        }

        return array_values($rows);
    }

    /**
     * Fallback rows from the plan's own generated topics (target + secondary
     * keywords), volumes filled from the shared keyword_metrics cache where
     * available. Same shape as normalizeResults().
     */
    private function fallbackRows(ContentPlan $plan): array
    {
        $keywords = [];
        foreach ($plan->topics()->get(['target_keyword', 'secondary_keywords', 'keyword_volume']) as $topic) {
            $kw = mb_strtolower(trim((string) $topic->target_keyword));
            if ($kw !== '') {
                $keywords[$kw] = $topic->keyword_volume ? (int) $topic->keyword_volume : null;
            }
            foreach ((array) ($topic->secondary_keywords ?? []) as $sec) {
                $sec = mb_strtolower(trim((string) $sec));
                if ($sec !== '' && ! array_key_exists($sec, $keywords)) {
                    $keywords[$sec] = null;
                }
            }
        }
        if ($keywords === []) {
            return [];
        }

        // Enrich from the shared metrics cache (free, already-paid data).
        try {
            $hashes = [];
            foreach (array_keys($keywords) as $kw) {
                $hashes[KeywordMetric::hashKeyword($kw)] = $kw;
            }
            $metrics = KeywordMetric::query()
                ->whereIn('keyword_hash', array_keys($hashes))
                ->get(['keyword_hash', 'search_volume']);
            foreach ($metrics as $m) {
                $kw = $hashes[$m->keyword_hash] ?? null;
                if ($kw !== null && $keywords[$kw] === null && is_numeric($m->search_volume)) {
                    $keywords[$kw] = (int) $m->search_volume;
                }
            }
        } catch (\Throwable) {
            // volumes stay null — the step still shows clusters/intent/questions
        }

        // Still-missing volumes: one bounded synchronous Keywords Everywhere
        // batch (the keyword server already failed to deliver — this is the
        // backup source, deliberately bypassing the provider setting). Fires
        // at most once per plan per FALLBACK_TTL (the built payload is
        // cached), ≤100 keywords/call. Fails soft to null volumes.
        $competitions = [];
        $missing = array_keys(array_filter($keywords, static fn ($v) => $v === null));
        if ($missing !== []) {
            try {
                $response = $this->ke->getKeywordData(
                    array_slice($missing, 0, 100),
                    websiteId: $plan->website_id,
                    source: 'content-wizard',
                );
                foreach ((array) ($response['data'] ?? []) as $row) {
                    if (! is_array($row) || ! is_string($row['keyword'] ?? null)) {
                        continue;
                    }
                    $kw = mb_strtolower(trim($row['keyword']));
                    if (array_key_exists($kw, $keywords) && $keywords[$kw] === null && is_numeric($row['vol'] ?? null)) {
                        $keywords[$kw] = (int) $row['vol'];
                    }
                    if (is_numeric($row['competition'] ?? null)) {
                        $c = (float) $row['competition']; // KE: 0..1
                        $competitions[$kw] = $c < 0.34 ? 'low' : ($c < 0.67 ? 'medium' : 'high');
                    }
                }
            } catch (\Throwable) {
                // fail soft
            }
        }

        $rows = [];
        foreach ($keywords as $kw => $volume) {
            $rows[] = [
                'keyword' => $kw,
                'volume' => $volume,
                'competition' => $competitions[$kw] ?? 'unknown',
                'intent' => KeywordIntentClassifier::classify($kw),
                'is_question' => KeywordIntentClassifier::isQuestion($kw),
            ];
        }

        return $rows;
    }

    /**
     * Digest normalized rows into the client-facing payload.
     *
     * @param  list<array{keyword:string, volume:?int, competition:string, intent:string, is_question:bool}>  $rows
     */
    private function build(array $rows, ContentPlan $plan, bool $partial): array
    {
        $plannedKeywords = $plan->topics()->pluck('target_keyword')
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))->filter()->flip()->all();

        // ── Clusters: LLM labels when available (cached monthly), else term groups.
        $clusterMap = null;
        try {
            if ($this->clusters->isAvailable()) {
                $keywordsSorted = array_column($rows, 'keyword');
                sort($keywordsSorted);
                $clusterMap = $this->clusters->cluster(
                    array_map(fn ($r) => ['keyword' => $r['keyword'], 'volume' => $r['volume']], $rows),
                    'content-wizard:'.md5(implode('|', $keywordsSorted)),
                );
            }
        } catch (\Throwable) {
            $clusterMap = null;
        }

        $clusters = [];
        if (is_array($clusterMap) && $clusterMap !== []) {
            $grouped = [];
            foreach ($rows as $r) {
                $label = $clusterMap[$r['keyword']] ?? null;
                if ($label === null || strcasecmp($label, 'Other') === 0) {
                    continue;
                }
                $grouped[$label] ??= ['label' => $label, 'count' => 0, 'volume' => 0, 'top' => []];
                $grouped[$label]['count']++;
                $grouped[$label]['volume'] += (int) ($r['volume'] ?? 0);
                $grouped[$label]['top'][] = $r;
            }
            uasort($grouped, fn ($a, $b) => $b['volume'] <=> $a['volume']);
            foreach (array_slice($grouped, 0, 6) as $g) {
                usort($g['top'], fn ($a, $b) => ($b['volume'] ?? 0) <=> ($a['volume'] ?? 0));
                $g['top'] = array_column(array_slice($g['top'], 0, 3), 'keyword');
                $clusters[] = $g;
            }
        } else {
            foreach (KeywordTermGrouper::groups($rows, 6) as $g) {
                $members = array_values(array_filter($rows, fn ($r) => str_contains($r['keyword'], $g['term'])));
                usort($members, fn ($a, $b) => ($b['volume'] ?? 0) <=> ($a['volume'] ?? 0));
                $clusters[] = [
                    'label' => \Illuminate\Support\Str::title($g['term']),
                    'count' => $g['count'],
                    'volume' => $g['volume'],
                    'top' => array_column(array_slice($members, 0, 3), 'keyword'),
                ];
            }
        }

        // ── Intent mix.
        $intents = array_fill_keys(KeywordIntentClassifier::INTENTS, 0);
        foreach ($rows as $r) {
            $intents[$r['intent']] = ($intents[$r['intent']] ?? 0) + 1;
        }
        $intents = array_filter($intents);
        arsort($intents);

        // ── Questions the audience asks.
        $questions = array_values(array_filter($rows, fn ($r) => $r['is_question']));
        usort($questions, fn ($a, $b) => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));
        $questions = array_map(
            fn ($r) => ['keyword' => $r['keyword'], 'volume' => $r['volume']],
            array_slice($questions, 0, 8)
        );

        // ── Opportunities: volume weighted by how winnable the keyword looks.
        $weight = ['low' => 1.0, 'medium' => 0.7, 'unknown' => 0.55, 'high' => 0.4];
        $opps = array_values(array_filter($rows, fn ($r) => ($r['volume'] ?? 0) > 0));
        usort($opps, fn ($a, $b) => ($b['volume'] * $weight[$b['competition']]) <=> ($a['volume'] * $weight[$a['competition']]));
        $opportunities = array_map(fn ($r) => [
            'keyword' => $r['keyword'],
            'volume' => $r['volume'],
            'competition' => $r['competition'],
            'planned' => isset($plannedKeywords[$r['keyword']]),
        ], array_slice($opps, 0, 6));

        return [
            'stats' => [
                'keywords' => count($rows),
                'volume' => (int) array_sum(array_map(fn ($r) => (int) ($r['volume'] ?? 0), $rows)),
                'questions' => count(array_filter($rows, fn ($r) => $r['is_question'])),
                'clusters' => count($clusters),
            ],
            'clusters' => $clusters,
            'intents' => $intents,
            'questions' => $questions,
            'opportunities' => $opportunities,
            'partial' => $partial,
        ];
    }

    /**
     * The generated topics gain real volumes once research lands — the
     * "searches/mo" chips on the first-articles step and the calendar stop
     * being blank. One bounded write pass, guarded by the insights cache.
     */
    private function backfillTopicVolumes(ContentPlan $plan, array $rows): void
    {
        try {
            $byKeyword = [];
            foreach ($rows as $r) {
                if (($r['volume'] ?? null) !== null) {
                    $byKeyword[$r['keyword']] = (int) $r['volume'];
                }
            }
            if ($byKeyword === []) {
                return;
            }
            foreach ($plan->topics()->whereNull('keyword_volume')->get(['id', 'target_keyword']) as $topic) {
                $kw = mb_strtolower(trim((string) $topic->target_keyword));
                if (isset($byKeyword[$kw])) {
                    $topic->forceFill(['keyword_volume' => $byKeyword[$kw]])->saveQuietly();
                }
            }
        } catch (\Throwable) {
            // cosmetic enrichment only — never break the read path
        }
    }
}
