<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Models\ContentArticle;
use App\Models\ContentTopic;
use App\Services\Content\ArticleExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Download / copy an article's body for a destination we cannot publish into
 * automatically (site builders with no content API — Hostinger Horizon and
 * friends — or any hand-built site).
 *
 * Serves the CURRENT version of the topic's article, so what the client copies
 * is exactly what the review screen shows, including their own edits.
 */
class ArticleExportController extends Controller
{
    public function __invoke(Request $request, string $topic, string $format, ArticleExporter $exporter)
    {
        abort_unless(
            in_array($format, [ArticleExporter::FORMAT_HTML, ArticleExporter::FORMAT_MARKDOWN], true),
            404,
        );

        // Same ownership scope as ArticleReview::topic() — a topic is reachable
        // only through a website this user can access (owned OR shared).
        $websiteIds = Auth::user()?->accessibleWebsitesQuery()->select('id');
        abort_if($websiteIds === null, 403);

        $topicModel = ContentTopic::query()
            ->whereKey($topic)
            ->whereIn('website_id', $websiteIds)
            ->first();
        abort_if($topicModel === null, 404);

        $article = ContentArticle::query()
            ->where('topic_id', $topicModel->id)
            ->where('is_current', true)
            ->first();
        abort_if($article === null, 404);

        ['filename' => $filename, 'mime' => $mime, 'body' => $body] = $exporter->export($article, $format);

        // `inline` when the caller only wants the text (the Copy buttons fetch
        // this URL), `attachment` for the Download buttons.
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            // Never let a proxy or the browser hold a stale copy of an article
            // the client is actively editing.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
