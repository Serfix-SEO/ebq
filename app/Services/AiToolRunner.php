<?php

namespace App\Services;

use App\AiTools\Contracts\AiTool;
use App\AiTools\Contracts\AiToolMeta;
use App\AiTools\Contracts\AiToolResult;
use App\AiTools\Contracts\InputField;
use App\Models\Website;
use App\Services\Ai\ContextBuilder;
use App\Support\CreditTypes;
use Illuminate\Support\Facades\Cache;

/**
 * Single execution path for every AI Studio tool.
 *
 * Flow:
 *   1. Validate input against tool->meta()->inputs (required, max_length, options).
 *   2. Build ToolContext from EBQ proprietary signals (lazy, opt-in).
 *   3. (Optional) Look up cached result by hashed input + tool id.
 *   4. tool->execute(input, context) returns a typed AiToolResult.
 *   5. Cache the result if the tool declared a TTL.
 *   6. Convert LLM token usage → EBQ Content Credits and log to
 *      `client_activities` with provider='ebq_content_credits' and
 *      meta.tool_id, so per-tool usage is queryable.
 *   7. Return the result.
 *
 * The plugin only ever sees the result; it never knows which model
 * ran, what the prompt was, or which signals were loaded — that's
 * the moat.
 */
class AiToolRunner
{
    /** Default ratio: 1 credit per 100 LLM tokens (matches existing writer). */
    private const DEFAULT_TOKENS_PER_CREDIT = 100;

    /** Floor: even cached or quirky responses charge 1 credit minimum. */
    private const MIN_CREDITS = 1;

    public function __construct(
        private readonly AiToolRegistry $registry,
        private readonly ContextBuilder $context,
        private readonly ClientActivityLogger $activity,
    ) {
    }

    /**
     * Public entry point.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $toolId, Website $website, ?string $userId, array $input): AiToolResult
    {
        $tool = $this->registry->find($toolId);
        if (! $tool) {
            return AiToolResult::fail('unknown_tool', "Tool '{$toolId}' is not registered.");
        }

        $meta = $tool->meta();

        // Pro-only tools are gated through the plan-driven feature
        // matrix: AI Studio entries are part of the `ai_writer` flag.
        // The controller decorates the failure response with the
        // tier/required_tier/feature triple so the WP plugin can render
        // a contextual "Upgrade to <plan>" CTA.
        if ($meta->requiresPro) {
            $effective = $website->effectiveFeatureFlags();
            if (($effective['ai_writer'] ?? false) !== true) {
                return AiToolResult::fail(
                    error: 'tier_required',
                    message: 'AI Studio is available on Pro. Upgrade to unlock.',
                    outputType: $meta->outputType,
                );
            }
        }

        $validated = $this->validate($meta, $input);
        if ($validated instanceof AiToolResult) {
            return $validated;
        }

        $cacheKey = $meta->cacheTtlSeconds && $meta->cacheTtlSeconds > 0
            ? $this->cacheKey($website, $toolId, $validated)
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if ($cached instanceof AiToolResult) {
                $this->logCredits($website, $userId, $toolId, $cached, true);
                return new AiToolResult(
                    ok: $cached->ok,
                    outputType: $cached->outputType,
                    value: $cached->value,
                    usage: $cached->usage,
                    cached: true,
                    model: $cached->model,
                    generatedAt: $cached->generatedAt,
                    diagnostics: $cached->diagnostics,
                );
            }
        }

        $context = $this->context->build($meta, $website, $userId, $validated);

        try {
            $result = $tool->execute($validated, $context);
        } catch (\Throwable $e) {
            \Log::warning('AiToolRunner: tool threw', [
                'tool' => $toolId,
                'website_id' => $website->id,
                'msg' => $e->getMessage(),
            ]);
            return AiToolResult::fail('execution_error', 'The tool failed unexpectedly. Try again.', $meta->outputType);
        }

        if ($result->ok && $cacheKey !== null) {
            Cache::put($cacheKey, $result, $meta->cacheTtlSeconds);
        }

        $this->logCredits($website, $userId, $toolId, $result, false);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|AiToolResult           validated input or a fail result
     */
    private function validate(AiToolMeta $meta, array $input): array|AiToolResult
    {
        $out = [];
        foreach ($meta->inputs as $field) {
            /** @var InputField $field */
            $raw = $input[$field->key] ?? null;

            if ($raw === null || $raw === '' || $raw === []) {
                if ($field->required) {
                    return AiToolResult::fail(
                        error: 'invalid_input',
                        message: "Missing required field: {$field->label}.",
                        outputType: $meta->outputType,
                    );
                }
                if ($field->default !== null) {
                    $out[$field->key] = $field->default;
                }
                continue;
            }

            $value = match ($field->type) {
                'number' => is_numeric($raw) ? (int) $raw : null,
                'tags' => $this->normalizeTags($raw),
                'post_picker' => is_array($raw) ? $raw : null,
                default => is_string($raw) ? trim($raw) : (is_array($raw) ? $raw : (string) $raw),
            };

            if ($value === null) {
                return AiToolResult::fail('invalid_input', "Field '{$field->label}' has the wrong type.", $meta->outputType);
            }

            if (is_string($value) && $field->maxLength !== null && mb_strlen($value) > $field->maxLength) {
                $value = mb_substr($value, 0, $field->maxLength);
            }

            if ($field->type === 'select' && is_array($field->options)) {
                $allowed = array_map(static fn (array $o) => (string) $o['value'], $field->options);
                if (! in_array((string) $value, $allowed, true)) {
                    return AiToolResult::fail('invalid_input', "Field '{$field->label}' must be one of the listed options.", $meta->outputType);
                }
            }

            $out[$field->key] = $value;
        }

        // Pass-through extras (focus_keyword, country, language, url) that
        // tools may reference even if not in their inputs (e.g. context
        // hints from the plugin).
        foreach (['focus_keyword', 'country', 'language', 'url', 'post', 'current_html'] as $extra) {
            if (! isset($out[$extra]) && isset($input[$extra])) {
                $out[$extra] = $input[$extra];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,\n]+/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            $raw,
        ), static fn ($v) => $v !== ''));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function cacheKey(Website $website, string $toolId, array $input): string
    {
        $hash = hash('xxh3', json_encode($input) ?: '');
        // v2 namespace (2026-07-11): `language` was always part of the
        // input hash, but tools IGNORED it until the AbstractAiTool
        // output-language rule — so English outputs sat cached under
        // localized keys. The bump orphans every pre-fix entry at once.
        return sprintf('ai_tool:v2:%s:%s:%s', $toolId, $website->id, $hash);
    }

    private function logCredits(Website $website, ?string $userId, string $toolId, AiToolResult $result, bool $cached): void
    {
        if (! $result->ok) {
            return;
        }

        $totalTokens = (int) ($result->usage['total'] ?? 0);
        $credits = $totalTokens > 0
            ? max(self::MIN_CREDITS, (int) ceil($totalTokens / self::DEFAULT_TOKENS_PER_CREDIT))
            : ($cached ? 0 : self::MIN_CREDITS);

        if ($credits <= 0) {
            return;
        }

        $type = 'credit_usage.ai_tool.' . $toolId;

        $this->activity->log(
            type: $type,
            userId: $userId,
            websiteId: $website->id,
            provider: CreditTypes::PROVIDER,
            meta: [
                'tool_id' => $toolId,
                'cached' => $cached,
                'tokens' => $totalTokens,
            ],
            unitsConsumed: $credits,
        );
    }
}
