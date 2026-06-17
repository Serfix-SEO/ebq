<?php

namespace App\Services\Llm;

use App\Models\User;
use App\Services\ClientActivityLogger;
use App\Services\Usage\UsageMeter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mistral chat-completions client. Defaults to mistral-small-latest
 * (currently Mistral Small 3.2) — cheap, fast, EU-hosted, native JSON
 * output mode. Used by `TopicalGapService` and any other extraction-style
 * EBQ feature.
 *
 * Failure mode is "return ok:false" — never throw from here so the call
 * sites can fall back to non-LLM behavior without crashing the editor.
 */
final class MistralClient implements LlmClient
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';
    private const DEFAULT_MODEL = 'mistral-small-latest';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = self::DEFAULT_MODEL,
    ) {}

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(array $messages, array $options = []): array
    {
        if (! $this->isAvailable()) {
            return [
                'ok' => false,
                'content' => '',
                'model' => '',
                'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
                'error' => 'mistral_api_key_missing',
            ];
        }

        $model = (string) ($options['model'] ?? $this->defaultModel);
        // Ceiling raised from 60→300s. JSON-mode requests are slower than
        // free-text (token-by-token grammar checking), and large-output
        // tasks like the AI Writer (up to 20 sections, 16k output tokens)
        // can run 2–4 minutes wall time. Floor stays at 2s; nobody calls
        // intentionally short. Per-call sites pass their own `timeout`,
        // so this is just a safety ceiling.
        $timeout = max(2, min(300, (int) ($options['timeout'] ?? 12)));

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
        ];
        if (! empty($options['max_tokens'])) {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }
        if (! empty($options['json_object'])) {
            // Mistral mirrors OpenAI's response_format contract.
            $body['response_format'] = ['type' => 'json_object'];
        }
        // Function calling: Mistral supports OpenAI-style tools array. When
        // present we also surface the raw assistant message back to the
        // caller (so the tool-loop can replay it into the next request).
        if (! empty($options['tools']) && is_array($options['tools'])) {
            $body['tools'] = array_values($options['tools']);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        // Per-user monthly token cap. Caller may pass __user_id /
        // __website_id explicitly (queue jobs, webhook handlers); during
        // a request the Auth user is the implicit fallback. Estimate the
        // pre-flight ceiling as prompt-chars / 4 + max_tokens (Mistral
        // tokenises ~4 chars per token for English).
        $billedUser = $this->resolveBilledUser($options);
        if ($billedUser !== null) {
            $estimated = $this->estimateTokens($messages, (int) ($body['max_tokens'] ?? 2048));
            app(UsageMeter::class)->assertCanSpend($billedUser, 'mistral', $estimated);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->retry(2, 250, throw: false)
                ->post(self::ENDPOINT, $body);
        } catch (\Throwable $e) {
            Log::warning('Mistral request threw', ['msg' => $e->getMessage()]);
            return [
                'ok' => false,
                'content' => '',
                'model' => $model,
                'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
                'error' => 'mistral_network_error',
            ];
        }

        if (! $response->successful()) {
            Log::warning('Mistral non-2xx', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);
            return [
                'ok' => false,
                'content' => '',
                'model' => $model,
                'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
                'error' => 'mistral_http_' . $response->status(),
            ];
        }

        $json = (array) $response->json();
        $message = (array) ($json['choices'][0]['message'] ?? []);
        $choice = $message['content'] ?? '';
        $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        $usage = (array) ($json['usage'] ?? []);
        $totalTokens = (int) ($usage['total_tokens'] ?? 0);

        // Log actual token spend so admin Usage page and UsageMeter both
        // see real billed usage (the pre-flight estimate above is just a
        // ceiling check — this is the truth).
        if ($billedUser !== null && $totalTokens > 0) {
            $meta = [
                'operation'         => 'chat_completion',
                'model'             => (string) ($json['model'] ?? $model),
                'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ];
            $source = isset($options['__source']) ? (string) $options['__source'] : null;
            if ($source !== null && $source !== '') {
                $meta['source'] = $source;
            }
            app(ClientActivityLogger::class)->log(
                'api_usage.mistral',
                userId: $billedUser->id,
                websiteId: isset($options['__website_id']) ? (string) $options['__website_id'] : null,
                provider: 'mistral',
                meta: $meta,
                unitsConsumed: $totalTokens,
            );
        }

        return [
            'ok' => true,
            'content' => (string) $choice,
            'model' => (string) ($json['model'] ?? $model),
            'usage' => [
                'prompt' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion' => (int) ($usage['completion_tokens'] ?? 0),
                'total' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'tool_calls' => $toolCalls,
            'message' => $message,
        ];
    }

    public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
    {
        $maxRounds = max(1, min(6, (int) ($options['max_tool_rounds'] ?? 4)));
        $callOptions = $options;
        $callOptions['tools'] = $tools;
        // Stripping json_object — JSON-mode + tools is supported by Mistral but
        // the reliability benefit isn't worth it; we control output shape via
        // the system prompt and parse the final content tolerantly.
        unset($callOptions['json_object']);

        $current = $messages;
        $log = [];
        $totalUsage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $lastModel = $this->defaultModel;

        for ($round = 0; $round < $maxRounds; $round++) {
            $resp = $this->complete($current, $callOptions);
            if (! ($resp['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'decoded' => null,
                    'content' => '',
                    'model' => (string) ($resp['model'] ?? $lastModel),
                    'usage' => $totalUsage,
                    'tool_calls' => $log,
                    'error' => (string) ($resp['error'] ?? 'mistral_failed'),
                ];
            }

            $lastModel = (string) ($resp['model'] ?? $lastModel);
            foreach (['prompt', 'completion', 'total'] as $k) {
                $totalUsage[$k] += (int) ($resp['usage'][$k] ?? 0);
            }

            $assistantMessage = is_array($resp['message'] ?? null) ? $resp['message'] : [];
            $toolCalls = is_array($resp['tool_calls'] ?? null) ? $resp['tool_calls'] : [];

            // Final answer — no more tool calls. Decode JSON and return.
            if (empty($toolCalls)) {
                $raw = (string) ($resp['content'] ?? '');
                $decoded = $this->tolerantJsonDecode($raw);
                if ($decoded === null) {
                    Log::warning('Mistral completeWithTools: parse failed', [
                        'model' => $lastModel,
                        'raw_preview' => mb_substr($raw, 0, 800),
                    ]);
                }
                return [
                    'ok' => true,
                    'decoded' => $decoded,
                    'content' => $raw,
                    'model' => $lastModel,
                    'usage' => $totalUsage,
                    'tool_calls' => $log,
                ];
            }

            // Replay assistant turn so the tool result is anchored to it.
            $current[] = [
                'role' => 'assistant',
                'content' => (string) ($assistantMessage['content'] ?? ''),
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $argsRaw = (string) ($call['function']['arguments'] ?? '{}');
                $args = json_decode($argsRaw, true);
                if (! is_array($args)) {
                    $args = [];
                }

                try {
                    $toolResult = $dispatcher($name, $args);
                } catch (\Throwable $e) {
                    Log::warning('Mistral tool dispatcher threw', [
                        'tool' => $name,
                        'msg' => $e->getMessage(),
                    ]);
                    $toolResult = ['error' => 'tool_failed', 'message' => $e->getMessage()];
                }

                $log[] = [
                    'name' => $name,
                    'args' => $args,
                    'ok' => ! (is_array($toolResult) && isset($toolResult['error'])),
                ];

                $current[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'name' => $name,
                    'content' => is_string($toolResult)
                        ? $toolResult
                        : (string) json_encode($toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return [
            'ok' => false,
            'decoded' => null,
            'content' => '',
            'model' => $lastModel,
            'usage' => $totalUsage,
            'tool_calls' => $log,
            'error' => 'max_tool_rounds_exceeded',
        ];
    }

    public function completeJson(array $messages, array $options = []): ?array
    {
        $options['json_object'] = true;
        $result = $this->complete($messages, $options);
        if (! ($result['ok'] ?? false)) {
            Log::warning('Mistral completeJson: complete() returned ok=false', [
                'error' => $result['error'] ?? 'unknown',
                'model' => $result['model'] ?? '',
            ]);
            return null;
        }

        $raw = (string) $result['content'];
        $decoded = $this->tolerantJsonDecode($raw);
        if ($decoded === null) {
            Log::warning('Mistral completeJson: parse failed', [
                'model' => $result['model'] ?? '',
                'raw_preview' => mb_substr($raw, 0, 800),
                'raw_length' => mb_strlen($raw),
                'usage' => $result['usage'] ?? null,
            ]);
        }
        return $decoded;
    }

    /**
     * Tolerates the four ways an LLM-in-JSON-mode can still return text
     * that the standard `json_decode` chokes on:
     *
     *   1. Markdown code fences: ```json\n{...}\n```
     *   2. Leading/trailing commentary ("Sure, here's the JSON: {...}")
     *   3. Bare object embedded in a longer string
     *   4. Trailing commas (not strict JSON but common in LLM output)
     *
     * Strategy: try strict decode → strip code fences → extract first
     * balanced `{...}` block → strip trailing commas → strict decode again.
     * Returns null only when no recoverable JSON object can be found.
     */
    private function tolerantJsonDecode(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') return null;

        // (1) Strict decode happy path.
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) return $decoded;

        // (2) Strip markdown code fences (```json … ``` or ``` … ```).
        $stripped = preg_replace('/^```(?:json|JSON)?\s*\n?(.+?)\n?```$/sm', '$1', $trimmed);
        if (is_string($stripped) && $stripped !== $trimmed) {
            $decoded = json_decode(trim($stripped), true);
            if (is_array($decoded)) return $decoded;
            $trimmed = trim($stripped);
        }

        // (3) Extract the first balanced { ... } block (handles leading
        //     commentary like "Here you go: { ... }").
        $start = strpos($trimmed, '{');
        if ($start === false) return null;
        $depth = 0;
        $inString = false;
        $escape = false;
        $end = -1;
        $len = strlen($trimmed);
        for ($i = $start; $i < $len; $i++) {
            $ch = $trimmed[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $inString) { $escape = true; continue; }
            if ($ch === '"') { $inString = ! $inString; continue; }
            if ($inString) continue;
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end === -1) return null;
        $candidate = substr($trimmed, $start, $end - $start + 1);

        // (4) Strip trailing commas before } or ] — common LLM artifact.
        $candidate = preg_replace('/,(\s*[\]}])/', '$1', $candidate) ?? $candidate;

        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve the billed user for usage gating. Caller may pass
     * `__user_id` (and optionally `__website_id`) in options; otherwise
     * we fall back to the authenticated user. Returns null for unauth'd
     * background contexts where there's no one to bill (e.g. internal
     * housekeeping jobs).
     */
    private function resolveBilledUser(array $options): ?User
    {
        $userId = isset($options['__user_id']) ? (string) $options['__user_id'] : Auth::id();
        if (($userId === null || $userId === '')) {
            return null;
        }
        return User::find($userId);
    }

    /**
     * Conservative pre-flight token estimate. Sums message content
     * length (chars/4 ≈ tokens for English) and adds the requested
     * max_tokens ceiling.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function estimateTokens(array $messages, int $maxOutputTokens): int
    {
        $promptChars = 0;
        foreach ($messages as $m) {
            $content = $m['content'] ?? '';
            if (is_string($content)) {
                $promptChars += strlen($content);
            }
        }
        return (int) ceil($promptChars / 4) + max(0, $maxOutputTokens);
    }
}
