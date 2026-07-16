<?php

namespace App\Services\Reports;

use App\Models\KeywordApiRequest;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\Competitive\CompetitorDiscoveryService;
use App\Services\Competitive\SerpCache;
use App\Services\Crawler\CrawlFetcher;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordFinder\KeywordIdeasMonthlyCache;
use App\Services\Llm\LlmClient;
use App\Services\MozLinksClient;
use App\Services\OpenPageRankClient;
use App\Support\Audit\HtmlAuditor;
use App\Support\GiantDomains;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a PARTIAL Site Explorer report for domains DataForSEO knows nothing
 * about (young sites), so the funnel never dead-ends on "no data".
 *
 * Composed entirely of existing primitives, cheapest-first:
 *  - Open PageRank (free)     → popularity
 *  - Moz (free tier)          → DA/PA/spam gauges
 *  - CrawlFetcher (free)      → 2-3 pages of body text
 *  - Keyword fleet (self-hosted) → site keywords; on competitor fallback,
 *    the best competitor's keywords as "keyword opportunities"
 *  - LLM completeJson (1 call)   → junk-keyword check + SERP query generation
 *  - SerpCache (metered, capped) → competitor tally when keywords are junk
 *  - DataForSEO               → NEVER called here
 *
 * Also merges a keywords section into FULL ('ready') reports via the same
 * async keyword-fleet flow (`bootstrapReadyKeywords` / stage `ready_keywords`).
 *
 * State machine lives in website_report_snapshots.enrichment_state; every
 * failure path finalizes with whatever exists — a stuck 'enriching' row also
 * self-heals via the short partial TTL in ReportFreshnessGate.
 */
class ReportEnrichmentService
{
    private const COMPETITOR_ROW_CAP = 15;

    /** Max keyword rows per section — config-driven (default 100). */
    private function keywordRowCap(): int
    {
        return max(1, (int) config('services.report.enrichment.keyword_rows', 100));
    }

    public function __construct(
        private OpenPageRankClient $opr,
        private MozLinksClient $moz,
        private CrawlFetcher $fetcher,
        private KeywordFinderPool $keywords,
        private SerpCache $serp,
        private LlmClient $llm,
        private ClientReportService $reports,
        private CompetitorDiscoveryService $discovery,
    ) {
    }

    // ─── Public entry points for the standalone Competitor Discovery tool ───

    /**
     * Keyword-ideas lookup for a domain (monthly-cache-first). Public wrapper
     * so the Competitor Discovery page can start/poll the fleet request.
     *
     * @return array{rows: ?list<array<string, mixed>>, id: ?string, cache_key: string}
     */
    public function keywordIdeasFor(string $domain, ?string $billedUserId = null): array
    {
        return $this->ideasRequestFor($domain, $billedUserId);
    }

    /**
     * Normalized keyword rows for a tracked request (or its monthly cache),
     * null while still pending.
     *
     * @param  array<string, mixed>  $ref  {id: ?string, cache_key: ?string}
     * @return list<array<string, mixed>>|null
     */
    public function keywordRowsFor(array $ref): ?array
    {
        $rows = $this->requestRows($ref);

        return $rows === null ? null : $this->normalizeKeywordRows($rows);
    }

    /**
     * Discover competitors for ANY url from its keyword profile, minimizing
     * SERP calls: LLM junk-checks the keywords first; if they're genuine the
     * top keywords ARE the SERP queries (no crawl, no query-gen); only when
     * they're scrap (login/signup/…) do we crawl a few pages and ask the LLM
     * to invent realistic queries. Then a single capped, cache-shared SERP
     * tally. Reuses the report-enrichment primitives.
     *
     * @param  list<array<string, mixed>>  $keywordRows
     * @return array{competitors: list<array<string, mixed>>, best: ?string,
     *               scrap: bool, query_source: string, queries: list<string>}
     */
    public function discoverCompetitorsFor(string $domain, array $keywordRows, ?string $billedUserId = null): array
    {
        $cap = max(1, (int) config('services.report.enrichment.serp_query_cap', 8));
        $genuine = $this->keywordsGenuine($keywordRows, $domain, $billedUserId);

        if ($genuine) {
            // The keywords themselves are the searches — no crawl, no LLM query-gen.
            $queries = array_slice(array_map(
                static fn (array $r): string => (string) ($r['keyword'] ?? ''),
                $keywordRows,
            ), 0, $cap);
            $querySource = 'keywords';
        } else {
            // Scrap keywords → crawl pages and let the LLM derive real queries.
            $pageText = $this->fetchPageText($domain);
            $queries = $this->classifyAndQueries($keywordRows, $pageText, $domain)['queries'];
            $querySource = 'page_content';
        }

        $queries = array_values(array_filter(array_map('trim', $queries), static fn ($q) => $q !== ''));
        if ($queries === []) {
            return ['competitors' => [], 'best' => null, 'scrap' => ! $genuine, 'query_source' => $querySource, 'queries' => []];
        }

        $tally = $this->tallyCompetitors($queries, $domain, $billedUserId);

        return [
            'competitors' => $tally['rows'],
            'best' => $tally['best'],
            'scrap' => ! $genuine,
            'query_source' => $querySource,
            'queries' => array_slice($queries, 0, $cap),
        ];
    }

    /**
     * Cheap LLM junk-check on the keyword list ALONE (no page fetch) — the
     * gate that decides whether we can use the keywords as SERP queries or
     * must crawl for real ones. null LLM → assume genuine (use the keywords).
     *
     * @param  list<array<string, mixed>>  $keywordRows
     */
    public function keywordsGenuine(array $keywordRows, string $domain, ?string $billedUserId = null): bool
    {
        if ($keywordRows === []) {
            return false;
        }

        $list = implode(', ', array_map(
            static fn (array $r): string => (string) ($r['keyword'] ?? ''),
            array_slice($keywordRows, 0, 40),
        ));

        $result = $this->llm->completeJson([
            [
                'role' => 'system',
                'content' => 'You are an SEO analyst. Reply with strict JSON only: {"genuine": bool}. '
                    .'"genuine" is true when the keyword list reflects the website\'s real topic / '
                    .'products / services, and false when it is dominated by generic UI or account '
                    .'boilerplate (signup, login, sign in, create account, password, dashboard, etc.).',
            ],
            ['role' => 'user', 'content' => "Website: {$domain}\nKeywords: {$list}"],
        ], [
            'json_object' => true,
            'temperature' => 0.1,
            'max_tokens' => 60,
            'timeout' => 30,
            '__source' => 'competitor_discovery',
            '__website_id' => $this->ownerWebsite($domain)?->id,
            '__user_id' => $this->ownerWebsite($domain)?->user_id ?? $billedUserId,
        ]);

        if ($result === null) {
            return true; // no LLM → treat keywords as usable rather than crawl
        }

        return (bool) ($result['genuine'] ?? true);
    }

    /**
     * Stage A for an empty domain: gather the free sync signals, then kick off
     * async keyword discovery for the domain itself.
     */
    public function bootstrap(string $domain): void
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        if ($snapshot === null || $snapshot->status !== 'enriching') {
            return;
        }

        $state = is_array($snapshot->enrichment_state) ? $snapshot->enrichment_state : [];
        $state['started_at'] ??= now()->toIso8601String();
        $state['attempts'] = 0;

        try {
            $state['popularity'] = $this->popularityFor($domain);
            $state['moz'] = $this->moz->isConfigured() ? $this->moz->urlMetrics($domain) : null;
            $state['page_text'] = $this->fetchPageText($domain);

            $request = $this->ideasRequestFor($domain);
            if ($request['rows'] !== null) {
                // Monthly cache hit — no dispatch needed; classify on the
                // next advance() tick using the cached rows.
                $state['own_request'] = ['id' => null, 'cache_key' => $request['cache_key']];
            } elseif ($request['id'] !== null) {
                $state['own_request'] = ['id' => $request['id'], 'cache_key' => $request['cache_key']];
            } else {
                // Keyword fleet unavailable — finalize with what we have.
                $this->finalize($domain, $state);

                return;
            }

            $state['stage'] = 'await_own_keywords';
            $this->saveState($snapshot, $state);
            \App\Jobs\FinalizeReportEnrichmentJob::dispatch($domain)
                ->delay(now()->addSeconds($this->pollSeconds()));
        } catch (Throwable $e) {
            Log::warning('ReportEnrichment bootstrap failed', ['domain' => $domain, 'message' => $e->getMessage()]);
            $this->finalize($domain, $state);
        }
    }

    /**
     * Keywords-only flow for a FULL report: fetch/dispatch site keywords and
     * merge them into the existing 'ready' payload when they arrive.
     */
    public function bootstrapReadyKeywords(string $domain): void
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload)) {
            return;
        }
        $payload = $snapshot->payload;

        // Keywords: backfill when missing OR below the current cap (the raw
        // fleet result is monthly-cached, so this is free). Competitors: when
        // DataForSEO's Labs endpoint returned none — discover them via SERP
        // (LLM queries → SERP tally), same as the new-site path. `competitors_enriched`
        // marks a single attempt so repeat /keywords views don't re-spend SERP.
        $needKeywords = count($payload['keywords'] ?? []) < $this->keywordRowCap();
        $needCompetitors = empty($payload['competitors']) && empty($payload['meta']['competitors_enriched']);

        // Free, no-API fill: derive "top pages by backlinks" from the stored
        // backlink sample when DataForSEO's domain_pages gave nothing.
        if (empty($payload['top_pages']) && ! empty($payload['backlinks'])) {
            $derived = $this->reports->deriveTopPagesFromBacklinks($payload['backlinks']);
            if ($derived !== []) {
                $this->patchReadyPayload($domain, ['top_pages' => $derived]);
            }
        }

        if (! $needKeywords && ! $needCompetitors) {
            return;
        }

        try {
            $bestCompetitor = null;

            // ── Competitors (synchronous: pages → LLM queries → SERP tally) ──
            if ($needCompetitors) {
                $pageText = $this->fetchPageText($domain);
                $queries = $this->classifyAndQueries([], $pageText, $domain)['queries'];
                $tally = $this->tallyCompetitors($queries, $domain);
                $bestCompetitor = $tally['best'];
                $this->patchReadyPayload($domain, ['competitors' => $tally['rows']], array_merge(
                    ['competitors_enriched' => true],
                    $tally['rows'] !== [] ? ['sources' => ['competitors' => 'search_results']] : [],
                ));
            }

            // ── Async keyword fetches: site keywords + best-competitor opps ──
            $pending = [];
            if ($needKeywords) {
                $req = $this->ideasRequestFor($domain);
                if ($req['rows'] !== null) {
                    $this->patchReadyPayload($domain, ['keywords' => $this->normalizeKeywordRows($req['rows'])], ['sources' => ['keywords' => 'estimated']]);
                } elseif ($req['id'] !== null) {
                    $pending['keywords'] = ['id' => $req['id'], 'cache_key' => $req['cache_key']];
                }
            }
            if ($bestCompetitor !== null && empty($payload['keyword_opportunities'])) {
                $req = $this->ideasRequestFor($bestCompetitor);
                if ($req['rows'] !== null) {
                    $this->patchReadyPayload($domain, ['keyword_opportunities' => $this->normalizeKeywordRows($req['rows'])],
                        ['sources' => ['keyword_opportunities' => 'similar_site'], 'opportunity_source' => $bestCompetitor]);
                } elseif ($req['id'] !== null) {
                    $pending['opportunities'] = ['id' => $req['id'], 'cache_key' => $req['cache_key'], 'source' => $bestCompetitor];
                }
            }

            if ($pending !== []) {
                WebsiteReportSnapshot::forDomain($domain)?->forceFill([
                    'enrichment_state' => [
                        'stage' => 'ready_merge',
                        'started_at' => now()->toIso8601String(),
                        'attempts' => 0,
                        'requests' => $pending,
                    ],
                ])->save();
                \App\Jobs\FinalizeReportEnrichmentJob::dispatch($domain)
                    ->delay(now()->addSeconds($this->pollSeconds()));
            }
        } catch (Throwable $e) {
            Log::warning('ReportEnrichment ready backfill failed', ['domain' => $domain, 'message' => $e->getMessage()]);
        }
    }

    /**
     * One poll tick. Returns true when the pipeline finished (no more polls
     * needed), false when the caller should re-dispatch a delayed poll.
     */
    public function advance(string $domain): bool
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        if ($snapshot === null || empty($snapshot->enrichment_state)) {
            return true;
        }

        $state = $snapshot->enrichment_state;
        $stage = (string) ($state['stage'] ?? '');

        // Overall budget: twice the per-stage timeout OR a hard poll-count
        // cap, then finalize with whatever we have. Never leaves the row stuck.
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $this->saveState($snapshot, $state);

        $started = isset($state['started_at']) ? \Illuminate\Support\Carbon::parse($state['started_at']) : now();
        $budgetExceeded = $state['attempts'] > 40
            || $started->lt(now()->subMinutes($this->ideasTimeoutMinutes() * 2));

        try {
            return match ($stage) {
                'await_own_keywords' => $this->advanceOwnKeywords($snapshot, $state, $budgetExceeded),
                'await_competitor_keywords' => $this->advanceCompetitorKeywords($snapshot, $state, $budgetExceeded),
                'ready_keywords' => $this->advanceReadyKeywords($snapshot, $state, $budgetExceeded),
                'ready_merge' => $this->advanceReadyMerge($snapshot, $state, $budgetExceeded),
                default => $this->finishNow($snapshot, $state),
            };
        } catch (Throwable $e) {
            Log::warning('ReportEnrichment advance failed', ['domain' => $domain, 'stage' => $stage, 'message' => $e->getMessage()]);
            $this->finishNow($snapshot, $state);

            return true;
        }
    }

    // ------------------------------------------------------------------ stages

    /**
     * @param  array<string, mixed>  $state
     */
    private function advanceOwnKeywords(WebsiteReportSnapshot $snapshot, array $state, bool $budgetExceeded): bool
    {
        $rows = $this->requestRows($state['own_request'] ?? []);

        if ($rows === null) {
            // Still pending.
            if ($budgetExceeded || $this->requestFailed($state['own_request'] ?? [])) {
                $this->finalize($snapshot->normalized_domain, $state);

                return true;
            }

            return false;
        }

        $keywordRows = $this->normalizeKeywordRows($rows);
        $verdict = $this->classifyAndQueries($keywordRows, $state['page_text'] ?? [], $snapshot->normalized_domain);

        // ALWAYS discover likely competitors via SERP for a new site — the
        // competitor section is core value here, not just the fallback path.
        $tally = $this->tallyCompetitors($verdict['queries'], $snapshot->normalized_domain);
        $state['competitors'] = $tally['rows'];

        if ($verdict['genuine'] && $keywordRows !== []) {
            $state['keywords'] = $keywordRows;
            $this->finalize($snapshot->normalized_domain, $state);

            return true;
        }

        // Boilerplate keywords (brand-new site: "signup", "login", …) or none
        // at all → borrow the best competitor's keyword profile as opportunities.
        $state['opportunity_source'] = $tally['best'];

        if ($tally['best'] === null) {
            $this->finalize($snapshot->normalized_domain, $state);

            return true;
        }

        $request = $this->ideasRequestFor($tally['best']);
        if ($request['rows'] !== null) {
            $state['keyword_opportunities'] = $this->normalizeKeywordRows($request['rows']);
            $this->finalize($snapshot->normalized_domain, $state);

            return true;
        }
        if ($request['id'] === null) {
            $this->finalize($snapshot->normalized_domain, $state);

            return true;
        }

        $state['competitor_request'] = ['id' => $request['id'], 'cache_key' => $request['cache_key']];
        $state['stage'] = 'await_competitor_keywords';
        $this->saveState($snapshot, $state);

        return false;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function advanceCompetitorKeywords(WebsiteReportSnapshot $snapshot, array $state, bool $budgetExceeded): bool
    {
        $rows = $this->requestRows($state['competitor_request'] ?? []);

        if ($rows === null) {
            if ($budgetExceeded || $this->requestFailed($state['competitor_request'] ?? [])) {
                $this->finalize($snapshot->normalized_domain, $state);

                return true;
            }

            return false;
        }

        $state['keyword_opportunities'] = $this->normalizeKeywordRows($rows);
        $this->finalize($snapshot->normalized_domain, $state);

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function advanceReadyKeywords(WebsiteReportSnapshot $snapshot, array $state, bool $budgetExceeded): bool
    {
        $rows = $this->requestRows($state['own_request'] ?? []);

        if ($rows === null) {
            if ($budgetExceeded || $this->requestFailed($state['own_request'] ?? [])) {
                // Give up quietly — clear the state so polling stops; the full
                // report simply has no keywords section this cycle.
                $snapshot->forceFill(['enrichment_state' => null])->save();

                return true;
            }

            return false;
        }

        $this->mergeKeywordsIntoReady($snapshot->normalized_domain, $this->normalizeKeywordRows($rows));

        return true;
    }

    // ------------------------------------------------------------------ finalize

    /**
     * Assemble the partial payload and atomically claim enriching → partial.
     * Safe to call from any path; a lost claim (another poll won) is a no-op.
     *
     * @param  array<string, mixed>  $state
     */
    private function finalize(string $domain, array $state): void
    {
        $payload = $this->reports->assemblePartial($domain, [
            'opr' => $state['popularity'] ?? null,
            'moz' => $state['moz'] ?? null,
            'keywords' => $state['keywords'] ?? [],
            'keyword_opportunities' => $state['keyword_opportunities'] ?? [],
            'competitors' => $state['competitors'] ?? [],
            'opportunity_source' => $state['opportunity_source'] ?? null,
        ]);

        WebsiteReportSnapshot::query()
            ->where('normalized_domain', WebsiteReportSnapshot::keyFor($domain))
            ->where('status', 'enriching')
            ->update(array_merge($this->reports->headlineColumns($payload), [
                'status' => 'partial',
                'payload' => json_encode($payload),
                'enrichment_state' => null,
                'fetched_at' => now(),
            ]));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finishNow(WebsiteReportSnapshot $snapshot, array $state): bool
    {
        if ($snapshot->status === 'enriching') {
            $this->finalize($snapshot->normalized_domain, $state);
        } else {
            $snapshot->forceFill(['enrichment_state' => null])->save();
        }

        return true;
    }

    /**
     * Merge a keywords section into an existing FULL report payload without
     * touching anything else. Conditional single-statement update so a
     * concurrent regeneration (which replaces the whole payload) wins.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mergeKeywordsIntoReady(string $domain, array $rows): void
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload) || $rows === []) {
            $snapshot?->forceFill(['enrichment_state' => null])->save();

            return;
        }

        $payload = $snapshot->payload;
        $payload['keywords'] = $rows;
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'sources' => array_merge($payload['meta']['sources'] ?? [], ['keywords' => 'estimated']),
        ]);

        WebsiteReportSnapshot::query()
            ->where('id', $snapshot->id)
            ->where('status', 'ready')
            ->where('fetched_at', $snapshot->fetched_at)
            ->update(['payload' => json_encode($payload), 'enrichment_state' => null]);
    }

    /**
     * Poll the pending site-keyword + competitor-opportunity fetches for a
     * ready report, merging each into the payload as it completes. Clears the
     * state (stops polling) once all are done, failed, or the budget lapses.
     *
     * @param  array<string, mixed>  $state
     */
    private function advanceReadyMerge(WebsiteReportSnapshot $snapshot, array $state, bool $budgetExceeded): bool
    {
        $domain = $snapshot->normalized_domain;
        $requests = is_array($state['requests'] ?? null) ? $state['requests'] : [];
        $stillPending = [];

        foreach ($requests as $slot => $ref) {
            $rows = $this->requestRows(is_array($ref) ? $ref : []);
            if ($rows !== null) {
                $norm = $this->normalizeKeywordRows($rows);
                if ($slot === 'keywords') {
                    $this->patchReadyPayload($domain, ['keywords' => $norm], ['sources' => ['keywords' => 'estimated']]);
                } else {
                    $this->patchReadyPayload($domain, ['keyword_opportunities' => $norm],
                        ['sources' => ['keyword_opportunities' => 'similar_site'], 'opportunity_source' => $ref['source'] ?? null]);
                }

                continue; // merged
            }
            if ($this->requestFailed(is_array($ref) ? $ref : [])) {
                continue; // drop
            }
            $stillPending[$slot] = $ref;
        }

        if ($stillPending === [] || $budgetExceeded) {
            WebsiteReportSnapshot::query()->where('id', $snapshot->id)->update(['enrichment_state' => null]);

            return true;
        }

        $state['requests'] = $stillPending;
        $this->saveState($snapshot->fresh() ?? $snapshot, $state);

        return false;
    }

    /**
     * Merge a set of keys into an existing FULL ('ready') report payload without
     * disturbing the rest or clearing enrichment_state (several patches can land
     * across a poll cycle). `$meta` may carry a `sources` sub-array; any other
     * keys are merged into `meta` directly. Conditional on status='ready' so a
     * concurrent regeneration (new payload) is never clobbered.
     *
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $meta
     */
    private function patchReadyPayload(string $domain, array $patch, array $meta = []): bool
    {
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        if ($snapshot === null || $snapshot->status !== 'ready' || empty($snapshot->payload)) {
            return false;
        }

        $payload = $snapshot->payload;
        foreach ($patch as $k => $v) {
            $payload[$k] = $v;
        }

        $sources = $meta['sources'] ?? [];
        unset($meta['sources']);
        $payload['meta'] = array_merge($payload['meta'] ?? [], $meta);
        if ($sources !== []) {
            $payload['meta']['sources'] = array_merge($payload['meta']['sources'] ?? [], $sources);
        }

        return WebsiteReportSnapshot::query()
            ->where('id', $snapshot->id)
            ->where('status', 'ready')
            ->update(['payload' => json_encode($payload)]) > 0;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>|null
     */
    private function popularityFor(string $domain): ?array
    {
        $metrics = $this->opr->metricsFor([$domain]);

        return $metrics[strtolower($domain)] ?? (reset($metrics) ?: null);
    }

    /**
     * Fetch the homepage + up to (max_pages - 1) same-host links, returning
     * capped body-text excerpts. All fetches SSRF-guarded by CrawlFetcher.
     *
     * @return list<string>
     */
    private function fetchPageText(string $domain): array
    {
        $maxPages = max(1, (int) config('services.report.enrichment.max_pages', 3));
        $texts = [];

        $home = $this->fetcher->fetch('https://'.$domain.'/', timeout: 15);
        if (! ($home['ok'] ?? false) || ! is_string($home['body'] ?? null) || $home['body'] === '') {
            return $texts;
        }

        $auditor = new HtmlAuditor($home['body'], 'https://'.$domain.'/');
        $texts[] = $this->excerpt((string) ($auditor->content()['body_text'] ?? ''));

        $links = $auditor->links();

        // Tier-1 link-graph harvest — free byproduct of a fetch that already
        // happened (EdgeRecorder never throws).
        if (! empty($links['external'])) {
            app(\App\Services\LinkGraph\EdgeRecorder::class)->record(
                'https://'.$domain.'/',
                $links['external'],
                \App\Services\LinkGraph\EdgeRecorder::SOURCE_ENRICHMENT,
            );
        }

        $internal = array_slice($links['internal'] ?? [], 0, ($maxPages - 1) * 3);
        foreach ($internal as $link) {
            if (count($texts) >= $maxPages) {
                break;
            }
            $href = (string) ($link['href'] ?? '');
            if ($href === '' || rtrim($href, '/') === 'https://'.$domain) {
                continue;
            }
            $res = $this->fetcher->fetch($href, timeout: 15);
            if (! ($res['ok'] ?? false) || ! is_string($res['body'] ?? null)) {
                continue;
            }
            $text = $this->excerpt((string) ((new HtmlAuditor($res['body'], $href))->content()['body_text'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return array_values(array_filter($texts, static fn (string $t): bool => $t !== ''));
    }

    private function excerpt(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 2000);
    }

    /**
     * ONE combined LLM call: is this keyword list genuinely about the site
     * (vs. generic UI boilerplate like "signup"/"login" that Google Ads
     * returns for content-less new sites), and — from the page text — what
     * would a potential visitor actually search for?
     *
     * @param  list<array<string, mixed>>  $keywordRows
     * @param  list<string>  $pageText
     * @return array{genuine: bool, queries: list<string>}
     */
    private function classifyAndQueries(array $keywordRows, array $pageText, string $domain): array
    {
        $keywordList = implode(', ', array_map(
            static fn (array $r): string => (string) ($r['keyword'] ?? ''),
            array_slice($keywordRows, 0, 40),
        ));
        $text = mb_substr(implode("\n---\n", $pageText), 0, 4500);

        $result = $this->llm->completeJson([
            [
                'role' => 'system',
                'content' => 'You are an SEO analyst. Reply with strict JSON only: '
                    .'{"genuine": bool, "queries": ["..."]}. "genuine" is true when the keyword list '
                    .'reflects the website\'s actual topic/products, false when it is dominated by '
                    .'generic UI boilerplate (signup, login, create account, password, etc.) or is empty. '
                    .'"queries" are 5-10 realistic Google searches a potential customer of this website '
                    .'would type (based on the page content), most important first. Use the page '
                    .'content\'s language for the queries.',
            ],
            [
                'role' => 'user',
                'content' => "Website: {$domain}\n\nKeyword list from a keyword-planner lookup:\n"
                    .($keywordList !== '' ? $keywordList : '(empty)')
                    ."\n\nWebsite page content:\n".($text !== '' ? $text : '(unavailable)'),
            ],
        ], [
            'json_object' => true,
            'temperature' => 0.2,
            'max_tokens' => max(256, (int) config('services.report.enrichment.llm_max_tokens', 1200)),
            'timeout' => 60,
            '__source' => 'report_enrichment',
            '__website_id' => $this->ownerWebsite($domain)?->id,
            '__user_id' => $this->ownerWebsite($domain)?->user_id,
        ]);

        // No LLM available → treat whatever keywords exist as genuine so we
        // still ship value instead of stalling.
        if ($result === null) {
            return ['genuine' => $keywordRows !== [], 'queries' => []];
        }

        $genuine = (bool) ($result['genuine'] ?? true);
        $queries = array_values(array_filter(array_map(
            static fn ($q): string => is_string($q) ? trim($q) : '',
            is_array($result['queries'] ?? null) ? $result['queries'] : [],
        ), static fn (string $q): bool => $q !== ''));

        // Judged boilerplate but gave us nothing to search — fall back to
        // shipping the keywords we have rather than an empty report.
        if ($genuine === false && $queries === []) {
            return ['genuine' => $keywordRows !== [], 'queries' => []];
        }

        return ['genuine' => $genuine, 'queries' => $queries];
    }

    /**
     * SERP the generated queries (capped, cache-shared), tally recurring
     * domains exactly like CompetitorDiscoveryService::run(), enrich with
     * Open PageRank, and pick the best-scoring competitor.
     *
     * @param  list<string>  $queries
     * @return array{rows: list<array<string, mixed>>, best: ?string}
     */
    private function tallyCompetitors(array $queries, string $domain, ?string $billedUserId = null): array
    {
        $cap = max(1, (int) config('services.report.enrichment.serp_query_cap', 8));
        $queries = array_slice($queries, 0, $cap);
        if ($queries === []) {
            return ['rows' => [], 'best' => null];
        }

        $owner = $this->ownerWebsite($domain);
        $tally = [];
        $sampled = 0;

        foreach ($queries as $query) {
            try {
                $serp = $this->serp->organic($query, 'us', $owner?->id, $owner?->user_id ?? $billedUserId, 'report_enrichment');
            } catch (Throwable) {
                break; // quota exceeded / hard failure — use what we have
            }
            $organic = is_array($serp['organic'] ?? null) ? $serp['organic'] : [];
            if ($organic === []) {
                continue;
            }
            $sampled++;

            $seenThisSerp = [];
            foreach ($organic as $idx => $result) {
                if (! is_array($result)) {
                    continue;
                }
                $link = (string) ($result['link'] ?? ($result['url'] ?? ''));
                $host = strtolower((string) parse_url($link, PHP_URL_HOST));
                $host = preg_replace('/^www\./', '', $host) ?? $host;
                if ($host === '' || $host === $domain || str_ends_with($host, '.'.$domain) || GiantDomains::isGiant($host)) {
                    continue;
                }
                if (isset($seenThisSerp[$host])) {
                    continue;
                }
                $seenThisSerp[$host] = true;

                $pos = (int) ($result['position'] ?? ($idx + 1));
                $tally[$host] ??= ['appearances' => 0, 'positions' => []];
                $tally[$host]['appearances']++;
                $tally[$host]['positions'][] = $pos;
            }
        }

        if ($tally === []) {
            return ['rows' => [], 'best' => null];
        }

        $opr = $this->opr->metricsFor(array_slice(array_keys($tally), 0, 50));

        $rows = [];
        foreach ($tally as $host => $data) {
            $avgPosition = $data['positions'] !== []
                ? round(array_sum($data['positions']) / count($data['positions']), 1)
                : null;
            $metrics = $opr[$host] ?? ($opr[OpenPageRankClient::registrable($host)] ?? null);
            $rows[] = [
                'domain' => $host,
                // Same row shape competitorRows() emits (KeywordGapAnalysis
                // reads these keys off the shared snapshot payload).
                'shared_keywords' => $data['appearances'],
                'avg_position' => $avgPosition,
                'popularity_rank' => is_array($metrics) ? ($metrics['rank'] ?? null) : null,
                'opr_score' => is_array($metrics) ? ($metrics['score'] ?? null) : null,
                '_score' => $this->discovery->score($data['appearances'], max(1, $sampled), $avgPosition),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['_score'] <=> $a['_score']));
        $rows = array_slice($rows, 0, self::COMPETITOR_ROW_CAP);

        $best = $rows[0]['domain'] ?? null;
        $rows = array_map(static function (array $r): array {
            unset($r['_score']);

            return $r;
        }, $rows);

        return ['rows' => $rows, 'best' => $best];
    }

    /**
     * Monthly-cache-first keyword ideas lookup for a domain (site scope).
     *
     * @return array{rows: ?list<array<string, mixed>>, id: ?string, cache_key: string}
     */
    private function ideasRequestFor(string $domain, ?string $billedUserId = null): array
    {
        $opts = ['url' => 'https://'.$domain, 'scope' => 'site'];
        [$mode, $payload] = $this->keywords->buildIdeasPayload($opts, 'us');
        $cacheKey = KeywordIdeasMonthlyCache::key($mode, $payload);

        $cached = KeywordIdeasMonthlyCache::get($cacheKey);
        if ($cached !== null && $cached !== []) {
            return ['rows' => $cached, 'id' => null, 'cache_key' => $cacheKey];
        }

        $owner = $this->ownerWebsite($domain);
        // Platform-initiated enrichment (no billed user) never meters; a
        // user-clicked lookup (billedUserId set) spends that user's plan quota.
        $request = $this->keywords->dispatchIdeas($opts, $owner?->user_id ?? $billedUserId, $owner?->id, null, 'us', meter: $billedUserId !== null);

        if ($request->status === KeywordApiRequest::STATUS_FAILED) {
            return ['rows' => null, 'id' => null, 'cache_key' => $cacheKey];
        }

        return ['rows' => null, 'id' => $request->request_id, 'cache_key' => $cacheKey];
    }

    /**
     * Completed rows for a tracked request (or its monthly cache), null while
     * still pending/failed.
     *
     * @param  array<string, mixed>  $ref  {id: ?string, cache_key: ?string}
     * @return list<array<string, mixed>>|null
     */
    private function requestRows(array $ref): ?array
    {
        $cacheKey = (string) ($ref['cache_key'] ?? '');
        if ($cacheKey !== '') {
            $cached = KeywordIdeasMonthlyCache::get($cacheKey);
            if ($cached !== null && $cached !== []) {
                return $cached;
            }
        }

        $id = (string) ($ref['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $request = KeywordApiRequest::query()->where('request_id', $id)->first();
        if ($request === null || $request->status !== KeywordApiRequest::STATUS_COMPLETED) {
            return null;
        }

        $rows = $request->result['results'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        if ($cacheKey !== '') {
            // Warm the shared monthly cache for every other consumer.
            KeywordIdeasMonthlyCache::put($cacheKey, $rows);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function requestFailed(array $ref): bool
    {
        $id = (string) ($ref['id'] ?? '');
        if ($id === '') {
            return false;
        }

        $request = KeywordApiRequest::query()->where('request_id', $id)->first();

        return $request === null || $request->status === KeywordApiRequest::STATUS_FAILED;
    }

    /**
     * Normalize raw keyword-planner rows to the report's display shape.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeKeywordRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $keyword = trim((string) ($r['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            $out[] = [
                'keyword' => $keyword,
                'volume' => isset($r['avgMonthlySearches']) && is_numeric($r['avgMonthlySearches'])
                    ? (int) $r['avgMonthlySearches']
                    : (isset($r['volume']) && is_numeric($r['volume']) ? (int) $r['volume'] : null),
                'cpc' => isset($r['highTopOfPageBid']) && is_numeric($r['highTopOfPageBid'])
                    ? round((float) $r['highTopOfPageBid'], 2)
                    : (isset($r['cpc']) && is_numeric($r['cpc']) ? round((float) $r['cpc'], 2) : null),
                'competition' => is_string($r['competition'] ?? null) ? ucfirst(strtolower($r['competition'])) : null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => (($b['volume'] ?? 0) <=> ($a['volume'] ?? 0)));

        return array_slice($out, 0, $this->keywordRowCap());
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function saveState(WebsiteReportSnapshot $snapshot, array $state): void
    {
        $snapshot->forceFill(['enrichment_state' => $state])->save();
    }

    private function ownerWebsite(string $domain): ?Website
    {
        static $cache = [];

        if (! array_key_exists($domain, $cache)) {
            $cache[$domain] = Website::query()->where('normalized_domain', $domain)->first();
        }

        return $cache[$domain];
    }

    private function pollSeconds(): int
    {
        return max(10, (int) config('services.report.enrichment.poll_seconds', 30));
    }

    private function ideasTimeoutMinutes(): int
    {
        return max(1, (int) config('services.report.enrichment.ideas_timeout_minutes', 12));
    }
}
