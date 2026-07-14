<?php

namespace App\Services\Competitive;

use App\Exceptions\QuotaExceededException;
use App\Jobs\RunKeywordGapVerification;
use App\Models\CompetitorBacklink;
use App\Models\KeywordApiRequest;
use App\Models\KeywordGapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\KeywordMetric;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordFinder\KeywordIdeasMonthlyCache;
use App\Services\KeywordMetricsService;
use App\Support\KeywordFinderLocations;
use App\Support\KeywordProviderConfig;
use Illuminate\Support\Facades\DB;

/**
 * Keyword Gap Analysis: discover the keyword footprint of our own site and 1–N
 * competitor URLs, then diff them into Missing / Weak / Strength buckets (or
 * Missing / Shared when the site has no GSC positions).
 *
 * Discovery is asynchronous (one {@see KeywordApiRequest} per URL), so a run
 * sits in `collecting` while the poller waits for every request to finish, then
 * aggregates exactly once. The webhook has already ingested each returned
 * keyword's volume into keyword_metrics, so enrichment is a free cache read.
 */
class KeywordGapService
{
    public function __construct(
        private KeywordFinderPool $pool,
        private KeywordMetricsService $metrics,
        private OpportunityScoreService $opportunity,
        private SerpCache $serp,
    ) {
    }

    /**
     * Kick off a run: dispatch website-mode discovery for our site + each
     * competitor, then return the `collecting` header. Throws nothing — surfaces
     * problems via the returned analysis `status`/`error`.
     *
     * @param  list<string>  $competitorUrls
     */
    public function start(Website $website, array $competitorUrls, string $country, ?string $userId = null): KeywordGapAnalysis
    {
        $country = $this->normalizeCountry($country);
        $ourUrl = (string) $website->domain;
        $competitors = $this->cleanUrls($competitorUrls);

        $analysis = KeywordGapAnalysis::create([
            'website_id' => $website->id,
            'user_id' => $userId,
            'our_url' => $ourUrl,
            'competitor_urls' => $competitors,
            'country' => $country,
            'status' => KeywordGapAnalysis::STATUS_COLLECTING,
            'request_ids' => [],
            'total_requests' => 0,
            'completed_requests' => 0,
        ]);

        if (! KeywordProviderConfig::usingKeywordFinder()) {
            $analysis->forceFill([
                'status' => KeywordGapAnalysis::STATUS_FAILED,
                'error' => 'Keyword discovery requires the self-hosted Keyword Planner provider, which is not currently enabled.',
            ])->save();

            return $analysis;
        }

        $requests = [];

        // Shared cross-user discovery cache (same store the Ideas tab and the
        // WP plugin read): a domain ANY platform user already discovered this
        // month is served from cache instead of re-querying a keyword server.
        // Cached entries carry `cache_key` instead of a request `id` and are
        // instantly "finished" for the poller.
        $cachedEntry = function (string $url, string $role) use ($country): ?array {
            [$mode, $payload] = $this->pool->buildIdeasPayload(['url' => $url, 'scope' => 'site'], $country);
            $key = KeywordIdeasMonthlyCache::key($mode, $payload);
            if (KeywordIdeasMonthlyCache::get($key) === null) {
                return null;
            }

            return ['id' => null, 'cache_key' => $key, 'role' => $role, 'url' => $url, 'domain' => CompetitorBacklink::extractDomain($url)];
        };

        // Our own site first. website-mode works with NO Google connection.
        if (($entry = $cachedEntry($ourUrl, 'ours')) !== null) {
            $requests[] = $entry;
        } else {
            $ourReq = $this->pool->dispatchIdeas(
                ['url' => $ourUrl, 'scope' => 'site'],
                userId: $userId,
                websiteId: $website->id,
                countryKey: $country,
            );
            // If our-site discovery can't even dispatch AND we have no GSC to fall
            // back on, the run is unusable.
            if ($ourReq->status === KeywordApiRequest::STATUS_FAILED && ! $website->hasGsc()) {
                $analysis->forceFill([
                    'status' => KeywordGapAnalysis::STATUS_FAILED,
                    'error' => $ourReq->error ?: 'Could not analyse your website. Please try again shortly.',
                ])->save();

                return $analysis;
            }
            $requests[] = ['id' => $ourReq->request_id, 'role' => 'ours', 'url' => $ourUrl, 'domain' => CompetitorBacklink::extractDomain($ourUrl)];
        }

        foreach ($competitors as $url) {
            if (($entry = $cachedEntry($url, 'competitor')) !== null) {
                $requests[] = $entry;

                continue;
            }
            $req = $this->pool->dispatchIdeas(
                ['url' => $url, 'scope' => 'site'],
                userId: $userId,
                websiteId: $website->id,
                countryKey: $country,
            );
            // A competitor that fails to dispatch simply contributes nothing.
            $requests[] = ['id' => $req->request_id, 'role' => 'competitor', 'url' => $url, 'domain' => CompetitorBacklink::extractDomain($url)];
        }

        $analysis->forceFill([
            'request_ids' => $requests,
            'total_requests' => count($requests),
        ])->save();

        // A run where every request failed instantly (no servers) should not
        // hang in `collecting` — aggregate immediately.
        $this->maybeAggregate($analysis->fresh());

        return $analysis->fresh();
    }

    /**
     * Poller entrypoint. Counts finished requests; when all are done (or the run
     * has timed out) it claims aggregation atomically and runs it exactly once.
     */
    public function maybeAggregate(KeywordGapAnalysis $analysis): void
    {
        if ($analysis->status !== KeywordGapAnalysis::STATUS_COLLECTING) {
            return;
        }

        $reqs = is_array($analysis->request_ids) ? $analysis->request_ids : [];
        $ids = array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $reqs)));
        // Cache-served entries (no request id) are finished by definition.
        $cachedCount = count(array_filter($reqs, fn ($r) => ($r['id'] ?? null) === null && ($r['cache_key'] ?? null) !== null));

        $statuses = KeywordApiRequest::query()
            ->whereIn('request_id', $ids)
            ->pluck('status', 'request_id');

        $finished = $cachedCount + $statuses->filter(fn ($s) => in_array($s, [KeywordApiRequest::STATUS_COMPLETED, KeywordApiRequest::STATUS_FAILED], true))->count();
        if ($finished !== $analysis->completed_requests) {
            $analysis->forceFill(['completed_requests' => $finished])->save();
        }

        $allDone = $finished >= $analysis->total_requests;
        $timedOut = $analysis->created_at !== null
            && $analysis->created_at->lt(now()->subMinutes($this->timeoutMinutes()));

        if (! $allDone && ! $timedOut) {
            return;
        }

        // On timeout, only proceed if we actually have usable "our" data —
        // otherwise everything would falsely look "missing".
        if (! $allDone) {
            $ourEntry = collect($reqs)->firstWhere('role', 'ours') ?? [];
            $ourId = $ourEntry['id'] ?? null;
            $ourReady = ($ourEntry['cache_key'] ?? null) !== null
                || ($ourId !== null && ($statuses[$ourId] ?? null) === KeywordApiRequest::STATUS_COMPLETED);
            if (! $ourReady && ! $analysis->website?->hasGsc()) {
                $analysis->forceFill([
                    'status' => KeywordGapAnalysis::STATUS_FAILED,
                    'error' => 'Analysis timed out before your website data was ready. Please try again.',
                    'completed_at' => now(),
                ])->save();

                return;
            }
        }

        // Atomic claim — only the poll that flips collecting → completed runs
        // the aggregation; concurrent polls bail here.
        $claimed = KeywordGapAnalysis::query()
            ->where('id', $analysis->id)
            ->where('status', KeywordGapAnalysis::STATUS_COLLECTING)
            ->update([
                'status' => KeywordGapAnalysis::STATUS_COMPLETED,
                'completed_at' => now(),
                'expires_at' => now()->addDays($this->cacheDays()),
            ]);

        if ($claimed === 0) {
            return;
        }

        $this->aggregate($analysis->fresh());
    }

    /**
     * Diff the finished discovery results + GSC into bucketed, enriched, scored
     * rows. Idempotent: clears any prior rows for this analysis first.
     */
    public function aggregate(KeywordGapAnalysis $analysis): void
    {
        $website = $analysis->website;
        $hasGsc = (bool) $website?->hasGsc();
        $country = $analysis->country;

        $reqs = is_array($analysis->request_ids) ? $analysis->request_ids : [];
        $byId = KeywordApiRequest::query()
            ->whereIn('request_id', array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $reqs))))
            ->get()
            ->keyBy('request_id');

        // Our footprint (website-mode discovery) keyed by lowercased keyword.
        $ourKeywords = [];
        // Competitor footprint: lowercased keyword => [domains].
        $competitorKeywords = [];

        foreach ($reqs as $r) {
            // Cache-served entry → keywords straight from the shared monthly
            // discovery cache (no keyword-server round-trip happened at all).
            if (($r['cache_key'] ?? null) !== null) {
                $keywords = $this->keywordsFromRows(KeywordIdeasMonthlyCache::get((string) $r['cache_key']) ?? []);
            } else {
                $req = $byId->get($r['id'] ?? '');
                if (! $req instanceof KeywordApiRequest || $req->status !== KeywordApiRequest::STATUS_COMPLETED) {
                    continue;
                }
                $keywords = $this->keywordsFromResult($req);
                // Warm the shared cache for everyone: the NEXT gap run (any
                // user), Ideas-tab lookup, or plugin call for this domain is
                // now a free cache hit for the rest of the month.
                $rows = $req->result['results'] ?? null;
                if (is_array($rows) && $rows !== []) {
                    KeywordIdeasMonthlyCache::put(
                        KeywordIdeasMonthlyCache::key((string) $req->mode, is_array($req->payload) ? $req->payload : []),
                        array_values(array_filter($rows, 'is_array')),
                    );
                }
            }

            foreach ($keywords as $kw) {
                $lower = mb_strtolower($kw);
                if (($r['role'] ?? '') === 'ours') {
                    $ourKeywords[$lower] ??= $kw;
                } else {
                    $competitorKeywords[$lower]['keyword'] ??= $kw;
                    $competitorKeywords[$lower]['domains'][] = (string) ($r['domain'] ?? '');
                }
            }
        }

        // GSC positions (and keywords) for our site, if connected.
        $ourPositions = $hasGsc ? $this->gscPositions($analysis->website_id) : [];
        foreach ($ourPositions as $lower => $pos) {
            $ourKeywords[$lower] ??= $lower;
        }

        // Bucket each keyword.
        $rows = [];
        foreach ($competitorKeywords as $lower => $data) {
            $weHaveIt = isset($ourKeywords[$lower]);
            $ourPos = $ourPositions[$lower] ?? null;
            $bucket = $this->bucketFor($weHaveIt, $ourPos, $hasGsc);
            $rows[$lower] = [
                'keyword' => $data['keyword'],
                'bucket' => $bucket,
                'our_position' => $ourPos,
                'competitor_domains' => array_values(array_unique(array_filter($data['domains'] ?? []))),
            ];
        }
        // Our strengths: keywords we have that competitors don't (only meaningful
        // with GSC positions — otherwise we can't claim a "strength").
        if ($hasGsc) {
            foreach ($ourKeywords as $lower => $kw) {
                if (isset($rows[$lower])) {
                    continue;
                }
                $ourPos = $ourPositions[$lower] ?? null;
                if ($ourPos !== null && $ourPos <= 10) {
                    $rows[$lower] = [
                        'keyword' => is_string($kw) ? $kw : $lower,
                        'bucket' => KeywordGapAnalysis::BUCKET_STRENGTH,
                        'our_position' => $ourPos,
                        'competitor_domains' => [],
                    ];
                }
            }
        }

        // Enrich with cached metrics (already warmed by the webhook ingest).
        $allKeywords = array_map(fn ($r) => $r['keyword'], $rows);
        $metrics = $this->metrics->metricsForMany(array_values($allKeywords), $country);

        $built = [];
        foreach ($rows as $lower => $row) {
            $metric = $metrics[KeywordMetric::hashKeyword($row['keyword'])] ?? null;
            $volume = $metric?->search_volume;
            $score = $this->opportunity->lightScore($volume, $row['our_position']);
            $built[] = [
                'keyword' => $row['keyword'],
                'keyword_hash' => KeywordMetric::hashKeyword($row['keyword']),
                'bucket' => $row['bucket'],
                'search_volume' => $volume,
                'competition' => $metric?->competition,
                'cpc' => $metric?->cpc,
                'our_position' => $row['our_position'],
                'competitor_domains' => $row['competitor_domains'],
                'opportunity_score' => $score['score'],
                'score_components' => $score['components'],
                '_sort_volume' => $volume ?? -1,
            ];
        }

        // Cap to top-by-volume to bound table growth.
        usort($built, fn ($a, $b) => $b['_sort_volume'] <=> $a['_sort_volume']);
        $built = array_slice($built, 0, $this->rowCap());

        // Persist (idempotent — clear prior rows for this analysis first).
        KeywordGapRow::query()->where('keyword_gap_analysis_id', $analysis->id)->delete();
        $summary = [
            KeywordGapAnalysis::BUCKET_MISSING => 0,
            KeywordGapAnalysis::BUCKET_WEAK => 0,
            KeywordGapAnalysis::BUCKET_STRENGTH => 0,
            KeywordGapAnalysis::BUCKET_SHARED => 0,
        ];
        $now = now();
        $insert = [];
        foreach ($built as $b) {
            $summary[$b['bucket']] = ($summary[$b['bucket']] ?? 0) + 1;
            unset($b['_sort_volume']);
            $insert[] = array_merge($b, [
                'keyword_gap_analysis_id' => $analysis->id,
                'competitor_domains' => json_encode($b['competitor_domains']),
                'score_components' => json_encode($b['score_components']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            KeywordGapRow::query()->insert(ulid_rows($chunk));
        }

        $analysis->forceFill(['summary' => $summary])->save();

        // Background-fill any keywords we couldn't enrich from cache.
        $missingMetrics = array_values(array_filter(
            $allKeywords,
            fn ($kw) => ! isset($metrics[KeywordMetric::hashKeyword($kw)])
        ));
        if ($missingMetrics !== []) {
            $this->metrics->metricsOrQueue($missingMetrics, $country, $analysis->website_id, $analysis->user_id);
        }
    }

    /**
     * Re-bucket a completed analysis in place now that GSC positions exist —
     * the cheap half of reprocessing: NO new discovery calls, just merge in
     * positions and re-derive buckets + opportunity proximity from stored rows.
     *
     * SHARED/WEAK/STRENGTH rows already implied we had the keyword; MISSING rows
     * are promoted only if GSC now shows us ranking for them. Idempotent.
     */
    public function reprocessWithGsc(KeywordGapAnalysis $analysis): void
    {
        if ($analysis->status !== KeywordGapAnalysis::STATUS_COMPLETED) {
            return;
        }
        $positions = $this->gscPositions($analysis->website_id);

        $summary = [
            KeywordGapAnalysis::BUCKET_MISSING => 0,
            KeywordGapAnalysis::BUCKET_WEAK => 0,
            KeywordGapAnalysis::BUCKET_STRENGTH => 0,
            KeywordGapAnalysis::BUCKET_SHARED => 0,
        ];

        KeywordGapRow::query()
            ->where('keyword_gap_analysis_id', $analysis->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($positions, &$summary): void {
                foreach ($rows as $row) {
                    $lower = mb_strtolower($row->keyword);
                    $wasInOurSet = $row->bucket !== KeywordGapAnalysis::BUCKET_MISSING;
                    $ourPos = $positions[$lower] ?? $row->our_position;
                    $weHaveIt = $wasInOurSet || isset($positions[$lower]);
                    $bucket = $this->bucketFor($weHaveIt, $ourPos, true);
                    $score = $this->opportunity->lightScore($row->search_volume, $ourPos);

                    $row->forceFill([
                        'bucket' => $bucket,
                        'our_position' => $ourPos,
                        'opportunity_score' => $score['score'],
                        'score_components' => $score['components'],
                    ])->save();

                    $summary[$bucket] = ($summary[$bucket] ?? 0) + 1;
                }
            });

        $analysis->forceFill([
            'summary' => $summary,
            'reprocessed_at' => now(),
        ])->save();
    }

    /**
     * Begin live-SERP verification of one bucket (defaults to the config
     * bucket set — Missing). Returns the number of rows queued (0 = nothing
     * to verify / already running / not completed).
     */
    public function startVerification(KeywordGapAnalysis $analysis, ?string $bucket = null): int
    {
        if ($analysis->status !== KeywordGapAnalysis::STATUS_COMPLETED) {
            return 0;
        }
        if ($analysis->verify_status === KeywordGapAnalysis::VERIFY_STATUS_VERIFYING) {
            return 0;
        }

        $candidates = KeywordGapRow::query()
            ->where('keyword_gap_analysis_id', $analysis->id)
            ->whereIn('bucket', $this->bucketsFor($bucket))
            ->whereNull('verified_at')
            ->count();
        $target = min($candidates, $this->verifyMax());
        if ($target === 0) {
            return 0;
        }

        $analysis->forceFill([
            'verify_status' => KeywordGapAnalysis::VERIFY_STATUS_VERIFYING,
            'verify_total' => $target,
            'verify_done' => 0,
            'verify_error' => null,
        ])->save();

        RunKeywordGapVerification::dispatch($analysis->id, $bucket);

        return $target;
    }

    /**
     * Verify candidate rows against the live SERP (one cached call per keyword):
     * capture the competitor's real position + ours, re-bucket from reality, and
     * recompute the opportunity score from the SAME response. Quota-safe: a
     * plan-cap hit stops the batch, persists what's done, and records a message.
     */
    public function verify(string $analysisId, ?string $bucket = null): void
    {
        $analysis = KeywordGapAnalysis::find($analysisId);
        if ($analysis === null || $analysis->verify_status !== KeywordGapAnalysis::VERIFY_STATUS_VERIFYING) {
            return;
        }

        $website = $analysis->website;
        $hasGsc = (bool) $website?->hasGsc();
        $gl = KeywordFinderLocations::serperGl($analysis->country);
        $ourDomain = CompetitorBacklink::extractDomain($analysis->our_url);
        $competitorDomains = is_array($analysis->competitor_urls) ? $analysis->competitor_urls : [];

        $rows = KeywordGapRow::query()
            ->where('keyword_gap_analysis_id', $analysis->id)
            ->whereIn('bucket', $this->bucketsFor($bucket))
            ->whereNull('verified_at')
            ->orderByDesc('search_volume')
            ->limit($this->verifyMax())
            ->get();

        foreach ($rows as $row) {
            try {
                $serp = $this->serp->organic($row->keyword, $gl, $website?->id, $analysis->user_id, 'gap_verify');
            } catch (QuotaExceededException $e) {
                $analysis->forceFill(['verify_error' => $e->userMessage])->save();
                break;
            }

            [$compPos, $ourPos, $compDomain] = $this->positionsFromSerp($serp, $competitorDomains, $ourDomain);

            // Real SERP position wins; fall back to any existing (GSC) position.
            $effectiveOurPos = $ourPos !== null ? (float) $ourPos : $row->our_position;
            $weHaveIt = $effectiveOurPos !== null;
            // We can split weak/strength whenever we have a position — from GSC
            // OR from this SERP — even on a no-GSC site.
            $havePosition = $hasGsc || $ourPos !== null;
            $bucket = $this->bucketFor($weHaveIt, $effectiveOurPos, $havePosition);

            $score = is_array($serp)
                ? $this->opportunity->scoreFromSerp($serp, $row->search_volume, $effectiveOurPos, $website?->id, $analysis->user_id)
                : $this->opportunity->lightScore($row->search_volume, $effectiveOurPos);

            $row->forceFill([
                'bucket' => $bucket,
                'our_position' => $effectiveOurPos,
                'competitor_position' => $compPos,
                'competitor_domain' => $compDomain,
                'opportunity_score' => $score['score'],
                'score_components' => $score['components'],
                'verified_at' => now(),
            ])->save();

            $analysis->increment('verify_done');
        }

        // Recompute summary from the real buckets after verification.
        $counts = KeywordGapRow::query()
            ->where('keyword_gap_analysis_id', $analysis->id)
            ->selectRaw('bucket, COUNT(*) as c')
            ->groupBy('bucket')
            ->pluck('c', 'bucket');
        $analysis->forceFill([
            'summary' => [
                KeywordGapAnalysis::BUCKET_MISSING => (int) ($counts[KeywordGapAnalysis::BUCKET_MISSING] ?? 0),
                KeywordGapAnalysis::BUCKET_WEAK => (int) ($counts[KeywordGapAnalysis::BUCKET_WEAK] ?? 0),
                KeywordGapAnalysis::BUCKET_STRENGTH => (int) ($counts[KeywordGapAnalysis::BUCKET_STRENGTH] ?? 0),
                KeywordGapAnalysis::BUCKET_SHARED => (int) ($counts[KeywordGapAnalysis::BUCKET_SHARED] ?? 0),
            ],
            'verify_status' => KeywordGapAnalysis::VERIFY_STATUS_COMPLETED,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * Best competitor position (+ which competitor it was) and best our
     * position from a cached SERP payload.
     *
     * @param  array<string, mixed>|null  $serp
     * @param  list<string>  $competitorDomains
     * @return array{0: ?int, 1: ?int, 2: ?string}  [competitorPosition, ourPosition, competitorDomain]
     */
    private function positionsFromSerp(?array $serp, array $competitorDomains, string $ourDomain): array
    {
        $compPos = null;
        $compDomain = null;
        $ourPos = null;
        if (! is_array($serp)) {
            return [null, null, null];
        }
        foreach ($serp['organic'] ?? [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $d = (string) ($r['domain'] ?? CompetitorBacklink::extractDomain((string) ($r['link'] ?? '')));
            if ($d === '') {
                continue;
            }
            $pos = (int) ($r['position'] ?? 0);
            if ($pos <= 0) {
                continue;
            }
            if (in_array($d, $competitorDomains, true) && ($compPos === null || $pos < $compPos)) {
                $compPos = $pos;
                $compDomain = $d;
            }
            if ($d === $ourDomain && ($ourPos === null || $pos < $ourPos)) {
                $ourPos = $pos;
            }
        }

        return [$compPos, $ourPos, $compDomain];
    }

    /** @return list<string> */
    private function verifyBuckets(): array
    {
        $buckets = [KeywordGapAnalysis::BUCKET_MISSING];
        if ((bool) config('services.competitive.gap_verify_include_shared', false)) {
            $buckets[] = KeywordGapAnalysis::BUCKET_SHARED;
            $buckets[] = KeywordGapAnalysis::BUCKET_WEAK;
        }

        return $buckets;
    }

    /**
     * Buckets a verification pass targets: the explicitly requested bucket
     * (per-tab "Verify" button, 2026-07-14 — verification is available on
     * every position-bearing tab, not just Missing), else the config default.
     *
     * @return list<string>
     */
    private function bucketsFor(?string $bucket): array
    {
        $valid = [
            KeywordGapAnalysis::BUCKET_MISSING,
            KeywordGapAnalysis::BUCKET_WEAK,
            KeywordGapAnalysis::BUCKET_STRENGTH,
            KeywordGapAnalysis::BUCKET_SHARED,
        ];

        return in_array($bucket, $valid, true) ? [$bucket] : $this->verifyBuckets();
    }

    private function verifyMax(): int
    {
        return max(1, (int) config('services.competitive.gap_verify_max', 25));
    }

    /**
     * Most recent non-expired completed analysis matching this competitor set,
     * so a repeat request is served from cache instead of re-dispatching.
     *
     * @param  list<string>  $competitorUrls
     */
    public function latestFresh(string $websiteId, array $competitorUrls, string $country): ?KeywordGapAnalysis
    {
        $want = $this->cleanUrls($competitorUrls);
        sort($want);
        $country = $this->normalizeCountry($country);

        return KeywordGapAnalysis::query()
            ->where('website_id', $websiteId)
            ->where('country', $country)
            ->where('status', KeywordGapAnalysis::STATUS_COMPLETED)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get()
            ->first(function (KeywordGapAnalysis $a) use ($want): bool {
                $have = is_array($a->competitor_urls) ? $a->competitor_urls : [];
                sort($have);

                return $have === $want;
            });
    }

    private function bucketFor(bool $weHaveIt, ?float $ourPos, bool $hasGsc): string
    {
        if (! $weHaveIt) {
            return KeywordGapAnalysis::BUCKET_MISSING;
        }
        if (! $hasGsc) {
            return KeywordGapAnalysis::BUCKET_SHARED;
        }

        // We have it AND we have positions: weak if we rank poorly, else strength.
        return ($ourPos === null || $ourPos > 10)
            ? KeywordGapAnalysis::BUCKET_WEAK
            : KeywordGapAnalysis::BUCKET_STRENGTH;
    }

    /**
     * @return list<string> distinct keyword strings from a finished request
     */
    private function keywordsFromResult(KeywordApiRequest $req): array
    {
        $results = $req->result['results'] ?? [];

        return $this->keywordsFromRows(is_array($results) ? $results : []);
    }

    /**
     * @param  array<int, mixed>  $rows  result rows (each with a `keyword` key)
     * @return list<string> distinct keyword strings
     */
    private function keywordsFromRows(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kw = trim((string) ($row['keyword'] ?? ''));
            if ($kw === '') {
                continue;
            }
            $key = mb_strtolower($kw);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $kw;
        }

        return $out;
    }

    /**
     * Average GSC position per query over the last 90 days: lowercased query => position.
     *
     * @return array<string, float>
     */
    private function gscPositions(string $websiteId): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereDate('date', '>=', now()->subDays(90)->toDateString())
            ->where('query', '!=', '')
            ->groupBy('query')
            ->select('query', DB::raw('AVG(position) as pos'))
            ->get()
            ->mapWithKeys(fn ($r) => [mb_strtolower(trim((string) $r->query)) => round((float) $r->pos, 2)])
            ->all();
    }

    /**
     * @param  list<string>  $urls
     * @return list<string> normalized, deduped competitor domains, capped
     */
    private function cleanUrls(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $u) {
            $domain = CompetitorBacklink::extractDomain((string) $u);
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $out[] = $domain;
        }

        return array_slice($out, 0, $this->maxCompetitors());
    }

    private function normalizeCountry(string $country): string
    {
        $c = strtolower(trim($country));

        return $c === '' ? 'global' : $c;
    }

    private function maxCompetitors(): int
    {
        return max(1, (int) config('services.competitive.gap_max_competitors', 3));
    }

    private function rowCap(): int
    {
        return max(1, (int) config('services.competitive.gap_row_cap', 1000));
    }

    private function timeoutMinutes(): int
    {
        return max(1, (int) config('services.competitive.gap_collect_timeout_minutes', 5));
    }

    private function cacheDays(): int
    {
        return max(1, (int) config('services.keyword_finder.fresh_days', 30));
    }
}
