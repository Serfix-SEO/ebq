<?php

namespace App\Services\Llm;

use App\Support\AiModelConfig;
use App\Support\LlmProviderConfig;

/**
 * Builds the concrete LlmClient for a provider (null = the admin-selected
 * active provider). Used by the container binding in AppServiceProvider
 * and by call sites that need a specific provider regardless of the
 * platform setting (e.g. the block editor's alt-text vision path, which
 * pins Mistral because DeepSeek has no vision model).
 */
class LlmClientFactory
{
    public static function make(?string $provider = null): LlmClient
    {
        $provider ??= LlmProviderConfig::currentProvider();

        $apiKey = (string) config("services.{$provider}.key", '');
        $model = AiModelConfig::currentModel($provider);

        return match ($provider) {
            LlmProviderConfig::PROVIDER_DEEPSEEK => new DeepSeekClient($apiKey, $model),
            default => new MistralClient($apiKey, $model),
        };
    }
}
