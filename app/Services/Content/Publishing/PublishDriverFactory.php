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
            ContentIntegration::PLATFORM_SHOPIFY => app(ShopifyDriver::class),
            ContentIntegration::PLATFORM_HUBSPOT => app(HubSpotDriver::class),
            ContentIntegration::PLATFORM_WEBFLOW => app(WebflowDriver::class),
            // PLATFORM_WORDPRESS (our plugin's v2.1 receive endpoint) is
            // still deferred.
            default => null,
        };
    }
}
