<?php

namespace App\Services\Content;

use App\Jobs\Content\ClassifyPlanKeywordsJob;
use App\Jobs\Content\HarvestDomainKeywordsJob;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\DomainKeywordHarvest;
use App\Models\DomainKeywordRanking;
use App\Models\DomainMetric;
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
use Illuminate\Support\Str;

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

    /** Competitor domains we mine keywords from for the gap analysis. Kept at 1:
     *  the self-hosted keyword server is concurrency-1 and minutes-per-job, so
     *  each extra rival stalls the whole step (seed + own domain + 1 competitor).
     *  The wizard says so plainly — deeper competitor research continues in the
     *  background per-article after onboarding. */
    private const MAX_COMPETITORS = 1;

    /** Give the (concurrency-1, minutes-per-job) server this long before falling back. */
    private const PENDING_GRACE_MINUTES = 15;

    /** Partial (competitors/gap still pending) results re-check this often. */
    private const PARTIAL_TTL_SECONDS = 15;

    /** Below this many LLM-labelled pillars we fall back to the deterministic
     *  term grouper — a thin/coarse LLM pass must not shrink "content pillars". */
    private const MIN_CLUSTERS = 4;

    /** How many (highest-volume) keywords we send to the cluster labeller. Kept
     *  small so its per-keyword JSON reply never truncates; the rest of the set
     *  inherits those labels via {@see extendClusterMap()}. */
    private const CLUSTER_SAMPLE = 150;

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
        // Plus: price the offer-spine candidate queries via DataForSEO so they
        // arrive in the "best search terms" ranking with real volumes instead
        // of being filtered out as volume-less, AND discover the real demand
        // around each offer via DFS keyword_suggestions (both run in the
        // queued job, once per plan per 30d).
        $this->enrichCandidateMetrics($plan);
        $this->ensureDfsSuggestions($plan);
        $this->ensureOwnRequest($plan);
        $this->ensureSiteRequest($plan, $this->ownDomainKey($plan), fn () => $plan->website?->normalized_domain ?: $plan->website?->domain);

        // ONE competitor only (see MAX_COMPETITORS): the keyword server is
        // concurrency-1, so each extra rival adds minutes to the step. The wizard
        // tells the client we analyze their top competitor now and keep studying
        // the rest in the background. Late dispatch is fine — the poll re-runs
        // ensureStarted once the competitors step has discovered them.
        foreach ($this->topCompetitorDomains($plan, $plan->website, self::MAX_COMPETITORS) as $i => $domain) {
            $this->ensureSiteRequest($plan, $this->competitorRequestKey($plan, $i), fn () => $domain);
        }
    }

    /**
     * Once the harvest has produced competitor rankings, classify this plan's
     * keywords (own vs relevant gap) in bulk — guarded so it runs once per plan
     * per calendar month. {@see ClassifyPlanKeywordsJob} does the LLM vetting.
     */
    private function ensureClassify(ContentPlan $plan): void
    {
        $website = $plan->website;
        if ($website === null || $plan->keywords_classified_at !== null) {
            return; // onboarding fires the FIRST classification only; monthly growth
            //         is driven by ebq:content-keyword-harvest.
        }
        $country = $this->planCountry($plan);
        $competitors = $this->topCompetitorDomains($plan, $website, self::MAX_COMPETITORS);
        if ($competitors === []) {
            return; // competitors not discovered yet
        }
        // Wait until EVERY competitor is harvested → the first gap the client sees
        // is complete (mirrors the job's final gate).
        foreach ($competitors as $d) {
            if (! DomainKeywordRanking::query()->where('domain', $d)->where('country', $country)->exists()) {
                return;
            }
        }
        if (! Cache::add('content:kw-classify:'.$plan->id, 1, now()->addMinutes(30))) {
            return;
        }
        ClassifyPlanKeywordsJob::dispatch($plan->id);
    }

    /**
     * Dispatch the shared DataForSEO keyword harvest for the client domain + the
     * top-3 competitors, at most once per domain per calendar month (shared across
     * plans). Admin-owned sites sandbox (never billed / persisted).
     */
    private function ensureHarvest(ContentPlan $plan): void
    {
        $website = $plan->website;
        if ($website === null) {
            return;
        }
        $country = $this->planCountry($plan);
        $sandbox = (bool) $website->user?->is_admin;

        $domains = [];
        $own = strtolower(preg_replace('/^www\./', '', (string) ($website->normalized_domain ?: $website->domain)));
        if ($own !== '') {
            $domains[$own] = true;
        }
        foreach ($this->topCompetitorDomains($plan, $website, self::MAX_COMPETITORS) as $d) {
            $domains[$d] = true;
        }

        $month = now()->format('Y-m');
        foreach (array_keys($domains) as $domain) {
            // DURABLE monthly state lives in domain_keyword_harvest (last_run_at),
            // set by the job only on a real run. Check it FIRST.
            $h = DomainKeywordHarvest::query()->where('domain', $domain)->where('country', $country)->first();
            if ($h !== null && $h->exhausted) {
                continue; // nothing left to fetch for this domain
            }
            if ($h !== null && $h->last_run_at !== null && $h->last_run_at->format('Y-m') === $month) {
                continue; // already harvested this month
            }
            // Transient dedupe guard LAST — only when we're actually dispatching, and
            // short-lived. (A 31-day guard set BEFORE the work meant one failed run
            // blocked the domain for a whole month — that broke prod 2026-07-20.)
            if (! Cache::add('content:kw-harvest:'.$domain.':'.$country, 1, now()->addMinutes(30))) {
                continue;
            }
            HarvestDomainKeywordsJob::dispatch($domain, $country, 1000, $sandbox);
        }
    }

    private function planCountry(ContentPlan $plan): string
    {
        return strtolower(trim((string) $plan->country)) ?: 'global';
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
        $candidates = [];
        try {
            $svc = app(ContentSetupInsights::class);
            // withOverrides: the client's manual add/remove edits on the
            // competitors step must steer the research too — previously a
            // removed domain was still analyzed (raw report list only).
            $insights = $svc->withOverrides($svc->competitorAuthority($website), $plan);
            $competitors = is_array($insights) ? ($insights['competitors'] ?? []) : [];
            $own = strtolower(preg_replace('/^www\./', '', (string) ($website->normalized_domain ?: $website->domain)));
            foreach ($competitors as $c) {
                $d = strtolower(preg_replace('/^www\./', '', trim((string) ($c['domain'] ?? ''))));
                if ($d !== '' && $d !== $own && ! in_array($d, $candidates, true)) {
                    $candidates[] = $d;
                }
            }
        } catch (\Throwable) {
            // no competitor data — skip competitor keywords
        }

        // The report's "competitors" are SERP neighbours ranked by authority —
        // which systematically surfaces directories and platforms (thryv.com
        // for a cleaning company, prod 2026-07-21) ahead of the actual rival.
        // The mention guard already classified each domain against what this
        // client SELLS, so analyze real product competitors first and never
        // waste the single research slot on a classified reference.
        $candidates = app(CompetitorMentionGuard::class)->rankAndFilter($plan, $candidates);

        return array_slice($candidates, 0, $limit);
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
            // Overlay competitor traffic/metrics fresh — the batched DataForSEO
            // enrichment is async and may land AFTER this digest was first
            // cached, so we don't want a stale (missing) figure baked in for the
            // whole 30-day TTL.
            return $this->withCompetitorData($cached, $plan);
        }

        $seed = $this->request($plan);                        // offering seeds
        $ownSite = $this->siteRequest($this->ownDomainKey($plan)); // client domain

        $completed = fn (?KeywordApiRequest $r) => $r !== null && $r->status === KeywordApiRequest::STATUS_COMPLETED;

        // Competitor requests — ONE rival (MAX_COMPETITORS) so the concurrency-1
        // keyword server isn't choked; the wizard tells the client the rest are
        // studied in the background.
        $competitorDomains = $this->topCompetitorDomains($plan, $plan->website, self::MAX_COMPETITORS);
        $expected = count($competitorDomains);
        $compReqs = [];
        foreach (array_keys($competitorDomains) as $i) {
            $compReqs[$i] = $this->siteRequest($this->competitorRequestKey($plan, $i));
        }

        // Competitor DISCOVERY may still be running — no domains found YET, not
        // necessarily none. Don't call competitors "done" while discovery is live.
        $discoveryPending = $plan->website !== null
            && $competitorDomains === []
            && app(ContentSetupInsights::class)->isGenerating($plan->website);

        // Show the FULL digest in one shot — never a smaller number that grows.
        $clientComplete = $completed($seed) && ($ownSite === null || $completed($ownSite));
        $compCompleted = count(array_filter($compReqs, $completed));
        $competitorsComplete = ! $discoveryPending && $compCompleted >= $expected;
        $allComplete = $clientComplete && $competitorsComplete;

        // Backstop: build from whatever landed once EVERYTHING has settled, so a
        // dead keyword server can't hang the step forever.
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
        // COMPETITOR keywords from the rival's site-scope crawl, scrap-filtered.
        $competitorRows = [];
        foreach ($compReqs as $req) {
            $competitorRows = $this->mergeRows($competitorRows, $this->filterScrap($this->completedRows($req), $plan));
        }
        $rows = $this->mergeRows($clientRows, $competitorRows);

        if ($rows === []) {
            // Server returned nothing → topic fallback.
            $rows = $this->fallbackRows($plan);
            if ($rows === []) {
                return null;
            }
            $partial = true;
        } else {
            $partial = ! $allComplete;
        }

        // Keyword gap: what the competitor ranks for that the client does NOT,
        // ranked by relevance to what the client sells (LLM-vetted).
        $gap = $this->computeGap($clientRows, $competitorRows, $plan);

        // "People also ask" / "People also search for" straight from Google's
        // SERP for the client's top query (one cached Serper call).
        $peopleAlso = $this->peopleAlsoSearch($plan, $rows);

        $insights = $this->build($rows, $plan, partial: $partial, gap: $gap['rows'], gapTotal: $gap['total'],
            competitorsPending: ! $competitorsComplete,
            competitorsDone: $compCompleted,
            competitorsTotal: max($expected, $compDispatched, $discoveryPending ? self::MAX_COMPETITORS : 0),
            peopleAlso: $peopleAlso);
        // Partial results are cached briefly so the next poll upgrades them; the
        // final digest is cached 30 days.
        Cache::put($this->insightsKey($plan), $insights,
            $partial ? now()->addSeconds(self::PARTIAL_TTL_SECONDS) : now()->addDays(self::CACHE_TTL_DAYS));
        $this->backfillTopicVolumes($plan, $rows);

        return $this->withCompetitorData($insights, $plan);
    }

    /**
     * Overlay competitor-derived data onto the digest, read FRESH each call
     * (the batched DataForSEO enrichment is async and may land after the digest
     * was first cached):
     *   - `traffic`  — {estimated, competitors}: the combined organic monthly
     *     traffic (DataForSEO Labs ETV) of this plan's competitors → the
     *     step-6 "Est. monthly traffic" card.
     *   - `competitor_metrics` — per-competitor authority + traffic rows for the
     *     "how your competitors stack up" table below the keyword gap.
     * Both come from {@see ContentSetupInsights::competitorAuthority()} (referring
     * domains / backlinks / DA / PA) merged with `domain_metrics.dfs_metrics`
     * (organic traffic + keyword count) that {@see EnrichCompetitorDomainMetricsJob}
     * batch-populates.
     */
    private function withCompetitorData(array $insights, ContentPlan $plan): array
    {
        $data = $this->competitorData($plan);
        $insights['traffic'] = ['estimated' => $data['traffic_total'], 'competitors' => $data['traffic_count']];
        $insights['competitor_metrics'] = $data['rows'];

        // NOTE: the keyword GAP is NOT overlaid here — it comes from the keyword
        // server via computeGap() in get() and is baked into the cached digest.
        // Only the DataForSEO competitor METRICS (authority + traffic) are overlaid,
        // because that enrichment lands asynchronously after the digest is cached.
        return $insights;
    }

    /**
     * Keyword gap from DataForSEO Labs: keywords the top-3 competitors rank for
     * that the CLIENT does not, read from the shared domain_keyword_rankings asset
     * (grows as {@see HarvestDomainKeywordsJob} accumulates ~1,000/competitor/mo).
     * Ranked by volume, then LLM-vetted for topical relevance.
     *
     * @return array{rows: list<array{keyword:string, volume:?int, competition:string}>,
     *               total:int, pending:bool, ready:int, active:int}
     */
    private function dfsGap(ContentPlan $plan): array
    {
        $empty = ['rows' => [], 'total' => 0, 'pending' => false, 'ready' => 0, 'active' => 0];
        $website = $plan->website;
        if ($website === null) {
            return $empty;
        }
        $country = $this->planCountry($plan);
        $competitors = $this->topCompetitorDomains($plan, $website, self::MAX_COMPETITORS);
        $active = count($competitors);
        if ($competitors === []) {
            // Competitor discovery may still be running → show a pending state.
            return array_merge($empty, ['pending' => app(ContentSetupInsights::class)->isGenerating($website)]);
        }

        // Show the FINAL gap only — hold a loader while the harvest is still landing
        // or the first bulk classification hasn't finished (keywords_classified_at
        // null), rather than a partial/raw list.
        $ready = 0;
        foreach ($competitors as $d) {
            if (DomainKeywordRanking::query()->where('domain', $d)->where('country', $country)->exists()) {
                $ready++;
            }
        }
        if ($ready < $active || $plan->keywords_classified_at === null) {
            return array_merge($empty, ['pending' => true, 'ready' => $ready, 'active' => $active]);
        }

        // Read the pre-classified, relevance-vetted gap keywords for this plan
        // (written once by ClassifyPlanKeywordsJob) — no LLM at render time.
        $gapQuery = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)->where('type', ContentPlanKeyword::TYPE_GAP);
        $total = (clone $gapQuery)->count();

        $level = static function ($c): string {
            if (! is_numeric($c)) {
                return 'unknown';
            }

            return $c < 0.34 ? 'low' : ($c < 0.67 ? 'medium' : 'high');
        };
        $rows = (clone $gapQuery)->orderByDesc('search_volume')->limit(8)->get()
            ->map(static fn ($k) => [
                'keyword' => $k->keyword,
                'volume' => $k->search_volume,
                'competition' => $level($k->competition),
            ])->all();

        return ['rows' => $rows, 'total' => $total, 'pending' => false, 'ready' => $active, 'active' => $active];
    }

    /**
     * @return array{traffic_total:int, traffic_count:int, rows:list<array{
     *   domain:string, traffic:?int, keywords:?int, referring_domains:?int,
     *   backlinks:?int, da:?int, pa:?int, authority:?int}>}
     */
    private function competitorData(ContentPlan $plan): array
    {
        $empty = ['traffic_total' => 0, 'traffic_count' => 0, 'rows' => []];
        $website = $plan->website;
        if ($website === null) {
            return $empty;
        }

        try {
            $svc = app(ContentSetupInsights::class);
            // withOverrides: the client's manual add/remove edits on the
            // competitors step must be reflected here too — this table
            // silently kept showing a removed competitor (thryv.com, prod
            // 2026-07-22) because it read the raw cached report, never the
            // client's edits layered on top.
            $insights = $svc->withOverrides($svc->competitorAuthority($website), $plan);
        } catch (\Throwable) {
            return $empty;
        }
        $competitors = array_values(array_filter((array) ($insights['competitors'] ?? []), 'is_array'));
        if ($competitors === []) {
            return $empty;
        }

        $domains = [];
        foreach ($competitors as $c) {
            $d = strtolower(preg_replace('/^www\./', '', trim((string) ($c['domain'] ?? ''))));
            if ($d !== '') {
                $domains[$d] = true;
            }
        }

        // Organic traffic + keyword count from the shared domain_metrics asset.
        $dfs = [];
        try {
            foreach (DomainMetric::query()
                ->whereIn('domain', array_keys($domains))
                ->whereNotNull('dfs_metrics')
                ->get(['domain', 'dfs_metrics']) as $m) {
                $dfs[$m->domain] = $m->dfs_metrics;
            }
        } catch (\Throwable) {
            // no DFS rows — traffic columns just show "—"
        }

        $rows = [];
        $total = 0.0;
        $count = 0;
        foreach ($competitors as $c) {
            $d = strtolower(preg_replace('/^www\./', '', trim((string) ($c['domain'] ?? ''))));
            if ($d === '') {
                continue;
            }
            // bulk_traffic_estimation stores {metrics:{organic:{etv,count,...},paid:{...}}}.
            $etv = data_get($dfs[$d] ?? null, 'metrics.organic.etv');
            $kwCount = data_get($dfs[$d] ?? null, 'metrics.organic.count');
            $traffic = is_numeric($etv) && (float) $etv > 0 ? (int) round((float) $etv) : null;
            // "Est. monthly traffic" sums PEERS ONLY (Phase D classes from
            // withOverrides): summing Sephora/Kohl's ETV produced a 74M
            // headline for a niche brand — absurd and demoralizing. The
            // table below still shows every competitor's own traffic.
            if ($traffic !== null && ($c['class'] ?? 'peer') === 'peer') {
                $total += (float) $etv;
                $count++;
            }
            $rows[] = [
                'domain' => $d,
                'traffic' => $traffic,
                'keywords' => is_numeric($kwCount) ? (int) $kwCount : null,
                'referring_domains' => isset($c['referring_domains']) ? (int) $c['referring_domains'] : null,
                'backlinks' => isset($c['backlinks']) ? (int) $c['backlinks'] : null,
                'da' => isset($c['da']) ? (int) $c['da'] : null,
                'pa' => isset($c['pa']) ? (int) $c['pa'] : null,
                'authority' => isset($c['authority']) ? (int) $c['authority'] : null,
            ];
        }

        // Strongest organic traffic first.
        usort($rows, fn ($a, $b) => ($b['traffic'] ?? -1) <=> ($a['traffic'] ?? -1));

        return ['traffic_total' => (int) round($total), 'traffic_count' => $count, 'rows' => $rows];
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
            ], ['temperature' => 0.1, 'max_tokens' => 800, 'timeout' => 30, '__source' => 'content_autopilot.kw_scrap', '__unmetered' => true]);

            $scrap = is_array($response['scrap'] ?? null) ? $response['scrap'] : [];

            return array_values(array_filter(array_map(
                static fn ($s) => mb_strtolower(trim((string) $s)), $scrap
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * LLM relevance filter for the "Questions your audience is asking" list.
     * Competitor/seed keyword mining can inject off-topic question keywords
     * (business naming for a gaming-name site). Keeps only questions genuinely
     * about the client's offerings/description. Cached 30d; fails OPEN (keeps
     * all) when the LLM is unavailable or would leave the list empty.
     *
     * @param  list<array{keyword:string, volume:?int}>  $questions
     * @return list<array{keyword:string, volume:?int}>
     */
    private function filterRelevantQuestions(array $questions, ContentPlan $plan): array
    {
        if (count($questions) < 2) {
            return $questions;
        }
        $keep = $this->relevanceKeep(array_map(static fn ($q) => (string) $q['keyword'], $questions), $plan, 'questions', 'qrel');
        if ($keep === null) {
            return $questions; // LLM unavailable → fail open
        }
        $keepSet = array_flip($keep);

        // Keep only questions the LLM judged on-topic. If it kept NONE, the list
        // is genuinely all off-topic noise (competitor/seed mining) → show none
        // rather than misleading questions.
        return array_values(array_filter(
            $questions,
            static fn ($q) => isset($keepSet[mb_strtolower(trim((string) $q['keyword']))])
        ));
    }

    /**
     * Shared LLM topical-relevance filter (questions + keyword gap). Returns the
     * lowercased subset of $items the LLM judged genuinely relevant to the plan's
     * offerings — or null when the LLM is unavailable/errored (caller fails open).
     * Cached 30d per (offerings, item-set). Off-topic competitor/seed keywords that
     * merely share a generic word ("generator", "name") are dropped.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private function relevanceKeep(array $items, ContentPlan $plan, string $noun, string $tag): ?array
    {
        $items = array_values(array_unique(array_filter(array_map(static fn ($s) => trim((string) $s), $items))));
        if (count($items) < 2) {
            return array_map(static fn ($s) => mb_strtolower($s), $items); // trivially keep
        }
        $offer = implode(', ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 10));
        $desc = mb_substr((string) $plan->business_description, 0, 400);

        $cacheKey = 'content:kw-'.$tag.':'.md5($offer.'|'.implode('|', $items));
        $keep = Cache::get($cacheKey);
        if (! is_array($keep)) {
            // null (unavailable/errored) → caller fails open. array (even empty) →
            // authoritative relevance judgement.
            $keep = $this->llmRelevantItems($items, $offer, $desc, $noun);
            if ($keep === null) {
                return null;
            }
            Cache::put($cacheKey, $keep, now()->addDays(self::CACHE_TTL_DAYS));
        }

        return $keep;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>|null lowercased on-topic items; null on LLM unavailable/error
     */
    private function llmRelevantItems(array $items, string $offer, string $desc, string $noun): ?array
    {
        try {
            $model = ContentAutopilotConfig::modelFor('ideate');
            $llm = LlmClientFactory::make($model['provider']);
            if (! $llm->isAvailable()) {
                return null;
            }
            $list = implode("\n", array_slice($items, 0, 150));
            $response = $llm->completeJson([
                ['role' => 'system', 'content' => "You filter SEO {$noun} lists for topical relevance. Respond with valid JSON only."],
                ['role' => 'user', 'content' => <<<PROMPT
                Business offerings: {$offer}
                About: {$desc}

                From the {$noun} below, return ONLY those genuinely relevant to THIS
                business's topic and audience — the ones its articles would actually
                target. DROP off-topic entries: unrelated tools, industries, languages
                or generic terms that merely share a common word (like "generator" or
                "name"). Keep the exact original text.

                {$list}

                Return JSON: {"relevant": ["...", "..."]}
                PROMPT],
            ], ['temperature' => 0.1, 'max_tokens' => 2000, 'timeout' => 40, '__source' => 'content_autopilot.kw_relevance', '__unmetered' => true]);

            $rel = is_array($response['relevant'] ?? null) ? $response['relevant'] : [];

            return array_values(array_filter(array_map(
                static fn ($s) => mb_strtolower(trim((string) $s)), $rel
            )));
        } catch (\Throwable) {
            return null;
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

        // LLM relevance pass over the top candidates — token overlap alone can't
        // tell a gaming-name gap from a competitor's "robotic voice generator" (both
        // share "generator"). Keep the LLM-vetted ones; fall back to the token-ranked
        // pool when the LLM is unavailable or would leave the gap empty.
        $candidates = array_slice($pool, 0, 50);
        $display = $candidates;
        $keep = $this->relevanceKeep(array_column($candidates, 'keyword'), $plan, 'search keywords', 'grel');
        if (is_array($keep)) {
            $keepSet = array_flip($keep);
            $vetted = array_values(array_filter(
                $candidates,
                static fn ($r) => isset($keepSet[mb_strtolower(trim((string) $r['keyword']))])
            ));
            if ($vetted !== []) {
                $display = $vetted;
            }
        }

        $rows = array_map(static fn ($r) => [
            'keyword' => $r['keyword'],
            'volume' => $r['volume'],
            'competition' => $r['competition'],
        ], array_slice($display, 0, 8));

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

    /**
     * DataForSEO keyword-overview pricing for the offer-spine candidate
     * queries (fix for the kayali onboarding: candidates never surfaced in
     * "best search terms" because the ranking required a volume and only the
     * keyword server assigned volumes). Writes into the shared
     * `keyword_metrics` asset (same `dfs_labs` source/TTL discipline as the
     * harvest); the digest's metric lookup then prices candidates for free.
     *
     * Once per plan per 30 days (Cache::add guard keyed on the candidate
     * signature); admin-owned sites sandbox and NEVER persist mock data or
     * charge the meter (same policy as every other DFS call site).
     */
    private function enrichCandidateMetrics(ContentPlan $plan): void
    {
        try {
            $candidates = app(OfferQueryGenerator::class)->candidates($plan);
            if ($candidates === []) {
                return;
            }
            $keywords = array_column($candidates, 'query');
            if (! Cache::add('content:kw-cand-dfs:'.$plan->id.':'.md5(implode('|', $keywords)), 1, now()->addDays(30))) {
                return;
            }

            // Skip keywords the shared asset already has (any country — a
            // volume from the wrong market beats none for display purposes;
            // the harvest's country-exact rows win naturally on insert).
            $hashes = [];
            foreach ($keywords as $kw) {
                $hashes[KeywordMetric::hashKeyword($kw)] = $kw;
            }
            $have = KeywordMetric::query()
                ->whereIn('keyword_hash', array_keys($hashes))
                ->pluck('keyword_hash')->all();
            $missing = array_values(array_intersect_key($hashes, array_flip(array_diff(array_keys($hashes), $have))));
            if ($missing === []) {
                return;
            }

            $dfs = app(\App\Services\DataForSeoBacklinkClient::class);
            $meter = app(\App\Services\Reports\DataForSeoSpendMeter::class);
            if (! $dfs->isConfigured() || $meter->exhausted()) {
                return;
            }

            $country = strtolower((string) ($plan->country ?: 'global'));
            [$location, $language] = \App\Services\DataForSeoBacklinkClient::labsGeo($country);
            $sandbox = (bool) $plan->website?->user?->is_admin;

            $rows = $dfs->useSandbox($sandbox)->resetCost()->keywordOverview($missing, [
                'location_code' => $location,
                'language_name' => $language,
            ]);
            $dfs->useSandbox(false);

            if ($sandbox || $rows === []) {
                return; // mock data must never enter the shared asset
            }
            $meter->add($dfs->totalCost());

            $now = now();
            $insert = [];
            foreach ($rows as $r) {
                $hash = KeywordMetric::hashKeyword($r['keyword']);
                if (in_array($hash, $have, true)) {
                    continue;
                }
                $insert[] = [
                    'id' => (string) Str::ulid(),
                    'keyword' => $r['keyword'],
                    'keyword_hash' => $hash,
                    'country' => $country,
                    'data_source' => 'dfs_labs',
                    'search_volume' => $r['search_volume'],
                    'cpc' => $r['cpc'],
                    'competition' => $r['competition'],
                    'keyword_difficulty' => $r['keyword_difficulty'],
                    'search_intent' => $r['search_intent'],
                    'fetched_at' => $now,
                    'expires_at' => $now->copy()->addDays(30),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($insert !== []) {
                KeywordMetric::query()->insert($insert);
            }
        } catch (\Throwable) {
            // pricing candidates is an upgrade, never a blocker
        }
    }

    /**
     * DFS keyword_suggestions per offer head — the demand-discovery half of
     * the offer spine (2026-07-23, after the kayali three-way comparison:
     * suggestions found "best vanilla perfume" 12,100/mo that neither our
     * keyword-server expansion nor the LLM candidates surfaced). One call per
     * offer (≤5 offers, ≤30 rows each, ~$0.08/onboarding), token-signature
     * deduped (the endpoint reports one query cluster as several word-order
     * variants), demand floor 50/mo, cap 8 per offer / 40 total. Results are
     * cached per plan as candidates ({query, offer, intent}) AND persisted
     * into the shared keyword_metrics asset so the ranking prices them.
     *
     * Admin-owned sites use the sandbox host and NEVER persist mock rows into
     * the shared asset or charge the meter (candidates cache is plan-local,
     * so mock terms only ever show on the admin's own plan).
     */
    private function ensureDfsSuggestions(ContentPlan $plan): void
    {
        try {
            $generator = app(OfferQueryGenerator::class);
            $offers = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                (array) (($plan->offerings ?? [])['sell'] ?? [])
            ), static fn ($v) => $v !== '' && ! OfferQueryGenerator::isPromoOffer($v)));
            $offers = array_slice($offers, 0, 5);
            if ($offers === []) {
                return;
            }
            $cacheKey = $this->dfsSuggestionKey($plan);
            if (Cache::has($cacheKey)) {
                return;
            }

            $dfs = app(\App\Services\DataForSeoBacklinkClient::class);
            $meter = app(\App\Services\Reports\DataForSeoSpendMeter::class);
            if (! $dfs->isConfigured() || $meter->exhausted()) {
                return;
            }

            $country = strtolower((string) ($plan->country ?: 'global'));
            [$location, $language] = \App\Services\DataForSeoBacklinkClient::labsGeo($country);
            $sandbox = (bool) $plan->website?->user?->is_admin;

            $candidates = [];
            $metricRows = [];
            $sigSeen = [];
            $dfs->useSandbox($sandbox)->resetCost();
            foreach ($offers as $offer) {
                $head = $generator->offerHead($offer);
                if ($head === '') {
                    continue;
                }
                $perOffer = 0;
                foreach ($dfs->keywordSuggestions($head, ['location_code' => $location, 'language_name' => $language, 'limit' => 30]) as $row) {
                    if ($perOffer >= 8 || count($candidates) >= 40) {
                        break;
                    }
                    if (($row['search_volume'] ?? 0) < 50) {
                        continue; // demand floor — this source exists FOR demand
                    }
                    // One Google query cluster arrives as several word-order
                    // variants ("best perfume vanilla"/"best vanilla perfume").
                    $tokens = preg_split('/\s+/', $row['keyword'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    sort($tokens);
                    $sig = implode(' ', $tokens);
                    if ($sig === '' || isset($sigSeen[$sig])) {
                        continue;
                    }
                    $sigSeen[$sig] = true;
                    $intent = in_array($row['search_intent'], ['informational', 'commercial', 'transactional', 'navigational'], true)
                        ? $row['search_intent'] : 'commercial';
                    $candidates[] = ['query' => $row['keyword'], 'offer' => $offer, 'intent' => $intent];
                    $metricRows[] = $row;
                    $perOffer++;
                }
            }
            $dfs->useSandbox(false);

            // Relevance vetting — same LLM pass questions/gap already use.
            // Kills cross-brand drift a junk offer seed drags in ("Exclusive
            // offers" → "nespresso exclusive offers", live kayali round #3);
            // fails open (null → keep all), and drops nothing when the model
            // is unavailable.
            if ($candidates !== []) {
                $offerLine = implode('; ', $offers);
                $desc = mb_substr((string) $plan->business_description, 0, 300);
                $kept = $this->llmRelevantItems(array_column($candidates, 'query'), $offerLine, $desc, 'search terms');
                if (is_array($kept)) {
                    $keptSet = array_flip(array_map(static fn ($k) => mb_strtolower(trim((string) $k)), $kept));
                    $candidates = array_values(array_filter(
                        $candidates,
                        static fn ($c) => isset($keptSet[$c['query']])
                    ));
                }
            }

            Cache::put($cacheKey, $candidates, now()->addDays(30));
            if ($sandbox || $metricRows === []) {
                return; // mock data never enters the shared asset
            }
            $meter->add($dfs->totalCost());

            $now = now();
            $hashes = [];
            foreach ($metricRows as $r) {
                $hashes[KeywordMetric::hashKeyword($r['keyword'])] = $r;
            }
            $have = KeywordMetric::query()->whereIn('keyword_hash', array_keys($hashes))->pluck('keyword_hash')->all();
            $insert = [];
            foreach (array_diff(array_keys($hashes), $have) as $hash) {
                $r = $hashes[$hash];
                $insert[] = [
                    'id' => (string) Str::ulid(),
                    'keyword' => $r['keyword'],
                    'keyword_hash' => $hash,
                    'country' => $country,
                    'data_source' => 'dfs_labs',
                    'search_volume' => $r['search_volume'],
                    'cpc' => $r['cpc'],
                    'competition' => $r['competition'],
                    'keyword_difficulty' => $r['keyword_difficulty'],
                    'search_intent' => $r['search_intent'],
                    'fetched_at' => $now,
                    'expires_at' => $now->copy()->addDays(30),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($insert !== []) {
                KeywordMetric::query()->insert($insert);
            }
        } catch (\Throwable) {
            // demand discovery is an upgrade, never a blocker
        }
    }

    private function dfsSuggestionKey(ContentPlan $plan): string
    {
        $offers = (array) (($plan->offerings ?? [])['sell'] ?? []);

        return 'content:kw-dfs-sugg:v1:'.$plan->id.':'.md5(json_encode([$plan->country, array_slice($offers, 0, 5)]));
    }

    /** @return list<array{query:string, offer:string, intent:string}> */
    private function dfsSuggestionCandidates(ContentPlan $plan): array
    {
        $cached = Cache::get($this->dfsSuggestionKey($plan));

        return is_array($cached) ? $cached : [];
    }

    /**
     * Persist the client's kept "best search terms" (the wizard's keyword
     * step, minus explicit removals) as `content_plan_keywords` type
     * `chosen` — the topic planner then materializes exactly one article per
     * row (source 'confirmed'). Shared by BOTH wizard hosts so the 1:1
     * behavior can't drift between dashboard and public onboarding.
     *
     * Idempotent upsert; a term the client removed in a later wizard visit is
     * deleted again unless a topic was already materialized from it.
     *
     * @param  list<string>  $removed  keywords the client crossed out
     * @return int number of confirmed terms now stored
     */
    public function confirmTerms(ContentPlan $plan, array $removed = []): int
    {
        $digest = $this->get($plan);
        if ($digest === null) {
            return 0;
        }

        $removedSet = array_flip(array_map(
            static fn ($k) => mb_strtolower(trim((string) $k)), $removed
        ));

        $kept = [];
        foreach ((array) ($digest['opportunities'] ?? []) as $opp) {
            $kw = mb_strtolower(trim((string) ($opp['keyword'] ?? '')));
            if ($kw === '' || isset($removedSet[$kw])) {
                continue;
            }
            $kept[$kw] = $opp;
        }

        try {
            $materialized = $plan->topics()
                ->where('source', 'confirmed')
                ->pluck('target_keyword')
                ->map(static fn ($k) => mb_strtolower(trim((string) $k)))
                ->flip();

            // Drop previously-confirmed terms the client has now crossed out —
            // unless the planner already turned them into a topic (the topic
            // list is the source of truth once materialized; dropTopic handles
            // those).
            ContentPlanKeyword::query()
                ->where('plan_id', $plan->id)
                ->where('type', ContentPlanKeyword::TYPE_CONFIRMED)
                ->get()
                ->each(function (ContentPlanKeyword $row) use ($kept, $materialized) {
                    $kw = mb_strtolower(trim((string) $row->keyword));
                    if (! isset($kept[$kw]) && ! $materialized->has($kw)) {
                        $row->delete();
                    }
                });

            foreach ($kept as $kw => $opp) {
                ContentPlanKeyword::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'keyword_hash' => KeywordMetric::hashKeyword($kw)],
                    [
                        'keyword' => $kw,
                        'type' => ContentPlanKeyword::TYPE_CONFIRMED,
                        'country' => (string) ($plan->country ?: 'global'),
                        'search_volume' => is_numeric($opp['volume'] ?? null) ? (int) $opp['volume'] : null,
                        'search_intent' => KeywordIntentClassifier::classify($kw),
                    ]
                );
            }

            return count($kept);
        } catch (\Throwable) {
            return 0; // never block a wizard step transition on this
        }
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
        // v2 (2026-07-23): payload gained offer lineage + winnability-ranked
        // opportunities. Version bump retires v1 entries naturally — never
        // mutate a live cache shape.
        return 'content:kw-insights:v2:'.$plan->id;
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

        // Offer-spine first: buyer-shaped candidate queries generated from the
        // confirmed offers × the site type's intent profile. These carry the
        // lineage the digest surfaces later; the server expands them into the
        // real keyword set. Fail-soft [] → the classic heads below still seed.
        foreach (app(OfferQueryGenerator::class)->candidates($plan) as $candidate) {
            $seeds[] = $candidate['query'];
            if (count($seeds) >= 10) {
                break; // leave room for offering heads + GSC within MAX_SEEDS
            }
        }

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
     * Deterministic pillars for when the LLM labeller is unavailable or comes back
     * too coarse (its label count swings run to run).
     *
     * The naive version grouped by each frequent term independently, which nested
     * and double-counted: "Name" (365), "Stylish" (66) and "Stylish Name" (41) all
     * claimed the same keywords, and one generic bucket swallowed most of the set.
     * Here every keyword is claimed by exactly ONE pillar — its MOST SPECIFIC
     * matching phrase (most words, then longest) — so pillars are disjoint and
     * meaningful, and the generic term only keeps what nothing better matched.
     *
     * @param  list<array{keyword:string, volume:?int}>  $rows
     * @return list<array{label:string, count:int, volume:int, top:list<string>}>
     */
    private function phraseClusters(array $rows): array
    {
        $terms = array_values(array_filter(array_column(KeywordTermGrouper::groups($rows, 12), 'term')));
        if ($terms === []) {
            return [];
        }
        // Most specific first, so "free fire stylish name" beats "name".
        usort($terms, static fn ($a, $b) => [substr_count($b, ' '), mb_strlen($b)]
            <=> [substr_count($a, ' '), mb_strlen($a)]);

        $groups = [];
        foreach ($rows as $r) {
            $kw = (string) ($r['keyword'] ?? '');
            if ($kw === '') {
                continue;
            }
            foreach ($terms as $term) {
                if (! str_contains($kw, $term)) {
                    continue;
                }
                $groups[$term] ??= ['label' => (string) Str::title($term), 'count' => 0, 'volume' => 0, 'top' => []];
                $groups[$term]['count']++;
                $groups[$term]['volume'] += (int) ($r['volume'] ?? 0);
                $groups[$term]['top'][] = $r;
                break; // one pillar per keyword — no overlap
            }
        }

        $groups = array_filter($groups, static fn ($g) => $g['count'] >= 3); // drop noise
        uasort($groups, static fn ($a, $b) => $b['volume'] <=> $a['volume']);

        $out = [];
        foreach (array_slice($groups, 0, 6) as $g) {
            usort($g['top'], static fn ($a, $b) => ($b['volume'] ?? 0) <=> ($a['volume'] ?? 0));
            $g['top'] = array_column(array_slice($g['top'], 0, 3), 'keyword');
            $out[] = $g;
        }

        return $out;
    }

    /**
     * Assign keywords the LLM labeller never saw (it caps at 300 by volume) to its
     * OWN cluster labels, by counting how many of a label's significant tokens the
     * keyword contains (singular/plural tolerant). Best-scoring label wins, so each
     * keyword lands in exactly ONE pillar — unlike the stem-based term grouper,
     * whose groups overlap ("Stylish" vs "Stylish Name"). Unmatched keywords stay
     * unlabelled and are skipped, as before.
     *
     * @param  array<string,string>  $map  keyword => label
     * @param  list<array{keyword:string, volume:?int}>  $rows
     * @return array<string,string>
     */
    private function extendClusterMap(array $map, array $rows): array
    {
        $other = (string) __('Other');

        $labelTokens = [];
        foreach (array_unique(array_values($map)) as $label) {
            $label = (string) $label;
            if ($label === '' || strcasecmp($label, $other) === 0) {
                continue;
            }
            $tokens = array_values(array_filter(
                preg_split('/[^a-z0-9]+/', mb_strtolower($label), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                static fn ($t) => mb_strlen($t) >= 3
            ));
            if ($tokens !== []) {
                $labelTokens[$label] = $tokens;
            }
        }
        if ($labelTokens === []) {
            return $map;
        }

        foreach ($rows as $r) {
            $kw = (string) ($r['keyword'] ?? '');
            if ($kw === '' || isset($map[$kw])) {
                continue;
            }
            $best = null;
            $bestScore = 0;
            foreach ($labelTokens as $label => $tokens) {
                $score = 0;
                foreach ($tokens as $t) {
                    $stem = rtrim($t, 's');
                    if (str_contains($kw, $t) || ($stem !== '' && str_contains($kw, $stem))) {
                        $score++;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $label;
                }
            }
            if ($best !== null) {
                $map[$kw] = $best;
            }
        }

        return $map;
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
        // ── Brand hygiene (2026-07-23, the kayali onboarding): guard-blocked
        // brand keywords out of the WHOLE digest — the guard forbids writing
        // about Sephora while the old digest simultaneously recommended
        // targeting "sephora birthday gift sets" and grew a 1,633-keyword
        // "Sephora Perfume" pillar. Own-brand terms stay in the digest (the
        // "Kayali Perfume" pillar is genuinely theirs) but are excluded from
        // opportunities below — people searching your brand already found you.
        $blockedTerms = [];
        $ownBrand = null;
        try {
            $guardSvc = app(CompetitorMentionGuard::class);
            $blockedTerms = $guardSvc->terms($plan);
            $ownBrand = $guardSvc->ownBrandToken($plan);
        } catch (\Throwable) {
        }
        $containsAny = static function (string $keyword, array $terms): bool {
            foreach ($terms as $t) {
                if ($t !== '' && str_contains($keyword, $t)) {
                    return true;
                }
            }

            return false;
        };
        if ($blockedTerms !== []) {
            $rows = array_values(array_filter($rows, fn ($r) => ! $containsAny((string) $r['keyword'], $blockedTerms)));
            $gap = array_values(array_filter($gap, fn ($g) => ! $containsAny(mb_strtolower((string) ($g['keyword'] ?? '')), $blockedTerms)));
        }

        $plannedKeywords = $plan->topics()->pluck('target_keyword')
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))->filter()->flip()->all();

        // ── Clusters: LLM labels when available (cached monthly), else term groups.
        $clusterMap = null;
        try {
            if ($this->clusters->isAvailable()) {
                // Cluster a SAMPLE, not the whole set. The labeller returns one
                // JSON entry per keyword, and ~300 of them overflow its max_tokens
                // → truncated JSON → null → we'd fall back to ugly stem groups
                // ("Name", "Free"). A top-by-volume sample answers reliably with
                // good names; extendClusterMap() then covers everything else.
                $sample = $rows;
                usort($sample, static fn ($a, $b) => (int) ($b['volume'] ?? 0) <=> (int) ($a['volume'] ?? 0));
                $sample = array_slice($sample, 0, self::CLUSTER_SAMPLE);

                $keywordsSorted = array_column($sample, 'keyword');
                sort($keywordsSorted);
                $clusterMap = $this->clusters->cluster(
                    array_map(fn ($r) => ['keyword' => $r['keyword'], 'volume' => $r['volume']], $sample),
                    'content-wizard:'.md5(implode('|', $keywordsSorted)),
                );
            }
        } catch (\Throwable) {
            $clusterMap = null;
        }

        // The labeller only sees the top AiKeywordClusterService::MAX_KEYWORDS (300)
        // by volume, so on a 500+ keyword set the remainder arrive UNLABELLED and
        // silently vanish from the pillars. Fold them into the LLM's OWN labels by
        // token overlap: keeps the readable names, covers the whole set, and gives
        // exactly one label per keyword.
        if (is_array($clusterMap) && $clusterMap !== []) {
            $clusterMap = $this->extendClusterMap($clusterMap, $rows);
        }

        $clusters = [];
        if (is_array($clusterMap) && $clusterMap !== []) {
            $grouped = [];
            foreach ($rows as $r) {
                $label = $clusterMap[$r['keyword']] ?? null;
                if ($label === null || strcasecmp($label, 'Other') === 0) {
                    continue;
                }
                // The labeller's casing is inconsistent ("stylish names" vs
                // "PUBG Names") — title-case the all-lowercase ones only, so
                // acronyms survive.
                $display = $label === mb_strtolower($label)
                    ? (string) Str::title($label)
                    : $label;
                $grouped[$label] ??= ['label' => $display, 'count' => 0, 'volume' => 0, 'top' => []];
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
        }

        // The LLM labeller caps its input (~300 keywords) and dumps a big slice into
        // "Other", and its label granularity swings run to run — on a 500+ keyword
        // set that can collapse the pillars to 2. The deterministic term grouper
        // always yields ~6, so use it whenever the LLM pass came back thin.
        if (count($clusters) < self::MIN_CLUSTERS) {
            $clusters = $this->phraseClusters($rows);
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
        // Drop questions that aren't about THIS business — competitor/seed keyword
        // mining can pull in off-topic question keywords (e.g. "how to name your
        // company" for a gaming-name generator). LLM relevance pass; fails open.
        $questionList = $this->filterRelevantQuestions($questionList, $plan);
        $questionsTotal = count($questionList);
        $questions = array_slice($questionList, 0, 8);

        // ── Opportunities — the offer-spine ranking flip (2026-07-23):
        // winnability (keyword difficulty vs OWN authority, else competition
        // tier) × the site type's intent weight decides the order; volume only
        // breaks ties. A DA-15 site no longer gets difficulty-60 head terms it
        // can never win just because they carry volume. Each pick also carries
        // its offer lineage ("because you sell: X") when attribution is
        // confident.
        $profile = \App\Support\ContentSiteTypeProfiles::profile($plan->site_type);
        $intentWeights = $profile['intent_weights'] + ['other' => 0.5];
        $avoidPatterns = (array) ($profile['avoid_patterns'] ?? []);
        $ownDa = null;
        $difficultyByKeyword = [];
        $volumeByKeyword = [];
        $competitionByKeyword = [];
        $originByKeyword = [];
        $candidates = [];
        try {
            $website = $plan->website;
            $ownDa = $website !== null ? KeywordWinnability::ownAuthority($website) : null;

            $generator = app(OfferQueryGenerator::class);
            $candidates = $generator->candidates($plan);
            // DFS demand-discovered candidates (real searches around each
            // offer) join the LLM/mechanical ones — first source wins on a
            // duplicate query.
            $candidateQueries = array_flip(array_column($candidates, 'query'));
            foreach ($this->dfsSuggestionCandidates($plan) as $c) {
                if (! isset($candidateQueries[$c['query']])) {
                    $candidates[] = $c;
                    $candidateQueries[$c['query']] = true;
                }
            }
            $sell = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                (array) (($plan->offerings ?? [])['sell'] ?? [])
            )));
            $originByKeyword = $generator->attribute(array_column($rows, 'keyword'), $candidates, $sell);
            // Candidates KNOW their offer — exact lineage beats attribution.
            foreach ($candidates as $c) {
                $originByKeyword[$c['query']] = $c['offer'];
            }

            // Difficulty + volume from the shared asset (harvest + the
            // candidate keyword-overview enrichment) — already-paid data.
            $hashes = [];
            foreach ($rows as $r) {
                $hashes[KeywordMetric::hashKeyword($r['keyword'])] = $r['keyword'];
            }
            foreach ($candidates as $c) {
                $hashes[KeywordMetric::hashKeyword($c['query'])] = $c['query'];
            }
            foreach (KeywordMetric::query()->whereIn('keyword_hash', array_keys($hashes))
                ->get(['keyword_hash', 'keyword_difficulty', 'search_volume', 'competition']) as $m) {
                $kw = $hashes[$m->keyword_hash] ?? null;
                if ($kw === null) {
                    continue;
                }
                if ($m->keyword_difficulty !== null) {
                    $difficultyByKeyword[$kw] = (int) $m->keyword_difficulty;
                }
                if ($m->search_volume !== null) {
                    $volumeByKeyword[$kw] = (int) $m->search_volume;
                }
                if ($m->competition !== null) {
                    $competitionByKeyword[$kw] = (float) $m->competition;
                }
            }
        } catch (\Throwable) {
            // Deterministic fallback below still ranks by competition tier.
        }

        $score = function (array $r) use ($intentWeights, $difficultyByKeyword, $ownDa, $avoidPatterns): float {
            $winnability = KeywordWinnability::score(
                $difficultyByKeyword[$r['keyword']] ?? null,
                (string) $r['competition'],
                $ownDa
            );
            $score = $winnability * (float) ($intentWeights[$r['intent']] ?? 0.5);
            // Offer-spine candidates: fit bonus when demand exists or is
            // unknown — but a DFS-priced ZERO volume means nobody searches
            // the phrase, and offer-true-but-unsearched must sink below real
            // demand (live kayali round #2: six vol=0 candidates crowded out
            // a 5,500/mo real term).
            if (! empty($r['is_candidate'])) {
                $volume = $r['volume'] ?? null;
                $score *= match (true) {
                    $volume === null => 1.1,   // unpriced — plausible
                    $volume === 0 => 0.4,      // priced: no demand
                    default => 1.25,           // priced: real demand + offer fit
                };
            }
            // Wrong-site-type queries ("perfume reviews" for a brand) sink.
            foreach ($avoidPatterns as $pattern) {
                if (@preg_match($pattern, $r['keyword']) === 1) {
                    $score *= 0.2;
                    break;
                }
            }

            return $score;
        };

        $opps = array_values(array_filter($rows, fn ($r) => ($r['volume'] ?? 0) > 0));
        // Offer-spine candidates join as first-class rows (volume from the
        // DFS candidate enrichment where it landed; null is allowed — an
        // offer-true term without a priced volume still beats junk).
        $inRows = array_flip(array_column($opps, 'keyword'));
        foreach ($candidates as $c) {
            $kw = $c['query'];
            if (isset($inRows[$kw]) || ($blockedTerms !== [] && $containsAny($kw, $blockedTerms))) {
                continue;
            }
            $difficulty = $difficultyByKeyword[$kw] ?? null;
            // Tier from difficulty when known, else from the DFS competition
            // float (suggestions often carry competition but zero difficulty).
            $comp01 = $competitionByKeyword[$kw] ?? null;
            $tier = match (true) {
                $difficulty !== null => $difficulty < 34 ? 'low' : ($difficulty < 67 ? 'medium' : 'high'),
                $comp01 !== null => $comp01 < 0.34 ? 'low' : ($comp01 < 0.67 ? 'medium' : 'high'),
                default => 'unknown',
            };
            $opps[] = [
                'keyword' => $kw,
                'volume' => $volumeByKeyword[$kw] ?? null,
                'competition' => $tier,
                'intent' => $c['intent'],
                'is_candidate' => true,
            ];
        }
        // Own-brand terms out of opportunities only: people searching the
        // brand already found the site — articles must win NEW audiences.
        if ($ownBrand !== null) {
            $opps = array_values(array_filter($opps, fn ($r) => ! str_contains((string) $r['keyword'], $ownBrand)));
        }
        usort($opps, function ($a, $b) use ($score) {
            $cmp = $score($b) <=> $score($a);

            return $cmp !== 0 ? $cmp : ((int) ($b['volume'] ?? 0) <=> (int) ($a['volume'] ?? 0));
        });
        // Mix guard: at most 4 of the 6 picks may be generator candidates —
        // the list must stay anchored in observed demand, not read as a
        // template output.
        $picked = [];
        $candidateCount = 0;
        foreach ($opps as $r) {
            $isCandidate = ! empty($r['is_candidate']);
            if ($isCandidate && $candidateCount >= 4) {
                continue;
            }
            $picked[] = $r;
            $candidateCount += $isCandidate ? 1 : 0;
            if (count($picked) >= 6) {
                break;
            }
        }
        $opportunities = array_map(fn ($r) => [
            'keyword' => $r['keyword'],
            'volume' => $r['volume'] ?? ($volumeByKeyword[$r['keyword']] ?? null),
            'competition' => $r['competition'],
            'planned' => isset($plannedKeywords[$r['keyword']]),
            'origin' => $originByKeyword[$r['keyword']] ?? null,
        ], $picked);

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
