<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentAeoQuestion;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Facades\Log;

/**
 * Where the questions come from — none of them bought.
 *
 * In order of trustworthiness:
 *   1. **Search Console** — question-shaped queries this site already gets
 *      impressions for. Real demand, already paid for, no guessing.
 *   2. **The briefs we already wrote** — `AiContentBriefService` stores a
 *      people_also_ask list on every topic, so the questions our own articles
 *      target are sitting in the database unused.
 *   3. **The offerings** — "best <what they sell> in <where>", which is the
 *      buying question the client actually cares about.
 *   4. **One LLM pass**, only to top up what the first three did not fill.
 *
 * The client edits the list afterwards; these are a starting point, not a
 * verdict on what their buyers ask.
 */
class AeoQuestionSource
{
    /** Fill up to the site's quota, never past it. */
    public function seed(Website $website, int $limit): int
    {
        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        if ($plan === null || $limit < 1) {
            return 0;
        }

        $candidates = array_merge(
            $this->fromSearchConsole($website, $limit),
            $this->fromBriefs($plan, $limit),
            $this->fromOfferings($plan),
        );

        $stored = $this->store($website, $candidates, $limit);

        // Only pay for questions when the free sources came up short.
        if ($stored < $limit) {
            $stored += $this->store($website, $this->fromModel($plan, $website, $limit - $stored), $limit);
        }

        return $stored;
    }

    /**
     * Question-shaped queries from GSC.
     *
     * @return list<array{question: string, source: string}>
     */
    private function fromSearchConsole(Website $website, int $limit): array
    {
        try {
            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->whereNotNull('query')
                ->where(function ($q) {
                    foreach (['how ', 'what ', 'why ', 'which ', 'where ', 'when ', 'is ', 'are ', 'best '] as $prefix) {
                        $q->orWhere('query', 'like', $prefix.'%');
                    }
                })
                ->select('query')
                ->selectRaw('SUM(impressions) as impressions')
                ->groupBy('query')
                ->orderByDesc('impressions')
                ->limit($limit * 2)
                ->pluck('query');
        } catch (\Throwable $e) {
            Log::debug('aeo.questions_gsc_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);

            return [];
        }

        return $rows->map(fn ($q): array => [
            'question' => (string) $q,
            'source' => ContentAeoQuestion::SOURCE_GSC,
        ])->all();
    }

    /**
     * The PAA lists already stored on every topic's brief — written months ago
     * by AiContentBriefService and never read again until now.
     *
     * @return list<array{question: string, source: string, topic_id: ?string}>
     */
    private function fromBriefs(ContentPlan $plan, int $limit): array
    {
        $out = [];
        ContentTopic::query()
            ->where('plan_id', $plan->id)
            ->whereNotNull('brief')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'brief'])
            ->each(function (ContentTopic $topic) use (&$out, $limit): void {
                foreach ((array) (($topic->brief ?? [])['people_also_ask'] ?? []) as $question) {
                    if (count($out) >= $limit * 2) {
                        return;
                    }
                    $question = trim((string) $question);
                    if ($question !== '') {
                        $out[] = [
                            'question' => $question,
                            'source' => ContentAeoQuestion::SOURCE_ARTICLE,
                            'topic_id' => (string) $topic->id,
                        ];
                    }
                }
            });

        return $out;
    }

    /**
     * The buying question, built from what they sell and where.
     *
     * @return list<array{question: string, source: string}>
     */
    private function fromOfferings(ContentPlan $plan): array
    {
        $sell = array_values(array_filter(array_map(
            static fn ($s): string => trim((string) $s),
            (array) (($plan->offerings ?? [])['sell'] ?? [])
        )));
        if ($sell === []) {
            return [];
        }

        $where = filled($plan->country) && $plan->country !== 'global'
            ? ' in '.strtoupper((string) $plan->country)
            : '';

        $out = [];
        foreach (array_slice($sell, 0, 5) as $offering) {
            $out[] = ['question' => 'Best '.$offering.$where, 'source' => ContentAeoQuestion::SOURCE_BRAND];
            $out[] = ['question' => 'Who should I buy '.$offering.' from'.$where.'?', 'source' => ContentAeoQuestion::SOURCE_BRAND];
        }

        return $out;
    }

    /**
     * One completion, only for the shortfall.
     *
     * @return list<array{question: string, source: string}>
     */
    private function fromModel(ContentPlan $plan, Website $website, int $needed): array
    {
        if ($needed < 1) {
            return [];
        }
        $llm = LlmClientFactory::make();
        if (! $llm->isAvailable()) {
            return [];
        }

        $sell = implode(', ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 8));
        $user = <<<PROMPT
        This business ({$website->normalized_domain}) is described as:
        {$plan->business_description}
        They offer: {$sell}

        Write {$needed} questions a potential customer would type into ChatGPT when they are close to buying —
        the questions where being recommended would win the business. Real phrasing, not marketing language.

        Return JSON only: {"questions": ["...", "..."]}
        PROMPT;

        try {
            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You know how people ask AI assistants for recommendations. Reply with valid JSON only.'],
                ['role' => 'user', 'content' => $user],
            ], [
                'temperature' => 0.4,
                'max_tokens' => 600,
                'timeout' => 45,
                '__source' => 'content_autopilot.aeo_questions',
                '__unmetered' => true,
            ]);
        } catch (\Throwable $e) {
            Log::debug('aeo.questions_llm_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);

            return [];
        }

        app(ContentLlmSpendMeter::class)->add(ContentAutopilotConfig::aeoProbeCostUsd());

        return collect((array) ($response['questions'] ?? []))
            ->map(static fn ($q): array => ['question' => trim((string) $q), 'source' => ContentAeoQuestion::SOURCE_BRAND])
            ->filter(static fn (array $q): bool => $q['question'] !== '')
            ->values()
            ->all();
    }

    /**
     * Store what fits, deduped. Returns how many were actually added.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function store(Website $website, array $candidates, int $limit): int
    {
        $existing = ContentAeoQuestion::query()->where('website_id', $website->id)->count();
        $added = 0;

        foreach ($candidates as $candidate) {
            if ($existing + $added >= $limit) {
                break;
            }
            $question = trim((string) ($candidate['question'] ?? ''));
            if ($question === '' || mb_strlen($question) > 300) {
                continue;
            }
            $normalized = ContentAeoQuestion::normalize($question);
            if ($normalized === '') {
                continue;
            }

            $row = ContentAeoQuestion::query()->firstOrCreate(
                ['website_id' => $website->id, 'normalized_question' => $normalized],
                [
                    'question' => $question,
                    'source' => (string) ($candidate['source'] ?? ContentAeoQuestion::SOURCE_BRAND),
                    'topic_id' => $candidate['topic_id'] ?? null,
                    'is_active' => true,
                ]
            );
            if ($row->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }
}
