<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Platform-wide AI model selection, per provider. Admin picks the active
 * provider (LlmProviderConfig) and a default chat model for each; every
 * service that doesn't pass an explicit `model` option in its LLM call
 * picks up the active provider's model.
 *
 * Persistence: Setting `ai.llm.model` for Mistral (legacy key, predates
 * multi-provider — the prod row keeps working) and `ai.llm.model.deepseek`
 * for DeepSeek. Reads fall back to config('services.{provider}.model'),
 * then to the provider's built-in default.
 *
 * Model lists: pulled live from each provider's /models endpoint (cached
 * for an hour so the admin page doesn't burn rate-limit quota on every
 * load), with known-good fallbacks when the API isn't reachable so the
 * dropdowns are never empty.
 */
class AiModelConfig
{
    /** Legacy Mistral setting key — keep, prod rows use it. */
    public const SETTING_KEY = 'ai.llm.model';
    public const SETTING_KEY_DEEPSEEK = 'ai.llm.model.deepseek';

    private const MODELS_ENDPOINTS = [
        'mistral' => 'https://api.mistral.ai/v1/models',
        'deepseek' => 'https://api.deepseek.com/models',
    ];

    /** Cache TTL for the live models lists (per provider). */
    private const MODELS_CACHE_PREFIX = 'ai_model_config:available_models:v3:';
    private const MODELS_CACHE_TTL_SEC = 3600;

    /**
     * DeepSeek reasoner-family aliases always run in thinking mode —
     * fine per-call, but as the PLATFORM DEFAULT they'd add reasoning
     * latency + token cost to every small interactive call (block
     * editor, chat, classifiers), so they're never selectable. Thinking
     * is instead opted into per call site via the `reasoning` option
     * (see DeepSeekClient / AiWriterService). Substring match so future
     * variants stay filtered without a code change.
     */
    private const DEEPSEEK_MODEL_DENYLIST_SUBSTRINGS = ['reasoner'];

    /**
     * Known-good fallbacks when the API key is missing or the provider is
     * unreachable. Keeps the admin dropdowns usable rather than empty.
     *
     * Each entry is `[id, human-readable-name]`. The Mistral list is
     * padded with both the rolling `*-latest` aliases AND specific dated
     * IDs so admins can pin to a precise model (e.g. "Mistral Small 3.2" =
     * `mistral-small-2506`) without depending on the live API call.
     */
    private const FALLBACK_MODELS = [
        'mistral' => [
            ['mistral-small-latest',  'Mistral Small (latest alias)'],
            ['mistral-small-2506',    'Mistral Small 3.2'],
            ['mistral-small-2503',    'Mistral Small 3.1'],
            ['mistral-small-2501',    'Mistral Small 3'],
            ['mistral-medium-latest', 'Mistral Medium (latest alias)'],
            ['mistral-medium-2508',   'Mistral Medium 3.1'],
            ['mistral-medium-2505',   'Mistral Medium 3'],
            ['mistral-large-latest',  'Mistral Large (latest alias)'],
            ['mistral-large-2411',    'Mistral Large 2.1'],
            ['mistral-large-2407',    'Mistral Large 2'],
            ['ministral-3b-latest',   'Ministral 3B'],
            ['ministral-8b-latest',   'Ministral 8B'],
            ['magistral-medium-latest', 'Magistral Medium (reasoning, latest alias)'],
            ['magistral-small-latest',  'Magistral Small (reasoning, latest alias)'],
            ['codestral-latest',      'Codestral (latest alias)'],
            ['codestral-2508',        'Codestral 25.08'],
            ['pixtral-large-latest',  'Pixtral Large (vision, latest alias)'],
            ['pixtral-12b-2409',      'Pixtral 12B'],
            ['open-mistral-7b',       'Open Mistral 7B'],
            ['open-mixtral-8x7b',     'Open Mixtral 8x7B'],
            ['open-mixtral-8x22b',    'Open Mixtral 8x22B'],
        ],
        'deepseek' => [
            ['deepseek-chat',     'DeepSeek Chat (rolling alias)'],
            ['deepseek-v4-flash', 'DeepSeek V4 Flash'],
            ['deepseek-v4-pro',   'DeepSeek V4 Pro'],
        ],
    ];

    /** Provider default when neither Setting nor config supply a model. */
    private const DEFAULT_MODELS = [
        'mistral' => 'mistral-small-latest',
        'deepseek' => 'deepseek-chat',
    ];

    /**
     * Higher-quality model for the few call sites that used to hardcode
     * `mistral-medium-latest` (block-editor title, snippet rewriter,
     * related keywords). DeepSeek has no mid tier — deepseek-chat is it.
     */
    private const PREMIUM_MODELS = [
        'mistral' => 'mistral-medium-latest',
        'deepseek' => 'deepseek-chat',
    ];

    /** Vision-capable model per provider; null = provider has none. */
    private const VISION_MODELS = [
        'mistral' => 'pixtral-12b-latest',
        'deepseek' => null,
    ];

    /**
     * The model the LlmClient should default to for a provider (null =
     * the active provider). Resolution order:
     *   1. Admin-selected Setting row (per-provider key)
     *   2. config('services.{provider}.model') — the .env-driven default
     *   3. Provider literal (mistral-small-latest / deepseek-chat)
     */
    public static function currentModel(?string $provider = null): string
    {
        $provider = self::normalizeProvider($provider);

        $stored = Setting::get(self::settingKeyFor($provider));
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        $configured = (string) config("services.{$provider}.model", '');
        if ($configured !== '') {
            return $configured;
        }
        return self::DEFAULT_MODELS[$provider];
    }

    /**
     * Persist the admin's choice for a provider. Caller is expected to
     * have already validated $model against listAvailableModels() so we
     * don't accept arbitrary strings here.
     */
    public static function setModel(string $model, ?string $provider = null): void
    {
        Setting::set(self::settingKeyFor(self::normalizeProvider($provider)), $model);
    }

    /** Higher-quality tier for the ex-hardcoded call sites. */
    public static function premiumModel(?string $provider = null): string
    {
        return self::PREMIUM_MODELS[self::normalizeProvider($provider)];
    }

    /** Vision-capable model, or null when the provider has none. */
    public static function visionModel(?string $provider = null): ?string
    {
        return self::VISION_MODELS[self::normalizeProvider($provider)];
    }

    /**
     * List every chat-capable model the configured provider key has
     * access to. Cached for an hour per provider. Falls back to the
     * known-good static list when the API isn't reachable so the admin
     * form is never empty.
     *
     * Returned shape: list of ['id' => string, 'label' => string].
     *
     * @return list<array{id:string,label:string}>
     */
    public static function listAvailableModels(?string $provider = null): array
    {
        $provider = self::normalizeProvider($provider);

        $cacheKey = self::MODELS_CACHE_PREFIX.$provider;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $apiKey = (string) config("services.{$provider}.key", '');
        if ($apiKey === '') {
            return self::fallbackList($provider);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(8)
                ->get(self::MODELS_ENDPOINTS[$provider]);
        } catch (\Throwable $e) {
            Log::warning('AiModelConfig: models endpoint threw', ['provider' => $provider, 'msg' => $e->getMessage()]);
            return self::fallbackList($provider);
        }

        if (! $response->successful()) {
            Log::warning('AiModelConfig: models endpoint non-2xx', [
                'provider' => $provider,
                'status' => $response->status(),
                'body'   => mb_substr((string) $response->body(), 0, 200),
            ]);
            return self::fallbackList($provider);
        }

        $data = (array) ($response->json('data') ?? []);
        $shaped = [];
        $seen = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || self::isDenylisted($provider, $id)) {
                continue;
            }
            // Mistral exposes embedding models, moderation models, and
            // chat models through the same list — filter to chat-style
            // entries by checking the capabilities map when present.
            // (DeepSeek's /models has no capabilities field — every row
            // passes, which is correct: it only lists chat models.)
            $caps = is_array($row['capabilities'] ?? null) ? $row['capabilities'] : [];
            if (array_key_exists('completion_chat', $caps) && ! (bool) $caps['completion_chat']) {
                continue;
            }
            $deprecation = (string) ($row['deprecation'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));

            $primary = self::shapeRow($id, $name, $description, $deprecation);
            if (! isset($seen[$primary['id']])) {
                $shaped[] = $primary;
                $seen[$primary['id']] = true;
            }

            // Mistral's models endpoint groups versioned variants under
            // an `aliases` array on the rolling alias row (and vice
            // versa). Expand them as separate dropdown entries so an
            // admin can pin to "Mistral Small 3.2" (id
            // `mistral-small-2506`) instead of being stuck on the
            // moving `mistral-small-latest` target. (DeepSeek has no
            // aliases field — loop is a no-op there.)
            foreach ((array) ($row['aliases'] ?? []) as $aliasId) {
                if (! is_string($aliasId) || $aliasId === '' || isset($seen[$aliasId]) || self::isDenylisted($provider, $aliasId)) {
                    continue;
                }
                $shaped[] = self::shapeRow($aliasId, $name, $description, $deprecation);
                $seen[$aliasId] = true;
            }
        }

        usort($shaped, static fn (array $a, array $b) => strcmp($a['id'], $b['id']));

        if ($shaped === []) {
            return self::fallbackList($provider);
        }

        Cache::put($cacheKey, $shaped, self::MODELS_CACHE_TTL_SEC);
        return $shaped;
    }

    /**
     * Compose a `{id, label}` row for the dropdown. The label puts the
     * human-readable name first when the provider supplied one, then the
     * raw id in parens, then a short description tail. Deprecated
     * models get a "(deprecated)" suffix so admins don't pin to them.
     *
     * @return array{id:string,label:string}
     */
    private static function shapeRow(string $id, string $name, string $description, string $deprecation): array
    {
        $parts = [];
        if ($name !== '') {
            $parts[] = $name.' ('.$id.')';
        } else {
            $parts[] = $id;
        }
        if ($description !== '') {
            $parts[] = mb_substr($description, 0, 80);
        }
        if ($deprecation !== '') {
            $parts[] = '⚠ deprecated';
        }
        return [
            'id'    => $id,
            'label' => implode(' — ', $parts),
        ];
    }

    /** Clear the cached model lists — call after admin changes an API key. */
    public static function clearModelsCache(): void
    {
        foreach (array_keys(self::MODELS_ENDPOINTS) as $provider) {
            Cache::forget(self::MODELS_CACHE_PREFIX.$provider);
        }
    }

    private static function normalizeProvider(?string $provider): string
    {
        $provider ??= LlmProviderConfig::currentProvider();

        return in_array($provider, LlmProviderConfig::PROVIDERS, true)
            ? $provider
            : LlmProviderConfig::PROVIDER_MISTRAL;
    }

    private static function settingKeyFor(string $provider): string
    {
        return $provider === LlmProviderConfig::PROVIDER_DEEPSEEK
            ? self::SETTING_KEY_DEEPSEEK
            : self::SETTING_KEY;
    }

    private static function isDenylisted(string $provider, string $id): bool
    {
        if ($provider !== LlmProviderConfig::PROVIDER_DEEPSEEK) {
            return false;
        }
        foreach (self::DEEPSEEK_MODEL_DENYLIST_SUBSTRINGS as $needle) {
            if (str_contains($id, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{id:string,label:string}>
     */
    private static function fallbackList(string $provider): array
    {
        return array_map(
            static fn (array $row) => self::shapeRow($row[0], $row[1], '', ''),
            self::FALLBACK_MODELS[$provider],
        );
    }
}
