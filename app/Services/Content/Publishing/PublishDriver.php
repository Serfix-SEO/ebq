<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentArticle;
use App\Models\ContentIntegration;

/**
 * Contract every Content Autopilot publish driver implements. Drivers are
 * stateless; credentials/config come from the ContentIntegration row
 * (credentials are an encrypted cast — never log them).
 *
 * Idempotency is the CALLER's job (PublishArticleJob claims the unique
 * content_publications row first); a driver's only idempotency duty is to
 * route through update() when an external_id already exists, so retries
 * never double-post.
 */
interface PublishDriver
{
    /** Cheap credentials check used by the connect UI. */
    public function verify(ContentIntegration $integration): PublishResult;

    /** Create the post/entry on the remote platform. */
    public function publish(ContentArticle $article, ContentIntegration $integration): PublishResult;

    /** Idempotent re-push of an already-published article. */
    public function update(ContentArticle $article, ContentIntegration $integration, string $externalId): PublishResult;
}
