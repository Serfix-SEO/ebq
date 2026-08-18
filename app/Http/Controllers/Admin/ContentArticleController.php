<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentArticle;
use App\Models\ContentArticleFeedback;
use App\Models\ContentImage;
use App\Models\ContentTopic;
use App\Support\HtmlSanitizer;
use Illuminate\View\View;

/**
 * Read-only admin view of ONE client article.
 *
 * The client's own article page is scoped by `accessibleWebsitesQuery`
 * (owner/shared websites), and admins are NOT special-cased there — so before
 * this existed, following a piece of feedback to "the article" 404'd for the
 * team and the only way in was impersonation.
 *
 * Read-only on purpose: editing belongs to the client (or to an explicit
 * impersonation session), not to a side door in the admin panel.
 */
class ContentArticleController extends Controller
{
    public function show(ContentTopic $topic): View
    {
        $topic->load(['website.user', 'plan']);

        $article = ContentArticle::query()
            ->where('topic_id', $topic->id)
            ->where('is_current', true)
            ->first();

        return view('admin.content.article', [
            'topic' => $topic,
            'article' => $article,
            // Allow-list sanitized, NOT the client-side blocklist: clients edit
            // this HTML in TipTap and it is about to render inside an admin
            // session. See HtmlSanitizer::article().
            'bodyHtml' => $article ? HtmlSanitizer::article((string) $article->html) : '',
            'images' => $article
                ? ContentImage::query()->where('article_id', $article->id)->orderBy('created_at')->get()
                : collect(),
            // Every verdict on this article, so the admin sees what prompted
            // the click alongside the content itself.
            'feedback' => ContentArticleFeedback::query()
                ->where('topic_id', $topic->id)
                ->with('user:id,name,email')
                ->latest()
                ->get(),
            'versions' => ContentArticle::query()
                ->where('topic_id', $topic->id)
                ->orderByDesc('version')
                ->get(['id', 'version', 'is_current', 'seo_score', 'word_count', 'created_at']),
        ]);
    }
}
