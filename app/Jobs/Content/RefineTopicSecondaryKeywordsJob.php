<?php

namespace App\Jobs\Content;

use App\Models\ContentPlanKeyword;
use App\Models\ContentTopic;
use App\Services\Llm\LlmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Async AI polish for a research-added topic's secondary keywords. The click
 * handler seeds them instantly with a token-overlap pick from the plan's
 * vetted library; this job re-ranks a wider candidate slice semantically
 * (synonyms and close subtopics that share no literal word with the focus
 * keyword) using the business profile as context.
 *
 * Strictly choose-from-candidates: the LLM may only keep library keywords, so
 * every secondary stays relevance-vetted and carries real volume data. Fails
 * open — on any error the instant token-overlap pick simply stands. Skips
 * (and never rewrites) a topic that already started writing.
 */
class RefineTopicSecondaryKeywordsJob implements ShouldQueue
{
    use Queueable;

    /** Library keywords offered to the LLM (highest volume first). */
    private const PROMPT_CANDIDATES = 120;

    private const KEEP = 8;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public string $topicId)
    {
        $this->onQueue('content');
    }

    public function handle(): void
    {
        $topic = ContentTopic::query()->with('plan')->find($this->topicId);
        $plan = $topic?->plan;
        if ($topic === null || $plan === null || $topic->status !== ContentTopic::STATUS_APPROVED) {
            return; // gone, or already writing — the producer owns it now
        }

        $focus = mb_strtolower(trim((string) $topic->target_keyword));
        if ($focus === '') {
            return;
        }

        $candidates = ContentPlanKeyword::query()
            ->where('plan_id', $plan->id)
            ->where('keyword', '!=', $focus)
            ->orderByDesc('search_volume')
            ->limit(self::PROMPT_CANDIDATES + 30)
            ->get(['keyword', 'search_volume'])
            ->unique('keyword')
            ->take(self::PROMPT_CANDIDATES)
            ->values();
        if ($candidates->isEmpty()) {
            return;
        }

        $llm = app(LlmClient::class);
        if (! $llm->isAvailable()) {
            return; // instant pick stands
        }

        $list = $candidates
            ->map(fn ($c) => $c->keyword.($c->search_volume ? ' ('.$c->search_volume.'/mo)' : ''))
            ->implode("\n");
        $offer = implode(', ', array_slice((array) (($plan->offerings ?? [])['sell'] ?? []), 0, 12));
        $desc = mb_substr((string) $plan->business_description, 0, 500);
        $keep = self::KEEP;
        $directives = $plan?->promptAddendumBlock() ?? '';

        try {
            $result = $llm->completeJson([
                ['role' => 'system', 'content' => 'You select supporting SEO keywords for an article. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                Business offerings: {$offer}
                About: {$desc}{$directives}

                An article will target this focus keyword: "{$focus}"

                From the candidate keywords below, pick up to {$keep} that best SUPPORT
                that article as secondary keywords: close subtopics, synonyms, question
                phrasings and long-tail variants of the SAME topic. Skip anything that
                deserves its own separate article or is off-topic. Only choose from the
                list; return each keyword's exact text without the volume suffix.

                {$list}

                Return JSON: {"keywords": ["...", "..."]}
                PROMPT],
            ], ['temperature' => 0.1, 'max_tokens' => 800, 'timeout' => 45, '__source' => 'content_autopilot.secondary_refine', '__unmetered' => true]);
        } catch (\Throwable $e) {
            Log::info('content_autopilot.secondary_refine_failed', [
                'topic_id' => $topic->id, 'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return;
        }

        $allowed = $candidates->pluck('keyword')
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))
            ->flip();
        $picked = collect((array) ($result['keywords'] ?? []))
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))
            ->filter(fn ($k) => $k !== '' && $k !== $focus && isset($allowed[$k]))
            ->unique()
            ->take(self::KEEP)
            ->values()
            ->all();
        if ($picked === []) {
            return; // nothing usable — instant pick stands
        }

        // Re-check + guarded write: never clobber a topic the producer claimed
        // while the LLM was thinking.
        ContentTopic::query()
            ->whereKey($topic->id)
            ->where('status', ContentTopic::STATUS_APPROVED)
            ->update(['secondary_keywords' => json_encode($picked)]);
    }
}
