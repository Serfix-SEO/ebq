<?php

namespace App\Services\Content\Publishing;

/**
 * Outcome of a driver publish/update/verify call. `ok=false` + `transient=true`
 * means the caller may retry (5xx/network); `transient=false` is a hard error
 * (bad credentials, 4xx) that retrying won't fix.
 */
final class PublishResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $error = null,
        public readonly bool $transient = false,
        public readonly array $response = [],
    ) {}

    public static function success(?string $externalId = null, ?string $externalUrl = null, array $response = []): self
    {
        return new self(true, $externalId, $externalUrl, null, false, $response);
    }

    public static function failure(string $error, bool $transient = false, array $response = []): self
    {
        return new self(false, null, null, $error, $transient, $response);
    }
}
