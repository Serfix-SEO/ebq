<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentAeoQuestion;
use App\Models\ContentAeoRun;
use App\Models\ContentAeoScore;
use App\Models\Website;

/**
 * One number for "are the AI answers finding you?", built from the signals
 * that are actually available for this site.
 *
 * **Weights renormalize over the signals we have.** A site with no analytics
 * connected has no referral data; scoring that as zero would tell them they
 * are failing at something we simply cannot see, which is the same dishonesty
 * as inventing the number. Missing signals drop out and the page says which
 * ones were used — the trick `ContentSeoScorer` already uses for context it
 * lacks.
 *
 * Brand recall is a **4-week rolling rate**, not the last run. Model answers
 * move week to week for reasons that have nothing to do with the client, and a
 * headline number that jumps on noise trains people to ignore it.
 */
class AeoVisibilityScorer
{
    public const VERSION = 1;

    /** Full weights; each drops out when its signal is unavailable. */
    private const WEIGHTS = [
        'crawler_access' => 25,
        'ai_referrals' => 25,
        'brand_recall' => 35,
        'ai_overview' => 15,
    ];

    public function __construct(private readonly AeoSignalReader $signals) {}

    /**
     * @return array{score: int, components: array<string, array{value: float, weight: int, detail: string}>, signals_used: list<string>, missing: list<string>}
     */
    public function score(Website $website): array
    {
        $components = [];
        $missing = [];

        // 1. Can the engines reach the site, and do they? Readiness is always
        // knowable; hits only where our plugin or kit runs.
        $audit = $this->signals->latestAudit($website);
        if ($audit !== null) {
            $hits = $this->signals->crawlerHits($website);
            $readiness = $audit->readiness_score / 100;
            // Being crawled is worth more than merely being allowed, but only
            // counts where we can actually observe it.
            $value = $hits['instrumented']
                ? ($readiness * 0.6) + (min(1.0, $hits['total'] / 50) * 0.4)
                : $readiness;
            $components['crawler_access'] = [
                'value' => round($value, 3),
                'weight' => self::WEIGHTS['crawler_access'],
                'detail' => $hits['instrumented']
                    ? __(':n AI crawler visits in the last 30 days', ['n' => $hits['total']])
                    : __('Based on which crawlers your site allows'),
            ];
        } else {
            $missing[] = 'crawler_access';
        }

        // 2. People arriving from an AI answer.
        $referrals = $this->signals->referrals($website, 30);
        if ($referrals['connected']) {
            $components['ai_referrals'] = [
                // 40 visits a month from AI answers is a strong signal for a
                // small site; the curve flattens rather than rewarding scale.
                'value' => round(min(1.0, $referrals['total'] / 40), 3),
                'weight' => self::WEIGHTS['ai_referrals'],
                'detail' => __(':n visits from AI answers in 30 days', ['n' => $referrals['total']]),
            ];
        } else {
            $missing[] = 'ai_referrals';
        }

        // 3. Does the model name them? The headline signal.
        $recall = $this->recallRate($website);
        if ($recall !== null) {
            $components['brand_recall'] = [
                'value' => round($recall['rate'], 3),
                'weight' => self::WEIGHTS['brand_recall'],
                'detail' => __('Named in :hit of :total answers', ['hit' => $recall['hits'], 'total' => $recall['asked']]),
            ];
        } else {
            $missing[] = 'brand_recall';
        }

        // 4. Google AI Overview — experimental, usually absent.
        $aio = $this->aioRate($website);
        if ($aio !== null) {
            $components['ai_overview'] = [
                'value' => round($aio, 3),
                'weight' => self::WEIGHTS['ai_overview'],
                'detail' => __('Appears in Google AI Overviews'),
            ];
        } else {
            $missing[] = 'ai_overview';
        }

        $totalWeight = array_sum(array_column($components, 'weight'));
        $earned = 0.0;
        foreach ($components as $c) {
            $earned += $c['value'] * $c['weight'];
        }

        return [
            'score' => $totalWeight > 0 ? (int) round($earned / $totalWeight * 100) : 0,
            'components' => $components,
            'signals_used' => array_keys($components),
            'missing' => $missing,
        ];
    }

    /** Store today's score so the page can draw a line rather than a number. */
    public function record(Website $website): ?ContentAeoScore
    {
        $result = $this->score($website);
        if ($result['signals_used'] === []) {
            return null;   // nothing measurable yet — a zero would be a claim
        }

        return ContentAeoScore::query()->updateOrCreate(
            ['website_id' => $website->id, 'scored_on' => now()->toDateString()],
            [
                'score' => $result['score'],
                'components' => $result['components'],
                'signals_used' => $result['signals_used'],
            ]
        );
    }

    /**
     * Four-week mention rate across successful runs.
     *
     * Failed runs are excluded rather than counted as "not mentioned" — the
     * distinction between "the model didn't name you" and "we couldn't ask"
     * is the whole reason `ok` is a column.
     *
     * @return array{rate: float, hits: int, asked: int}|null
     */
    private function recallRate(Website $website): ?array
    {
        $runs = ContentAeoRun::query()
            ->where('website_id', $website->id)
            ->where('ok', true)
            ->where('ran_on', '>=', now()->subDays(28)->toDateString())
            ->whereIn('engine', [ContentAeoRun::ENGINE_DEEPSEEK, ContentAeoRun::ENGINE_MISTRAL])
            ->get(['mentioned', 'mention_rank']);

        if ($runs->isEmpty()) {
            return null;
        }

        $asked = $runs->count();
        $hits = $runs->where('mentioned', true)->count();
        // Being named first is worth more than being named eighth — a client
        // buried at the bottom of a list is not really being recommended.
        $weighted = $runs->sum(function (ContentAeoRun $run): float {
            if (! $run->mentioned) {
                return 0.0;
            }
            $rank = max(1, (int) ($run->mention_rank ?? 5));

            return $rank <= 3 ? 1.0 : 0.6;
        });

        return ['rate' => $asked > 0 ? $weighted / $asked : 0.0, 'hits' => $hits, 'asked' => $asked];
    }

    private function aioRate(Website $website): ?float
    {
        $runs = ContentAeoRun::query()
            ->where('website_id', $website->id)
            ->where('engine', ContentAeoRun::ENGINE_GOOGLE_AIO)
            ->where('ok', true)
            ->where('ran_on', '>=', now()->subDays(28)->toDateString())
            ->get(['mentioned']);

        return $runs->isEmpty() ? null : $runs->where('mentioned', true)->count() / $runs->count();
    }

    /**
     * Questions where the model named somebody else and not the client — the
     * list that turns this page into work for the calendar.
     *
     * @return list<array{question: string, question_id: string, competitors: list<string>, topic_id: ?string}>
     */
    public function gaps(Website $website, int $limit = 10): array
    {
        $latest = ContentAeoRun::query()
            ->where('website_id', $website->id)
            ->where('ok', true)
            ->where('ran_on', '>=', now()->subDays(28)->toDateString())
            ->orderByDesc('ran_on')
            ->get()
            ->groupBy('question_id');

        $questions = ContentAeoQuestion::query()
            ->whereIn('id', $latest->keys())
            ->get()
            ->keyBy('id');

        $gaps = [];
        foreach ($latest as $questionId => $runs) {
            // Not named by ANY engine we asked — one engine forgetting you is
            // noise, all of them is a gap.
            if ($runs->contains(fn (ContentAeoRun $r): bool => (bool) $r->mentioned)) {
                continue;
            }
            $question = $questions[$questionId] ?? null;
            if ($question === null) {
                continue;
            }
            $gaps[] = [
                'question_id' => (string) $questionId,
                'question' => (string) $question->question,
                'topic_id' => $question->topic_id ? (string) $question->topic_id : null,
                'competitors' => array_values(array_unique(array_merge(
                    ...$runs->map(fn (ContentAeoRun $r): array => (array) ($r->competitors ?? []))->all()
                ))),
            ];
        }

        return array_slice($gaps, 0, $limit);
    }
}
