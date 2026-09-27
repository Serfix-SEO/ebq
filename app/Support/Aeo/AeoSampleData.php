<?php

namespace App\Support\Aeo;

use App\Models\ContentAeoAudit;

/**
 * The example report a free signup sees on the AI Visibility page.
 *
 * Two rules, and they matter more than the numbers:
 *
 *  1. **Not one figure here touches the visitor's own site.** The teaser never
 *     reads their audit, their crawler hits or their analytics. A real number
 *     shown under a "sample" label is a lie in the other direction — the
 *     client would discount data that was actually theirs — and a sample built
 *     from real data would leak what we are asking them to pay for.
 *  2. **Fixed, not random.** The same figures on every load. A sample that
 *     changes between refreshes reads as live data, which is exactly the
 *     confusion the big banner on the page exists to prevent.
 *
 * The shape is honest even though the numbers are invented: one blocked
 * training crawler, a couple of engines sending traffic, one agent never seen.
 * That is what a real mid-sized site looks like, so the upgrade delivers the
 * page they were promised rather than a livelier one.
 */
class AeoSampleData
{
    /** Verdicts per agent, as the readiness audit would store them. */
    public static function robotsAi(): array
    {
        $out = [];
        foreach (array_keys(AiAgents::all()) as $id) {
            $out[$id] = ContentAeoAudit::ALLOWED;
        }
        // One deliberate failure, because a report where everything passes
        // teaches the reader nothing about what the page is for.
        $out['gptbot'] = ContentAeoAudit::BLOCKED;
        $out['ccbot'] = ContentAeoAudit::BLOCKED;

        return $out;
    }

    /** A non-persisted audit the page can render exactly like a real one. */
    public static function audit(): ContentAeoAudit
    {
        $audit = new ContentAeoAudit;
        $audit->forceFill([
            'checked_at' => now()->subHours(9),
            'robots_ai' => self::robotsAi(),
            'robots_fetched' => true,
            'llms_txt_present' => false,
            'schema_coverage' => ['pages' => 84, 'with_answer_schema' => 61, 'types' => ['Article' => 61, 'Organization' => 84]],
            'readiness_score' => 74,
            'breakdown' => [
                ['code' => 'robots_search', 'label' => __('AI search crawlers can read your site'), 'passed' => true, 'weight' => 34, 'detail' => ''],
                ['code' => 'robots_user', 'label' => __('AI assistants can open your pages for a user'), 'passed' => true, 'weight' => 26, 'detail' => ''],
                ['code' => 'robots_training', 'label' => __('AI training crawlers can read your site'), 'passed' => false, 'weight' => 12, 'detail' => 'GPTBot, CCBot'],
                ['code' => 'llms_txt', 'label' => __('Your site publishes an llms.txt'), 'passed' => false, 'weight' => 10, 'detail' => ''],
                ['code' => 'answer_schema', 'label' => __('Your pages describe themselves in structured data'), 'passed' => true, 'weight' => 18, 'detail' => __(':n of :total pages checked', ['n' => 61, 'total' => 84])],
            ],
        ]);

        return $audit;
    }

    /** Crawler activity, in the shape AeoSignalReader::crawlerHits() returns. */
    public static function crawlerHits(): array
    {
        $bots = [];
        foreach ([
            'oai_searchbot' => [128, 46, 1],
            'chatgpt_user' => [54, 31, 1],
            'perplexitybot' => [37, 22, 2],
            'claudebot' => [11, 8, 4],
        ] as $id => [$hits, $pages, $daysAgo]) {
            $agent = AiAgents::find($id);
            $bots[] = [
                'bot' => $id,
                'label' => $agent['label'],
                'engine' => $agent['engine'],
                'kind' => $agent['kind'],
                'hits' => $hits,
                'pages' => $pages,
                'last_seen' => now()->subDays($daysAgo)->toDateString(),
            ];
        }

        return [
            'instrumented' => true,
            'stale' => false,
            'last_report' => now()->subDay()->toDateString(),
            'total' => 230,
            'bots' => $bots,
        ];
    }

    /** The visibility score, in the shape AeoVisibilityScorer::score() returns. */
    public static function visibility(): array
    {
        return [
            'score' => 61,
            'components' => [
                'crawler_access' => ['value' => 0.74, 'weight' => 25, 'detail' => __(':n AI crawler visits in the last 30 days', ['n' => 230])],
                'ai_referrals' => ['value' => 0.55, 'weight' => 25, 'detail' => __(':n visits from AI answers in 30 days', ['n' => 22])],
                'brand_recall' => ['value' => 0.48, 'weight' => 35, 'detail' => __('Named in :hit of :total answers', ['hit' => 6, 'total' => 25])],
            ],
            'signals_used' => ['crawler_access', 'ai_referrals', 'brand_recall'],
            'missing' => ['ai_overview'],
        ];
    }

    /** A rising score line — fixed, like everything else in the sample. */
    public static function history(): array
    {
        $shape = [38, 41, 39, 44, 47, 46, 52, 55, 54, 58, 61];
        $out = [];
        foreach ($shape as $i => $score) {
            $out[] = [
                'date' => now()->subWeeks(count($shape) - 1 - $i)->toDateString(),
                'score' => $score,
            ];
        }

        return $out;
    }

    /** Questions where a competitor is named instead — the sample's call to action. */
    public static function gaps(): array
    {
        return [
            [
                'question_id' => 'sample-1',
                'question' => __('Best oud perfume for hot weather'),
                'topic_id' => null,
                'competitors' => ['Ajmal', 'Swiss Arabian', 'Rasasi'],
            ],
            [
                'question_id' => 'sample-2',
                'question' => __('Which perfume lasts longest in humidity?'),
                'topic_id' => null,
                'competitors' => ['Lattafa', 'Ajmal'],
            ],
        ];
    }

    /** AI referral sessions, in the shape AeoSignalReader::referrals() returns. */
    public static function referrals(int $days = 90): array
    {
        // Daily and zero-filled, exactly like the real reader returns, so the
        // teaser's chart cannot look different from the one they are buying.
        // Fixed pattern, no rand() — see the class docblock.
        $shape = [0, 1, 2, 1, 3, 2, 4, 2, 0, 3, 5, 4, 6, 3, 5, 7, 4, 6, 8, 5, 7, 9, 6, 8, 4, 7, 10, 6, 9, 11];
        $series = [];
        for ($i = $days; $i >= 0; $i--) {
            $series[] = [
                'date' => now()->subDays($i)->toDateString(),
                'sessions' => $shape[($days - $i) % count($shape)],
            ];
        }

        return [
            'connected' => true,
            'total' => 173,
            'engines' => ['ChatGPT' => 112, 'Perplexity' => 38, 'Gemini' => 15, 'Claude' => 8],
            'series' => $series,
        ];
    }
}
