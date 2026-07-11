<?php

namespace App\Services\Llm;

/**
 * Mistral chat-completions client. Defaults to mistral-small-latest
 * (currently Mistral Small 3.2) — cheap, fast, EU-hosted, native JSON
 * output mode. Used by `TopicalGapService` and any other extraction-style
 * EBQ feature.
 *
 * All behavior lives in OpenAiCompatibleClient; this class only pins the
 * endpoint and the 'mistral' provider key (which keeps every error code —
 * `mistral_http_*`, `mistral_network_error`, `mistral_api_key_missing` —
 * byte-identical to the pre-refactor client; downstream copy mappers and
 * stored activity rows depend on them).
 */
final class MistralClient extends OpenAiCompatibleClient
{
    private const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';
    public const DEFAULT_MODEL = 'mistral-small-latest';

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
        return 'mistral';
    }
}
