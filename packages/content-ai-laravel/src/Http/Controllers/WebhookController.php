<?php

namespace Serfix\ContentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Serfix\ContentAi\Services\ArticleImporter;

/**
 * Receives Content AI deliveries. Signature + replay checks already ran in
 * VerifyWebhookSignature middleware, so anything reaching here is authentic.
 *
 * The reply matters as much as the storing: Serfix records `id` and `url` from
 * our JSON, uses the id to route later edits through update() instead of
 * re-publishing, and uses the url to link and verify the live page. Answering
 * a bare 200 works, but leaves the publisher with no link to your article.
 */
class WebhookController
{
    public function __invoke(Request $request, ArticleImporter $importer): JsonResponse
    {
        $event = (string) $request->input('event', '');

        // Connection test from the Connect-publishing screen.
        if ($event === 'verify') {
            return response()->json(['ok' => true, 'message' => 'Content AI webhook reachable.']);
        }

        if (! in_array($event, ['article.published', 'article.updated', 'article.unpublished'], true)) {
            return response()->json(['error' => 'Unsupported event: '.$event], 422);
        }

        if ($event === 'article.unpublished') {
            $article = $importer->unpublish(
                (string) $request->input('external_id', ''),
                $request->input('article.slug')
            );

            return response()->json(['ok' => true, 'id' => $article?->id]);
        }

        if (! is_array($request->input('article'))) {
            return response()->json(['error' => 'Missing article payload.'], 422);
        }

        try {
            $article = $importer->import((array) $request->all());
        } catch (\Throwable $e) {
            // 500 (not 200) so Serfix retries — PublishArticleJob owns the
            // backoff, and swallowing the error here would silently lose posts.
            Log::error('content-ai.import_failed', ['error' => $e->getMessage(), 'event' => $event]);

            return response()->json(['error' => 'Import failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'id' => (string) $article->id,
            'url' => $article->url(),
            'status' => $article->status,
        ]);
    }
}
