<?php

namespace App\Services\Competitive;

use App\Exceptions\QuotaExceededException;
use App\Models\CompetitorBacklink;
use App\Services\OpenPageRankClient;
use Illuminate\Support\Facades\Cache;

/**
 * Transparent 0–100 keyword opportunity score: how easy AND worthwhile a
 * keyword is to win. Higher = better. Built from inputs we already have, no new
 * vendor:
 *   - difficulty  ← avg domain authority of the live SERP top-10 + SERP-feature
 *                   crowding (answer box, knowledge graph, ads, shopping, PAA)
 *   - worth       ← search volume (log-scaled to tame bucket coarseness)
 *   - proximity   ← our own current position (striking distance 4–15 is gold)
 *
 * The score is intentionally explainable: {@see score} returns the component
 * breakdown so the UI can show "Top-10 DA 62 · 3 SERP features · 12k vol".
 */
class OpportunityScoreService
{
    public function __construct(
        private SerpCache $serp,
        private OpenPageRankClient $opr,
    ) {
    }

    /**
     * Pure scoring function.
     *
     * @param  array{answerBox?: bool, knowledgeGraph?: bool, ads?: bool, shopping?: bool, paa?: int}  $serpFeatures
     * @return array{score: int, components: array<string, mixed>}
     */
    public function score(?float $avgTop10Da, array $serpFeatures, ?int $volume, ?float $ourPosition): array
    {
        // Missing DA → neutral 0.5 rather than assuming easy or hard.
        $daNorm = $avgTop10Da !== null ? $this->clamp($avgTop10Da / 100) : 0.5;

        $answerBox = ! empty($serpFeatures['answerBox']);
        $kg = ! empty($serpFeatures['knowledgeGraph']);
        $ads = ! empty($serpFeatures['ads']);
        $shopping = ! empty($serpFeatures['shopping']);
        $paa = (int) ($serpFeatures['paa'] ?? 0);

        $crowding = min(1.0,
            0.25 * ($answerBox ? 1 : 0)
            + 0.20 * ($kg ? 1 : 0)
            + 0.15 * ($ads ? 1 : 0)
            + 0.20 * ($shopping ? 1 : 0)
            + 0.20 * min(1.0, $paa / 4)
        );

        $difficulty = $this->clamp(0.6 * $daNorm + 0.4 * $crowding);
        $ease = 1 - $difficulty;

        $volumeNorm = $this->clamp(log10(max(1, (int) $volume)) / 5);

        $proximity = ($ourPosition !== null && $ourPosition >= 4 && $ourPosition <= 15)
            ? 1.0
            : ($ourPosition === null ? 0.25 : 0.0);

        $opportunity = (int) round(100 * $this->clamp(
            0.55 * $ease + 0.30 * $volumeNorm + 0.15 * $proximity
        ));

        return [
            'score' => $opportunity,
            'components' => [
                'avg_top10_da' => $avgTop10Da !== null ? round($avgTop10Da, 1) : null,
                'serp_features' => array_keys(array_filter([
                    'answer box' => $answerBox,
                    'knowledge graph' => $kg,
                    'ads' => $ads,
                    'shopping' => $shopping,
                    'people also ask' => $paa >= 4,
                ])),
                'volume' => $volume,
                'our_position' => $ourPosition,
                'difficulty' => round($difficulty, 2),
            ],
        ];
    }

    /**
     * Score a keyword using only data already on hand (no SERP call). Uses a
     * neutral difficulty baseline — good enough for the bulk of gap rows; the
     * live variant refines the few the user expands.
     *
     * @return array{score: int, components: array<string, mixed>}
     */
    public function lightScore(?int $volume, ?float $ourPosition): array
    {
        return $this->score(null, [], $volume, $ourPosition);
    }

    /**
     * Fetch the live SERP for a keyword and compute the full score (top-10 DA +
     * SERP-feature crowding). Cost-bearing — call this only for the capped
     * subset the user explicitly expands.
     *
     * @return array{score: int, components: array<string, mixed>}|null
     */
    public function liveScore(string $keyword, string $gl, ?int $volume, ?float $ourPosition, ?string $websiteId = null, ?string $ownerUserId = null): ?array
    {
        try {
            $payload = $this->serp->organic($keyword, $gl, $websiteId, $ownerUserId, 'opportunity_score');
        } catch (QuotaExceededException $e) {
            // Let the plan-cap case bubble so the caller can show the upgrade
            // CTA — never silently no-op on quota. (A cache hit never gets here.)
            throw $e;
        } catch (\Throwable) {
            return null;
        }
        if ($payload === null) {
            return null;
        }

        return $this->scoreFromSerp($payload, $volume, $ourPosition, $websiteId, $ownerUserId);
    }

    /**
     * Score from an already-fetched Serper organic response: extract the
     * top-10 domains' avg DA + SERP-feature crowding and combine with volume +
     * our position. Shared by {@see liveScore} and the gap-analysis verifier so
     * one SERP call powers both ranking verification and scoring.
     *
     * @param  array<string, mixed>  $json
     * @return array{score: int, components: array<string, mixed>}
     */
    public function scoreFromSerp(array $json, ?int $volume, ?float $ourPosition, ?string $websiteId = null, ?string $ownerUserId = null): array
    {
        $organic = is_array($json['organic'] ?? null) ? $json['organic'] : [];
        $domains = [];
        foreach ($organic as $r) {
            if (! is_array($r)) {
                continue;
            }
            $d = CompetitorBacklink::extractDomain((string) ($r['link'] ?? $r['url'] ?? ''));
            if ($d !== '') {
                $domains[$d] = true;
            }
        }
        $domains = array_slice(array_keys($domains), 0, 10);

        // Top-10 authority from Open PageRank (free tier, one bulk call for
        // all 10, cached 7 days per domain set). This REPLACED the old
        // CompetitorBacklinkService::queueRefresh() path, which fetched each
        // SERP domain's BACKLINKS from Keywords Everywhere at 50 credits per
        // domain just to derive a DA number — a single gap verification pass
        // billed thousands of KE credits sweeping random SERP domains
        // (ssa.gov, support.google.com…) through the backlink API
        // (found 2026-07-14 on the admin usage page). OPR score is 0–10 →
        // ×10 for the 0–100 DA-like scale score() expects; per-domain
        // fallback to any historically-cached CompetitorBacklink DA.
        $avgDa = $this->avgAuthority($domains);

        $features = [
            'answerBox' => isset($json['answerBox']),
            'knowledgeGraph' => isset($json['knowledgeGraph']),
            'ads' => ! empty($json['ads']),
            'shopping' => ! empty($json['shopping']),
            'paa' => is_array($json['peopleAlsoAsk'] ?? null) ? count($json['peopleAlsoAsk']) : 0,
        ];

        return $this->score($avgDa, $features, $volume, $ourPosition);
    }

    /**
     * Average 0–100 authority for a set of SERP domains: Open PageRank score
     * ×10 (free bulk endpoint, cached 7 days per domain set), falling back
     * per-domain to any historically-cached CompetitorBacklink DA. Null when
     * nothing is known — score() treats that as neutral.
     *
     * @param  list<string>  $domains
     */
    private function avgAuthority(array $domains): ?float
    {
        if ($domains === []) {
            return null;
        }

        $sorted = $domains;
        sort($sorted);
        $metrics = Cache::remember(
            'opr-serp-authority:'.md5(implode('|', $sorted)),
            now()->addDays(7),
            fn (): array => $this->opr->metricsFor($domains),
        );

        $values = [];
        foreach ($domains as $d) {
            $score = $metrics[$d]['score']
                ?? ($metrics[OpenPageRankClient::registrable($d)]['score'] ?? null);
            if ($score !== null) {
                $values[] = min(100.0, (float) $score * 10);

                continue;
            }
            $da = CompetitorBacklink::query()
                ->forDomain($d)
                ->whereNotNull('domain_authority')
                ->max('domain_authority');
            if ($da !== null) {
                $values[] = (float) $da;
            }
        }

        return $values !== [] ? array_sum($values) / count($values) : null;
    }

    private function clamp(float $v, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $v));
    }
}
