<?php

namespace App\Services\Llm;

/**
 * DeepSeek chat-completions client (OpenAI-compatible dialect). Defaults
 * to deepseek-chat (V3). Provider quirks handled here:
 *
 *  - Output cap: clamped to 32k via maxOutputTokensCap() (V4 accepts it;
 *    the V3-era API 400'd above 8192).
 *  - JSON mode requires the word "json" somewhere in the prompt or the
 *    API can 400 / emit degenerate output — prepareBody() appends a
 *    one-line system nudge when no message mentions it.
 *  - Thinking ("DeepThink" in the DeepSeek app): V4 models think BY
 *    DEFAULT when the request omits the flag, so prepareBody() always
 *    sends `thinking: {type: disabled|enabled}` — enabled only when the
 *    call site passes `reasoning => true`. The reply then carries
 *    `reasoning_content` alongside the answer. Verified live 2026-07-11
 *    on V4: thinking mode DOES support json_object and function calling
 *    (the old R1 `deepseek-reasoner` limits are gone), and reasoning
 *    tokens bill through `usage.total_tokens` AND count against
 *    `max_tokens` — an un-disabled small-budget call returns empty
 *    content (live incident: blog-post wizard briefs). Route thinking
 *    only where quality beats latency — never on interactive paths.
 *  - `deepseek-reasoner` still works as an always-thinking alias but is
 *    denylisted from the admin model list (AiModelConfig): as the
 *    platform DEFAULT it would add reasoning latency + token cost to
 *    every small call (block editor, chat, classifiers).
 *  - Slower first token than Mistral → 30s default timeout.
 */
final class DeepSeekClient extends OpenAiCompatibleClient
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';
    public const DEFAULT_MODEL = 'deepseek-chat';

    /**
     * Output-token ceiling. The V3-era API 400'd above 8192; V4 accepts
     * at least 32k (live-verified 2026-07-11 — the 8192 clamp was
     * silently starving the AI Writer, which asks for 16k). Kept as a
     * clamp so a future per-call request can't trip an API 400.
     */
    private const MAX_OUTPUT_TOKENS = 32768;

    /**
     * Reasoning budget when thinking is enabled. Reasoning tokens share
     * `max_tokens` with the answer — without a budget, thinking on
     * deepseek-v4-pro consumed a writer call's ENTIRE output allowance
     * and returned empty content (live incident 2026-07-11). 4k of
     * thinking is plenty for an article plan and leaves the rest of the
     * budget for the answer itself.
     */
    private const REASONING_BUDGET_TOKENS = 4096;

    public function __construct(string $apiKey, string $defaultModel = self::DEFAULT_MODEL)
    {
        parent::__construct($apiKey, $defaultModel);
    }

    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    protected function providerKey(): string
    {
        return 'deepseek';
    }

    protected function defaultTimeout(): int
    {
        return 30;
    }

    protected function maxOutputTokensCap(): ?int
    {
        return self::MAX_OUTPUT_TOKENS;
    }

    protected function prepareBody(array $body, array $options): array
    {
        // ALWAYS send the thinking flag. V4 models think BY DEFAULT when
        // it's omitted (found live 2026-07-11: a brief call burned its
        // whole 1500 max_tokens on reasoning_content and returned empty
        // content → llm_parse_failed in the blog-post wizard). Thinking
        // is opt-in per call site via `reasoning` — everything else must
        // explicitly disable it. When enabled, the budget_tokens cap
        // stops reasoning from starving the answer (they share
        // max_tokens; see REASONING_BUDGET_TOKENS).
        $body['thinking'] = empty($options['reasoning'])
            ? ['type' => 'disabled']
            : ['type' => 'enabled', 'budget_tokens' => self::REASONING_BUDGET_TOKENS];

        if (($body['response_format']['type'] ?? '') === 'json_object'
            && ! $this->messagesMentionJson($body['messages'] ?? [])) {
            $body['messages'][] = [
                'role' => 'system',
                'content' => 'Respond with valid JSON only.',
            ];
        }

        return $body;
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function messagesMentionJson(array $messages): bool
    {
        foreach ($messages as $m) {
            $content = $m['content'] ?? '';
            if (is_string($content) && stripos($content, 'json') !== false) {
                return true;
            }
        }
        return false;
    }
}
