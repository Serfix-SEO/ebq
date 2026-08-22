<?php

namespace App\Services\Content;

use App\Models\ContentTopic;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use Illuminate\Support\Str;

/**
 * Rewrite-request helper (replaced the allow/reject classifier 2026-08-23 —
 * it produced false rejections like "add 100 name examples"; owner decision:
 * never judge relevance, instead OFFER an enhanced version of the client's
 * prompt and let them choose between their own and the enhanced one).
 *
 * enhance() input is the client's prompt + light topic context ONLY — never
 * the article body (cheap + fast). Failure returns null and the caller
 * proceeds with the original prompt. The only hard rejection left is the
 * injection-needle check; real protection stays where it always was: the
 * advisory CLIENT REWRITE REQUEST framing + the brand-safety/publish gates
 * on the finished article.
 */
class RewritePromptEnhancer
{
    public const MAX_LEN = 2000;

    public function __construct(private readonly ?LlmClient $llm = null) {}

    /**
     * Hard blocks only: blatant prompt-injection and resource abuse (owner
     * definition of manipulation: "make it a 1 million word article"). NEVER
     * topical relevance — that produced false rejections. Null = fine.
     */
    public function blockReason(string $body): ?string
    {
        $trimmed = trim($body);
        if (mb_strlen($trimmed) > self::MAX_LEN) {
            return __('Please keep your request under 2,000 characters.');
        }

        $lower = mb_strtolower($trimmed);
        foreach ([
            'ignore previous', 'ignore all previous', 'disregard previous',
            'forget previous instructions', 'forget all previous',
            'reveal your system prompt', 'print your system prompt',
            'output your system prompt', 'you are now', 'system prompt:',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return __('This looks like an attempt to change how the writer works rather than a request about the article.');
            }
        }

        // Resource abuse: absurd length demands ("1 million words", "50000
        // word article"). The pipeline hard-caps output anyway (16k tokens
        // per pass + the length clamp in the rewrite block); this just tells
        // the client honestly instead of silently under-delivering.
        if (preg_match_all('/(\d[\d,\.]*)\s*(k|thousand|m|million|lakh)?\s*[- ]?word/iu', $lower, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $n = (float) str_replace(',', '', $hit[1]);
                $n *= match (strtolower($hit[2] ?? '')) {
                    'k', 'thousand' => 1_000,
                    'm', 'million' => 1_000_000,
                    'lakh' => 100_000,
                    default => 1,
                };
                if ($n > 10_000) {
                    return __('Rewrites keep your article close to its planned length — huge word-count requests can\'t be applied. Ask for specific changes instead.');
                }
            }
        }

        return null;
    }

    /**
     * Turn the client's note into a clearer editor instruction. Preserves
     * every specific (quantities, examples, symbols) and invents no new
     * requirements. Null when the LLM is unavailable or unhelpful — the
     * caller then just uses the original prompt.
     */
    public function enhance(string $prompt, ContentTopic $topic): ?string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return null;
        }

        try {
            $stage = ContentAutopilotConfig::modelFor('ideate');
            $llm = $this->llm ?? LlmClientFactory::make($stage['provider']);
            if (! $llm->isAvailable()) {
                return null;
            }

            $response = $llm->completeJson([
                ['role' => 'system', 'content' => "You improve a client's article-rewrite request into a clear, specific instruction for an article editor.\n\nRules:\n- Preserve EVERY specific the client gave: quantities, example strings, symbols, unicode, names — copy them verbatim.\n- Make the intent concrete and actionable (where in the article, what form, how much) without inventing requirements the client didn't imply.\n- Keep it under 1200 characters, same language as the client's request.\n\nReturn STRICT JSON: {\"enhanced_prompt\": string}."],
                ['role' => 'user', 'content' => "ARTICLE TOPIC: {$topic->title}\nTARGET KEYWORD: {$topic->target_keyword}\n\nCLIENT REQUEST:\n{$prompt}"],
            ], array_filter([
                'temperature' => 0.2,
                'max_tokens' => 600,
                'json_object' => true,
                'timeout' => 12,
                'model' => $stage['model'],
                '__source' => 'content_autopilot.rewrite_enhance',
                '__unmetered' => true,
            ]));

            $enhanced = trim((string) ($response['enhanced_prompt'] ?? ''));
            if ($enhanced === '' || Str::lower($enhanced) === Str::lower($prompt)) {
                return null; // nothing gained — don't offer a fake choice
            }

            return mb_substr($enhanced, 0, self::MAX_LEN);
        } catch (\Throwable) {
            return null;
        }
    }
}
