<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Which provider backs the platform LLM (App\Services\Llm\LlmClient).
 * Two coexist:
 *
 *   - `mistral`  — the original provider (EU-hosted, has vision via Pixtral).
 *   - `deepseek` — cheaper per token; no vision model.
 *
 * Stored in the `Setting` table so an admin can flip it live from the
 * platform settings page. Defaults to Mistral so behaviour is unchanged
 * until an admin opts in. Token spend from both providers counts against
 * the single `mistral.monthly_tokens` plan cap (see UsageMeter::LLM_POOL).
 */
class LlmProviderConfig
{
    public const PROVIDER_MISTRAL = 'mistral';
    public const PROVIDER_DEEPSEEK = 'deepseek';

    public const SETTING_KEY = 'ai.llm.provider';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_MISTRAL,
        self::PROVIDER_DEEPSEEK,
    ];

    public static function currentProvider(): string
    {
        $value = (string) Setting::get(self::SETTING_KEY, self::PROVIDER_MISTRAL);

        return in_array($value, self::PROVIDERS, true)
            ? $value
            : self::PROVIDER_MISTRAL;
    }

    public static function setProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            $provider = self::PROVIDER_MISTRAL;
        }
        Setting::set(self::SETTING_KEY, $provider);
    }

    /** True when the provider's API key is configured. */
    public static function isConfigured(string $provider): bool
    {
        return (string) config("services.{$provider}.key", '') !== '';
    }

    /** @return array<string, string> value => human label, for the admin select. */
    public static function options(): array
    {
        return [
            self::PROVIDER_MISTRAL => 'Mistral',
            self::PROVIDER_DEEPSEEK => 'DeepSeek',
        ];
    }
}
