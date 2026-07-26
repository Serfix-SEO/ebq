<?php

namespace App\Services\Competitive;

use App\Jobs\RunCompetitorDiscovery;
use App\Models\CompetitorBacklink;
use App\Models\CompetitorDiscoveryRun;
use App\Models\DiscoveredCompetitor;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\OpenPageRankClient;
use App\Support\KeywordFinderLocations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auto-discovers a website's organic competitors by sampling the live SERP for
 * its top keywords and tallying which domains recur. DB-first + cadence-gated:
 * a fresh run within `discovery_refresh_days` is never re-billed.
 *
 * Selection prefers real GSC top queries; without GSC it accepts manual seeds.
 * The fan-out is capped (`discovery_max_keywords`) and every SERP call is
 * attributed to the website's billing scope via SerperSearchClient.
 */
class CompetitorDiscoveryService
{
    public function __construct(
        private SerpCache $serp,
        private OpenPageRankClient $opr,
        private CompetitorEnricher $enricher,
    ) {
    }

    /**
     * Ranked competitors for a website (pure DB read, best first).
     *
     * @return Collection<int, DiscoveredCompetitor>
     */
    public function resultsFor(string $websiteId): Collection
    {
        return DiscoveredCompetitor::query()
            ->forWebsite($websiteId)
            ->orderByDesc('score')
            ->get();
    }

    public function latestRun(string $websiteId): ?CompetitorDiscoveryRun
    {
        return CompetitorDiscoveryRun::query()
            ->where('website_id', $websiteId)
            ->latest('id')
            ->first();
    }

    /**
     * True when there is no completed run inside the refresh window — i.e. it's
     * worth (re-)running discovery.
     */
    public function isStale(string $websiteId): bool
    {
        $days = max(1, (int) config('services.competitive.discovery_refresh_days', 14));
        $fresh = CompetitorDiscoveryRun::query()
            ->where('website_id', $websiteId)
            ->where('status', CompetitorDiscoveryRun::STATUS_COMPLETED)
            ->where('completed_at', '>', now()->subDays($days))
            ->exists();

        return ! $fresh;
    }

    /**
     * Dispatch a run only if discovery is stale (or forced). No-ops — returning
     * null — when fresh, when a run is already in flight, or when there are no
     * keywords to sample (no GSC and no manual seeds).
     *
     * @param  list<string>  $manualSeeds
     */
    public function queueRunIfStale(Website $website, ?string $userId = null, array $manualSeeds = [], bool $force = false): ?CompetitorDiscoveryRun
    {
        if (! $force && ! $this->isStale($website->id)) {
            return null;
        }

        // Don't stack runs — one in-flight run per website at a time.
        $inFlight = CompetitorDiscoveryRun::query()
            ->where('website_id', $website->id)
            ->whereIn('status', [CompetitorDiscoveryRun::STATUS_QUEUED, CompetitorDiscoveryRun::STATUS_RUNNING])
            ->exists();
        if ($inFlight) {
            return null;
        }

        return $this->startRun($website, $userId, $manualSeeds);
    }

    /**
     * Select keywords, create the run row, and dispatch the fan-out job.
     * Returns null when no keywords are available to sample.
     *
     * @param  list<string>  $manualSeeds
     */
    public function startRun(Website $website, ?string $userId = null, array $manualSeeds = []): ?CompetitorDiscoveryRun
    {
        $cap = $this->keywordCap();

        $manual = $this->cleanKeywords($manualSeeds);
        if ($manual !== []) {
            $keywords = array_slice($manual, 0, $cap);
            $seedSource = CompetitorDiscoveryRun::SEED_MANUAL;
        } else {
            $keywords = $this->gscSeedKeywords($website->id, $cap);
            $seedSource = CompetitorDiscoveryRun::SEED_GSC;
        }

        if ($keywords === []) {
            return null;
        }

        $run = CompetitorDiscoveryRun::create([
            'run_id' => (string) Str::uuid(),
            'website_id' => $website->id,
            'user_id' => $userId,
            'status' => CompetitorDiscoveryRun::STATUS_QUEUED,
            'keywords_planned' => count($keywords),
            'serp_calls_made' => 0,
            'seed_source' => $seedSource,
        ]);

        RunCompetitorDiscovery::dispatch($run->run_id, $keywords);

        return $run;
    }

    /**
     * The fan-out — called from {@see RunCompetitorDiscovery}. Scans each
     * keyword's SERP, tallies competitor domains, scores + persists them, then
     * prunes to this run.
     *
     * @param  list<string>  $keywords
     */
    public function run(string $runId, array $keywords): void
    {
        $run = CompetitorDiscoveryRun::query()->where('run_id', $runId)->first();
        if (! $run instanceof CompetitorDiscoveryRun || $run->isFinished()) {
            return;
        }
        $website = Website::query()->find($run->website_id);
        if (! $website instanceof Website) {
            $run->markFailed('Website no longer exists.');

            return;
        }
        $run->markRunning();

        $ownDomain = CompetitorBacklink::extractDomain((string) $website->domain);
        $gl = $this->countryCodeFor($website);
        $cap = $this->keywordCap();

        /** @var array<string, array{appearances: int, positions: list<int>, samples: list<string>}> $tally */
        $tally = [];
        $sampled = 0;
        $serpCalls = 0;

        foreach (array_slice($keywords, 0, $cap) as $keyword) {
            try {
                $serp = $this->serp->organic($keyword, $gl, $website->id, $run->user_id, 'competitor_discovery');
            } catch (\Throwable $e) {
                // UsageMeter quota or transport error — stop spending, keep
                // whatever we gathered so far.
                Log::info('CompetitorDiscovery: SERP call stopped: '.$e->getMessage(), ['run_id' => $runId]);
                break;
            }

            $serpCalls++;
            if ($serp === null) {
                continue;
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
                $link = (string) ($result['link'] ?? $result['url'] ?? '');
                $domain = CompetitorBacklink::extractDomain($link);
                if ($domain === '' || $domain === $ownDomain || $this->isGiant($domain)) {
                    continue;
                }
                if (isset($seenThisSerp[$domain])) {
                    continue; // count a domain once per SERP, at its best position
                }
                $seenThisSerp[$domain] = true;

                $pos = (int) ($result['position'] ?? ($idx + 1));
                $tally[$domain] ??= ['appearances' => 0, 'positions' => [], 'samples' => []];
                $tally[$domain]['appearances']++;
                $tally[$domain]['positions'][] = $pos;
                if (count($tally[$domain]['samples']) < 10) {
                    $tally[$domain]['samples'][] = $keyword;
                }
            }
        }

        $this->persist($run, $tally, max(1, $sampled));

        $run->forceFill(['serp_calls_made' => $serpCalls])->save();
        $run->markCompleted();

        $this->enrichCompetitors($website, array_keys($tally));

        // Organic-traffic estimation for the site + every competitor in ONE
        // flat-priced DataForSEO Labs call — powers the Site Explorer traffic
        // chart and is cached 30 days on the shared domain_metrics asset.
        $sandbox = (bool) $website->user?->is_admin;
        $this->enricher->enrichTraffic(
            array_merge([(string) $website->domain], array_keys($tally)),
            $sandbox
        );
    }

    /**
     * Upsert one row per tallied domain, then prune rows from older runs.
     *
     * @param  array<string, array{appearances: int, positions: list<int>, samples: list<string>}>  $tally
     */
    private function persist(CompetitorDiscoveryRun $run, array $tally, int $sampled): void
    {
        $now = Carbon::now();

        foreach ($tally as $domain => $data) {
            $appearances = $data['appearances'];
            $positions = $data['positions'];
            $avgPosition = $positions !== [] ? array_sum($positions) / count($positions) : null;
            $bestPosition = $positions !== [] ? min($positions) : null;

            DiscoveredCompetitor::updateOrCreate(
                ['website_id' => $run->website_id, 'competitor_domain' => $domain],
                [
                    'appearances' => $appearances,
                    'keywords_sampled' => $sampled,
                    'avg_position' => $avgPosition,
                    'best_position' => $bestPosition,
                    'score' => $this->score($appearances, $sampled, $avgPosition),
                    'sample_keywords' => $data['samples'],
                    'run_id' => $run->run_id,
                    'last_refreshed_at' => $now,
                ]
            );
        }

        // Prune competitors that weren't seen in this run (stale from older runs).
        DiscoveredCompetitor::query()
            ->where('website_id', $run->website_id)
            ->where('run_id', '!=', $run->run_id)
            ->delete();
    }

    /**
     * Ranked competitor score (0–100). Rewards domains that appear across many
     * of our keywords (frequency) and rank highly (position).
     */
    public function score(int $appearances, int $sampled, ?float $avgPosition): float
    {
        $frequency = $sampled > 0 ? min(1.0, $appearances / $sampled) : 0.0;
        $positionScore = $avgPosition !== null
            ? max(0.0, min(1.0, 1 - (($avgPosition - 1) / 9)))
            : 0.0;

        return round(100 * (0.65 * $frequency + 0.35 * $positionScore), 2);
    }

    /**
     * Enrich the top discovered competitors from the shared {@see CompetitorEnricher}
     * — the SAME real DataForSEO referring-domains/backlinks + Moz DA/PA the Content
     * Autopilot analysis uses, all cached 30 days on the `domain_metrics` asset (so a
     * domain enriched for one product is free for the other). This replaces the old
     * OpenPageRank-only DA, which undercounts referring domains 10-100x.
     *
     * Informational only — none of these feed the ranking score. Capped at the top
     * few domains, and paid providers self-throttle via their monthly spend breakers.
     * OPR / historical CompetitorBacklink DA survive purely as a last-resort DA
     * fallback when both DataForSEO and Moz are unavailable.
     *
     * Topical relevance is deliberately NOT run here (an LLM call per domain) — it is
     * opt-in via {@see \App\Jobs\Competitive\ClassifyCompetitorTopicJob}.
     *
     * @param  list<string>  $domains
     */
    private function enrichCompetitors(Website $website, array $domains): void
    {
        $top = array_slice($domains, 0, $this->enrichCap());
        if ($top === []) {
            return;
        }

        // Admin-owned sites route to the free mock host and are never persisted to
        // the shared asset — same policy as ContentSetupInsights.
        $sandbox = (bool) $website->user?->is_admin;

        $oprMetrics = null; // lazily fetched only if a domain has no DFS/Moz authority

        foreach ($top as $domain) {
            $m = $this->enricher->enrich($domain, $sandbox);

            // Authority preference: real Moz DA → DataForSEO domain rank (0-1000
            // scaled to 0-100) → OpenPageRank → any historical CompetitorBacklink DA.
            $da = $m['moz_da'];
            if ($da === null && $m['dfs_rank'] !== null) {
                $da = (int) min(100, round($m['dfs_rank'] / 10));
            }
            if ($da === null) {
                $oprMetrics ??= $this->opr->metricsFor($top);
                $score = $oprMetrics[$domain]['score']
                    ?? ($oprMetrics[OpenPageRankClient::registrable($domain)]['score'] ?? null);
                $da = $score !== null
                    ? (int) min(100, round((float) $score * 10))
                    : CompetitorBacklink::query()
                        ->forDomain($domain)
                        ->whereNotNull('domain_authority')
                        ->max('domain_authority');
            }

            DiscoveredCompetitor::query()
                ->where('website_id', $website->id)
                ->where('competitor_domain', $domain)
                ->update([
                    'domain_authority' => $da !== null ? (int) $da : null,
                    'page_authority' => $m['moz_pa'],
                    'referring_domains' => $m['referring_domains'],
                    'backlinks' => $m['backlinks'],
                ]);
        }
    }

    private function enrichCap(): int
    {
        return max(1, min(50, (int) config('services.competitive.discovery_enrich_max', 10)));
    }

    /**
     * Top GSC queries over the last 28 days, preferring those where we rank
     * 4–30 (competitors most visible there), padded with the highest-impression
     * remainder up to the cap.
     *
     * @return list<string>
     */
    private function gscSeedKeywords(string $websiteId, int $cap): array
    {
        $rows = SearchConsoleData::query()
            ->where('website_id', $websiteId)
            ->whereDate('date', '>=', now()->subDays(28)->toDateString())
            ->where('query', '!=', '')
            ->groupBy('query')
            ->select('query', DB::raw('SUM(impressions) as imp'), DB::raw('AVG(position) as pos'))
            ->havingRaw('SUM(impressions) > 0')
            ->orderByDesc('imp')
            ->limit($cap * 2)
            ->get();

        $preferred = [];
        $others = [];
        foreach ($rows as $row) {
            $query = trim((string) $row->query);
            if ($query === '') {
                continue;
            }
            $pos = (float) $row->pos;
            if ($pos >= 4 && $pos <= 30) {
                $preferred[] = $query;
            } else {
                $others[] = $query;
            }
        }

        return array_slice(array_values(array_unique([...$preferred, ...$others])), 0, $cap);
    }

    /**
     * Mega-domains that rank for nearly everything and are never a client's
     * actionable "competitor" — shared list lives in Support\GiantDomains.
     */
    private function isGiant(string $domain): bool
    {
        return \App\Support\GiantDomains::isGiant($domain);
    }

    /** Map the website's country (or default) to a 2-letter SERP `gl`. */
    private function countryCodeFor(Website $website): string
    {
        // The keyword tools are US-centric and Websites carry no explicit
        // country, so default to US; centralized so 'uk'→'gb'/'global'→'us'
        // stay consistent with the gap-analysis verifier.
        return KeywordFinderLocations::serperGl('us');
    }

    private function keywordCap(): int
    {
        return max(1, min(100, (int) config('services.competitive.discovery_max_keywords', 25)));
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function cleanKeywords(array $keywords): array
    {
        $seen = [];
        $out = [];
        foreach ($keywords as $k) {
            $s = is_string($k) ? trim($k) : '';
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return $out;
    }
}
