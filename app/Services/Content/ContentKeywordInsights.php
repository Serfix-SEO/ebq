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
use App\Services\Llm\LlmClientFactory;
use App\Services\SerperSearchClient;
use App\Support\ContentAutopilotConfig;
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

    /** Competitor domains we mine keywords from for the gap analysis. */
    private const MAX_COMPETITORS = 3;

    /** Give the (concurrency-1, minutes-per-job) server this long before falling back. */
    private const PENDING_GRACE_MINUTES = 15;

    /** Partial (competitors/gap still pending) results re-check this often. */
    private const PARTIAL_TTL_SECONDS = 15;

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
        // Three research angles, merged in get(): (1) offering seeds, (2) the
        // CLIENT's own domain (what they actually rank for — site-scope), and
        // (3) the top competitor's domain. (2) and (3) are crawl-derived so they
        // pass through an LLM scrap filter (drop login / create-account / cart…).
        $this->ensureOwnRequest($plan);
        $this->ensureSiteRequest($plan, $this->ownDomainKey($plan), fn () => $plan->website?->normalized_domain ?: $plan->website?->domain);

        // Up to MAX_COMPETITORS competitor domains, one keyword request each, so
        // the gap analysis is triangulated across several rivals (not one). Late
        // dispatch is fine — the wizard poll re-runs ensureStarted once the
        // competitors step has discovered them.
        $competitors = $this->topCompetitorDomains($plan, $plan->website, self::MAX_COMPETITORS);
        foreach ($competitors as $i => $domain) {
            $this->ensureSiteRequest($plan, $this->competitorRequestKey($plan, $i), fn () => $domain);
        }
    }

    /**
     * A site-scope keyword request against $domainResolver()'s domain (client's
     * own or a competitor), stored under $cacheKey. Guarded once per key.
     */
    private function ensureSiteRequest(ContentPlan $plan, string $cacheKey, \Closure $domainResolver): void
    {
        if (Cache::has($cacheKey)) {
            return;
        }
        $website = $plan->website;
        if ($website === null) {
            return;
        }
        $domain = $domainResolver();
        $domain = $domain ? strtolower(preg_replace('/^www\./', '', trim((string) $domain))) : '';
        if ($domain === '') {
            return; // not known yet (e.g. competitor before the competitors step)
        }
        $lock = $cacheKey.':lock';
        if (! Cache::add($lock, 1, now()->addMinutes(30))) {
            return;
        }

        try {
            $language = match (mb_strtolower(trim((string) $plan->language))) {
                '', 'en' => null,
                'ar' => 'Arabic',
                default => (string) $plan->language,
            };
            $request = $this->pool->dispatchIdeas(
                ['url' => 'https://'.$domain, 'scope' => 'site', 'language' => $language],
                $website->user_id,
                $website->id,
                countryKey: $plan->country ?: null,
                meter: false,
            );
            Cache::put($cacheKey, $request->id, now()->addHours(2));
        } catch (\Throwable $e) {
            Log::warning('ContentKeywordInsights site request failed', [
                'plan_id' => $plan->id, 'domain' => $domain, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** The site's own keyword ideas request (offerings + GSC seeds). */
    private function ensureOwnRequest(ContentPlan $plan): void
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

    /** Top competitor domains to mine keywords from (SERP-shared / authority order). */
    private function topCompetitorDomains(ContentPlan $plan, ?Website $website, int $limit = self::MAX_COMPETITORS): array
    {
        if ($website === null) {
            return [];
        }
        $out = [];
        try {
            $insights = app(ContentSetupInsights::class)->competitorAuthority($website);
            $competitors = is_array($insights) ? ($insights['competitors'] ?? []) : [];
            $own = strtolower(preg_replace('/^www\./', '', (string) ($website->normalized_domain ?: $website->domain)));
            foreach ($competitors as $c) {
                $d = strtolower(preg_replace('/^www\./', '', trim((string) ($c['domain'] ?? ''))));
                if ($d !== '' && $d !== $own && ! in_array($d, $out, true)) {
                    $out[] = $d;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // no competitor data — skip competitor keywords
        }

        return $out;
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
    /**
     * Live per-source research status for the step-6 loader (which domain we're
     * analyzing right now). Returns [] once the digest is built.
     *
     * @return list<array{label:string, done:bool}>
     */
    public function researchStatus(ContentPlan $plan): array
    {
        if (Cache::has($this->insightsKey($plan))) {
            return [];
        }
        $website = $plan->website;
        $ownDomain = $website ? ($website->normalized_domain ?: $website->domain) : null;

        // "Done" here means genuinely COMPLETED — never merely "settled". A
        // request that hasn't been dispatched yet (null) must read as still
        // analyzing, not flash a misleading instant "Done".
        $completed = fn (?KeywordApiRequest $r) => $r !== null && $r->status === KeywordApiRequest::STATUS_COMPLETED;

        $seed = $this->request($plan);
        $ownSite = $this->siteRequest($this->ownDomainKey($plan));

        $status = [];
        // The client's own research (seeds + own-domain crawl) as one line.
        $status[] = [
            'label' => $ownDomain ? __('Your site — :d', ['d' => $ownDomain]) : __('Your site'),
            'done' => $completed($seed) && ($ownSite !== null && $completed($ownSite)),
        ];

        // One line per competitor (up to MAX_COMPETITORS). While discovery is
        // still running we don't know the domains yet, so show generic
        // placeholder lines so the user can see all rivals are being analyzed.
        $competitorDomains = $this->topCompetitorDomains($plan, $website, self::MAX_COMPETITORS);
        $discoveryPending = $website !== null
            && $competitorDomains === []
            && app(ContentSetupInsights::class)->isGenerating($website);
        $lines = $discoveryPending ? self::MAX_COMPETITORS : count($competitorDomains);
        for ($i = 0; $i < $lines; $i++) {
            $domain = $competitorDomains[$i] ?? null;
            $req = $domain !== null ? $this->siteRequest($this->competitorRequestKey($plan, $i)) : null;
            $status[] = [
                'label' => $domain
                    ? __('Competitor :n — :d', ['n' => $i + 1, 'd' => $domain])
                    : __('Competitor :n', ['n' => $i + 1]),
                'done' => $completed($req),
            ];
        }

        return $status;
    }

    public function get(ContentPlan $plan): ?array
    {
        $cached = Cache::get($this->insightsKey($plan));
        if ($cached !== null) {
            return $cached;
        }

        $seed = $this->request($plan);                        // offering seeds
        $ownSite = $this->siteRequest($this->ownDomainKey($plan)); // client domain

        $completed = fn (?KeywordApiRequest $r) => $r !== null && $r->status === KeywordApiRequest::STATUS_COMPLETED;

        // Competitor requests — one per discovered rival, up to MAX_COMPETITORS.
        $competitorDomains = $this->topCompetitorDomains($plan, $plan->website, self::MAX_COMPETITORS);
        $expected = count($competitorDomains);
        $compReqs = [];
        foreach (array_keys($competitorDomains) as $i) {
            $compReqs[$i] = $this->siteRequest($this->competitorRequestKey($plan, $i));
        }

        // Competitor DISCOVERY (SERP/backlinks) may still be running — no domains
        // found YET, not necessarily none. Don't treat competitors as "done"
        // while discovery is live; the wizard poll re-dispatches once they land.
        $discoveryPending = $plan->website !== null
            && $competitorDomains === []
            && app(ContentSetupInsights::class)->isGenerating($plan->website);

        // Show the FULL digest in one shot — never a smaller number that grows.
        // A keyword count that jumps (517 → 1,000+) as sources land reads as
        // "we found less than we did"; a few extra seconds of the loader is fine,
        // seeing a partial count is not. So we wait for the client's own keyword
        // set (offering seeds + own-domain crawl) AND every competitor before
        // building. The loader's per-source status shows progress meanwhile.
        $clientComplete = $completed($seed) && ($ownSite === null || $completed($ownSite));

        $compCompleted = count(array_filter($compReqs, $completed));
        $competitorsComplete = ! $discoveryPending && $compCompleted >= $expected;

        $allComplete = $clientComplete && $competitorsComplete;

        // Backstop: give up (build from whatever landed) only once EVERYTHING has
        // settled — client requests plus every dispatched competitor request — so
        // a dead keyword server can't hang the step forever.
        $compDispatched = count(array_filter($compReqs, static fn ($r) => $r !== null));
        $competitorsSettled = ! $discoveryPending
            && $compDispatched >= $expected
            && count(array_filter($compReqs, fn ($r) => $this->settled($r))) === count($compReqs);
        $allSettled = $this->settled($seed) && $this->settled($ownSite) && $competitorsSettled;

        if (! $allComplete && ! $allSettled) {
            return null; // keep the loader up — show complete results only
        }

        // CLIENT keywords = offering seeds + their own crawled domain (scrap-
        // filtered — the site-scope set includes login / cart / account junk).
        $clientRows = $this->mergeRows(
            $this->completedRows($seed),
            $this->filterScrap($this->completedRows($ownSite), $plan),
        );
        // COMPETITOR keywords, merged across every completed rival, scrap-filtered.
        $competitorRows = [];
        foreach ($compReqs as $req) {
            $competitorRows = $this->mergeRows($competitorRows, $this->filterScrap($this->completedRows($req), $plan));
        }

        $rows = $this->mergeRows($clientRows, $competitorRows);

        if ($rows === []) {
            // All settled but the server(s) returned nothing → topic fallback.
            $rows = $this->fallbackRows($plan);
            if ($rows === []) {
                return null;
            }
            $partial = true;
        } else {
            $partial = ! $allComplete; // only true on the settled-backstop path
        }

        // Keyword gap: what competitors rank for that the client does NOT,
        // ranked by relevance to what the client sells.
        $gap = $this->computeGap($clientRows, $competitorRows, $plan);

        // "People also ask" / "People also search for" straight from Google's
        // SERP for the client's top query (one cached Serper call).
        $peopleAlso = $this->peopleAlsoSearch($plan, $rows);

        $insights = $this->build($rows, $plan, partial: $partial, gap: $gap['rows'], gapTotal: $gap['total'],
            competitorsPending: ! $competitorsComplete,
            competitorsDone: $compCompleted,
            competitorsTotal: max($expected, $compDispatched, $discoveryPending ? self::MAX_COMPETITORS : 0),
            peopleAlso: $peopleAlso);
        // Partial results are cached briefly so the next poll upgrades them as the
        // remaining requests complete; the final digest is cached 30 days.
        Cache::put($this->insightsKey($plan), $insights,
            $partial ? now()->addSeconds(self::PARTIAL_TTL_SECONDS) : now()->addDays(self::CACHE_TTL_DAYS));
        $this->backfillTopicVolumes($plan, $rows);

        return $insights;
    }

    /** LLM removes website UI / account / navigation junk keywords (login, cart…). */
    private function filterScrap(array $rows, ContentPlan $plan): array
    {
        if ($rows === []) {
            return [];
        }
        $keywords = array_values(array_unique(array_column($rows, 'keyword')));
        $cacheKey = 'content:kw-scrap:'.md5(implode('|', $keywords));
        $scrap = Cache::get($cacheKey);
        if ($scrap === null) {
            $scrap = $this->llmScrapKeywords($keywords);
            Cache::put($cacheKey, $scrap, now()->addDays(self::CACHE_TTL_DAYS));
        }
        if ($scrap === []) {
            return $rows;
        }
        $scrapSet = array_flip($scrap);

        return array_values(array_filter($rows, static fn ($r) => ! isset($scrapSet[$r['keyword']])));
    }

    /** @return list<string> lowercased scrap keywords the LLM flagged as UI/account junk */
    private function llmScrapKeywords(array $keywords): array
    {
        try {
            $model = ContentAutopilotConfig::modelFor('ideate');
            $llm = LlmClientFactory::make($model['provider']);
            if (! $llm->isAvailable()) {
                return [];
            }
            $list = implode("\n", array_slice($keywords, 0, 250));
            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You clean keyword lists for SEO content research. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                From the keyword list below, return ONLY the entries that are website UI,
                account, or navigation junk — things nobody researches content for, e.g.
                "login", "sign in", "create account", "my account", "cart", "checkout",
                "password reset", "dashboard", "logout", "terms of service", "contact us".
                Do NOT include real topical / product / informational search terms.

                {$list}

                Return JSON: {"scrap": ["...", "..."]}
                PROMPT],
            ], ['temperature' => 0.1, 'max_tokens' => 800, 'timeout' => 30, '__source' => 'content_autopilot.kw_scrap']);

            $scrap = is_array($response['scrap'] ?? null) ? $response['scrap'] : [];

            return array_values(array_filter(array_map(
                static fn ($s) => mb_strtolower(trim((string) $s)), $scrap
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Keyword gap: competitor keywords (with volume) the client isn't targeting,
     * ranked by RELEVANCE to what the client actually sells (offering overlap)
     * first, then volume — so a gold buyer sees "sell gold coins", not a
     * competitor's unrelated high-volume blog term.
     *
     * @return array{rows: list<array{keyword:string, volume:?int, competition:string}>, total: int}
     */
    private function computeGap(array $clientRows, array $competitorRows, ContentPlan $plan): array
    {
        if ($competitorRows === []) {
            return ['rows' => [], 'total' => 0];
        }
        $client = array_flip(array_column($clientRows, 'keyword'));
        $offering = $this->offeringTokens($plan);

        $gap = [];
        foreach ($competitorRows as $r) {
            if (isset($client[$r['keyword']]) || (int) ($r['volume'] ?? 0) <= 0) {
                continue;
            }
            $r['_rel'] = $this->relevanceScore((string) $r['keyword'], $offering);
            $gap[] = $r;
        }
        if ($gap === []) {
            return ['rows' => [], 'total' => 0];
        }

        // Prefer on-topic keywords; only fall back to raw volume if too few match.
        $relevant = array_values(array_filter($gap, static fn ($r) => $r['_rel'] > 0));
        $pool = count($relevant) >= 3 ? $relevant : $gap;

        usort($pool, static fn ($a, $b) => ($b['_rel'] <=> $a['_rel'])
            ?: ((int) ($b['volume'] ?? 0) <=> (int) ($a['volume'] ?? 0)));

        $rows = array_map(static fn ($r) => [
            'keyword' => $r['keyword'],
            'volume' => $r['volume'],
            'competition' => $r['competition'],
        ], array_slice($pool, 0, 8));

        // total = every competitor keyword the client isn't targeting (the full
        // gap they'll unlock after onboarding), not just the shown sample.
        return ['rows' => $rows, 'total' => count($gap)];
    }

    /**
     * Google's "People also ask" (questions) and "People also search for"
     * (related searches) for the client's top query — the real SERP demand
     * signal. One Serper call, geo-targeted, cached 30 days. Fails soft to
     * empty (the section just hides).
     *
     * @return array{ask: list<string>, search: list<string>, query: string}
     */
    private function peopleAlsoSearch(ContentPlan $plan, array $rows): array
    {
        $empty = ['ask' => [], 'search' => [], 'query' => ''];
        try {
            $query = $this->primaryQuery($plan, $rows);
            if ($query === '') {
                return $empty;
            }
            $gl = strtolower(trim((string) ($plan->country ?: 'us'))) ?: 'us';
            $cacheKey = 'content:kw-paa:'.$plan->id.':'.md5($query.'|'.$gl);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $json = app(SerperSearchClient::class)->query([
                'q' => $query,
                'type' => 'search',
                'gl' => $gl,
                '__website_id' => $plan->website_id,
                '__owner_user_id' => $plan->website?->user_id,
                '__source' => 'content_autopilot.people_also',
            ]);
            if (! is_array($json)) {
                return $empty;
            }

            $ask = [];
            foreach ((array) ($json['peopleAlsoAsk'] ?? []) as $p) {
                $q = trim((string) (is_array($p) ? ($p['question'] ?? '') : $p));
                if ($q !== '') {
                    $ask[$q] = true;
                }
            }
            $search = [];
            foreach ((array) ($json['relatedSearches'] ?? []) as $p) {
                $q = trim((string) (is_array($p) ? ($p['query'] ?? '') : $p));
                if ($q !== '') {
                    $search[$q] = true;
                }
            }

            $result = [
                'ask' => array_slice(array_keys($ask), 0, 8),
                'search' => array_slice(array_keys($search), 0, 12),
                'query' => $query,
            ];
            Cache::put($cacheKey, $result, now()->addDays(self::CACHE_TTL_DAYS));

            return $result;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** The single query to probe Google with: highest-volume keyword, else a head offering. */
    private function primaryQuery(ContentPlan $plan, array $rows): string
    {
        $best = '';
        $bestVol = -1;
        foreach ($rows as $r) {
            $v = (int) ($r['volume'] ?? 0);
            if ($v > $bestVol && ($r['keyword'] ?? '') !== '') {
                $bestVol = $v;
                $best = (string) $r['keyword'];
            }
        }
        if ($best !== '') {
            return $best;
        }
        foreach ((array) (($plan->offerings ?? [])['sell'] ?? []) as $item) {
            $item = trim((string) preg_replace('/\s*\(.*?\)\s*/', ' ', mb_strtolower((string) $item)));
            $words = preg_split('/\s+/', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words !== []) {
                return implode(' ', array_slice($words, 0, 4));
            }
        }

        return '';
    }

    /** Significant tokens from what the client sells + their business description. */
    private function offeringTokens(ContentPlan $plan): array
    {
        $stop = ['the', 'and', 'for', 'with', 'you', 'your', 'our', 'that', 'this', 'are',
            'from', 'they', 'all', 'can', 'get', 'buy', 'sell', 'best', 'top', 'new', 'more'];
        $text = mb_strtolower(implode(' ',
            array_merge((array) (($plan->offerings ?? [])['sell'] ?? []), [(string) $plan->business_description])
        ));
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $stop, true)
        )));
    }

    /** How many offering tokens the keyword contains (word-boundary matches). */
    private function relevanceScore(string $keyword, array $offeringTokens): int
    {
        if ($offeringTokens === []) {
            return 0;
        }
        $words = array_flip(preg_split('/[^a-z0-9]+/', mb_strtolower($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $score = 0;
        foreach ($offeringTokens as $t) {
            if (isset($words[$t])) {
                $score++;
            }
        }

        return $score;
    }

    /** A request that has completed or aged past the grace window (null = overdue now). */
    private function settled(?KeywordApiRequest $request): bool
    {
        if ($request === null) {
            return true;
        }

        return $request->status === KeywordApiRequest::STATUS_COMPLETED
            || $request->status === KeywordApiRequest::STATUS_FAILED
            || ($request->created_at !== null && $request->created_at->lt(now()->subMinutes(self::PENDING_GRACE_MINUTES)));
    }

    /** Normalized rows from a completed request, else []. */
    private function completedRows(?KeywordApiRequest $request): array
    {
        return $request !== null && $request->status === KeywordApiRequest::STATUS_COMPLETED
            ? $this->normalizeResults((array) ($request->result ?? []))
            : [];
    }

    /** Merge two normalized row sets, keeping the first occurrence per keyword. */
    private function mergeRows(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach ([...$a, ...$b] as $row) {
            $kw = $row['keyword'] ?? '';
            if ($kw === '' || isset($seen[$kw])) {
                continue;
            }
            $seen[$kw] = true;
            $out[] = $row;
        }

        return $out;
    }

    /** Clear cached insights + both request pointers so a refetch redispatches. */
    public function forget(ContentPlan $plan): void
    {
        Cache::forget($this->insightsKey($plan));
        Cache::forget($this->requestKey($plan));
        Cache::forget($this->ownDomainKey($plan));
        Cache::forget('content:kw-insights:lock:'.$plan->id);
        Cache::forget($this->ownDomainKey($plan).':lock');
        for ($i = 0; $i < self::MAX_COMPETITORS; $i++) {
            Cache::forget($this->competitorRequestKey($plan, $i));
            Cache::forget($this->competitorRequestKey($plan, $i).':lock');
        }
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

    private function competitorRequestKey(ContentPlan $plan, int $i = 0): string
    {
        return 'content:kw-insights:comp-req:'.$plan->id.':'.$i;
    }

    private function ownDomainKey(ContentPlan $plan): string
    {
        return 'content:kw-insights:site-req:'.$plan->id;
    }

    private function request(ContentPlan $plan): ?KeywordApiRequest
    {
        $id = Cache::get($this->requestKey($plan));

        return $id ? KeywordApiRequest::query()->find($id) : null;
    }

    /** Resolve a site-scope keyword request stored under an arbitrary cache key. */
    private function siteRequest(string $cacheKey): ?KeywordApiRequest
    {
        $id = Cache::get($cacheKey);

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
    private function build(array $rows, ContentPlan $plan, bool $partial, array $gap = [], int $gapTotal = 0,
        bool $competitorsPending = false, int $competitorsDone = 0, int $competitorsTotal = 0,
        array $peopleAlso = []): array
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

        // ── What people are searching for: the highest-volume real search terms,
        // ranked purely by monthly searches (not winnability). This is the plain
        // "here's the demand we found" list.
        $searches = array_values(array_filter($rows, fn ($r) => (int) ($r['volume'] ?? 0) > 0));
        usort($searches, fn ($a, $b) => (int) ($b['volume'] ?? 0) <=> (int) ($a['volume'] ?? 0));
        $topSearches = array_map(fn ($r) => [
            'keyword' => $r['keyword'],
            'volume' => $r['volume'],
            'competition' => $r['competition'],
        ], array_slice($searches, 0, 12));

        // ── Intent mix.
        $intents = array_fill_keys(KeywordIntentClassifier::INTENTS, 0);
        foreach ($rows as $r) {
            $intents[$r['intent']] = ($intents[$r['intent']] ?? 0) + 1;
        }
        $intents = array_filter($intents);
        arsort($intents);

        // ── Questions the audience asks: our keyword-derived questions PLUS
        // Google's real "People also ask" (which don't come with a volume). Many
        // markets have few question-shaped keywords, so without the PAA merge the
        // "Questions asked" stat and section would sit at 0.
        $questionRows = array_values(array_filter($rows, fn ($r) => $r['is_question']));
        usort($questionRows, fn ($a, $b) => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));
        $questionList = [];
        $seenQuestion = [];
        foreach ($questionRows as $r) {
            $key = mb_strtolower(trim((string) $r['keyword']));
            if ($key === '' || isset($seenQuestion[$key])) {
                continue;
            }
            $seenQuestion[$key] = true;
            $questionList[] = ['keyword' => $r['keyword'], 'volume' => $r['volume']];
        }
        foreach ((array) ($peopleAlso['ask'] ?? []) as $q) {
            $q = trim((string) $q);
            $key = mb_strtolower($q);
            if ($key === '' || isset($seenQuestion[$key])) {
                continue;
            }
            $seenQuestion[$key] = true;
            $questionList[] = ['keyword' => $q, 'volume' => null];
        }
        $questionsTotal = count($questionList);
        $questions = array_slice($questionList, 0, 8);

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
                'questions' => $questionsTotal,
                'clusters' => count($clusters),
            ],
            'clusters' => $clusters,
            'intents' => $intents,
            'questions' => $questions,
            'top_searches' => $topSearches,
            'people_also' => $peopleAlso,
            'opportunities' => $opportunities,
            'gap' => $gap,
            'gap_total' => $gapTotal,
            'partial' => $partial,
            'competitors_pending' => $competitorsPending,
            'competitors_done' => $competitorsDone,
            'competitors_total' => $competitorsTotal,
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
