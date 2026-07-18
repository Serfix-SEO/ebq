<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentIntegration;

/** Resolves the driver for an integration's platform, or null if unsupported. */
class PublishDriverFactory
{
    public function for(ContentIntegration $integration): ?PublishDriver
    {
        return match ($integration->platform) {
            ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD => app(WordPressAppPasswordDriver::class),
            ContentIntegration::PLATFORM_WEBHOOK => app(WebhookDriver::class),
            // PLATFORM_WORDPRESS (our plugin's v2.1 receive endpoint) and
            // PLATFORM_SHOPIFY land in later phases.
            default => null,
        };
    }
}
