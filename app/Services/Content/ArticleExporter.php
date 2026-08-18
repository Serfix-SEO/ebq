<?php

namespace App\Services\Content;

use App\Models\ContentArticle;
use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\MarkdownAdapter;

/**
 * Hand an article to a client who has to move it themselves.
 *
 * Not every website can be published into. Site builders like Hostinger
 * Horizon, and any hand-built site, expose no content API — there is nothing
 * for a publish driver to call. Those clients were previously stuck: the
 * article existed in the review screen with no way to get it out except
 * selecting rendered text in the browser, which loses headings, links and
 * image references.
 *
 * Everything here derives from the article's CURRENT `html`. In particular the
 * Markdown is re-rendered rather than read from `content_articles.markdown` —
 * that column is copied verbatim by `ArticleReview::save()` and so still holds
 * the pre-edit text after any client edit.
 */
class ArticleExporter
{
    public const FORMAT_HTML = 'html';

    public const FORMAT_MARKDOWN = 'md';

    public function __construct(
        private readonly HtmlBlockParser $parser,
        private readonly MarkdownAdapter $markdown,
    ) {}

    /** @return array{filename:string, mime:string, body:string} */
    public function export(ContentArticle $article, string $format): array
    {
        $slug = $article->slug ?: \Illuminate\Support\Str::slug((string) $article->h1) ?: 'article';

        return $format === self::FORMAT_MARKDOWN
            ? ['filename' => $slug.'.md', 'mime' => 'text/markdown; charset=UTF-8', 'body' => $this->markdown($article)]
            : ['filename' => $slug.'.html', 'mime' => 'text/html; charset=UTF-8', 'body' => $this->html($article)];
    }

    /**
     * Body HTML with the H1 on top — what someone pastes into a builder's
     * rich-text/HTML block. No <html>/<head> wrapper: every destination
     * supplies its own page chrome, and a full document pasted into a CMS
     * editor becomes escaped text.
     */
    public function html(ContentArticle $article): string
    {
        $h1 = trim((string) $article->h1);
        $body = trim((string) $article->html);

        return ($h1 !== '' ? '<h1>'.e($h1).'</h1>'."\n" : '').$body."\n";
    }

    /** Markdown with the H1 as the document title. */
    public function markdown(ContentArticle $article): string
    {
        $h1 = trim((string) $article->h1);
        $body = $this->markdown->render($this->parser->parse((string) $article->html));

        return ($h1 !== '' ? '# '.$h1."\n\n" : '').$body;
    }
}
