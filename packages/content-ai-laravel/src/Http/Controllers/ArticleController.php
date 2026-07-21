<?php

namespace Serfix\ContentAi\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Services\MetaBuilder;
use Serfix\ContentAi\Services\SchemaBuilder;

class ArticleController
{
    /** @var class-string<Article> */
    private string $model;

    public function __construct()
    {
        $this->model = config('content-ai.models.article', Article::class);
    }

    public function index(): View
    {
        $articles = $this->model::query()
            ->published()
            ->latest('published_at')
            ->paginate((int) config('content-ai.route.per_page', 12));

        return view(config('content-ai.views.index', 'content-ai::index'), compact('articles'));
    }

    public function show(Request $request, string $slug, MetaBuilder $meta, SchemaBuilder $schema): View
    {
        $article = $this->model::query()->where('slug', $slug)->firstOrFail();

        // Unpublished articles are viewable ONLY through a signed preview link,
        // so an unlisted draft cannot be discovered by guessing slugs.
        abort_unless($article->isPublished() || $request->hasValidSignature(), 404);

        return view(config('content-ai.views.show', 'content-ai::show'), [
            'article' => $article,
            'meta' => $meta->for($article),
            'schema' => $schema->toJson($article),
        ]);
    }

    /** Shareable, expiring link to an unpublished draft. */
    public function previewUrl(Article $article): string
    {
        return URL::temporarySignedRoute(
            config('content-ai.route.name_prefix', 'content-ai.').'show',
            now()->addMinutes((int) config('content-ai.publishing.preview_ttl', 1440)),
            ['slug' => $article->slug]
        );
    }

    /** RSS 2.0 — cheap distribution, and it keeps feed readers off the HTML pages. */
    public function feed(): Response
    {
        $articles = $this->model::query()->published()->latest('published_at')->limit(50)->get();
        $prefix = trim((string) config('content-ai.route.prefix', 'blog'), '/');

        $items = $articles->map(fn (Article $a) => sprintf(
            '<item><title>%s</title><link>%s</link><guid isPermaLink="true">%s</guid>'
            .'<description>%s</description><pubDate>%s</pubDate></item>',
            e($a->title),
            e($a->url()),
            e($a->url()),
            e($a->summary()),
            $a->published_at?->toRfc2822String() ?? ''
        ))->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            .'<title>'.e((string) config('content-ai.seo.site_name')).'</title>'
            .'<link>'.e(url($prefix)).'</link>'
            .'<description>'.e((string) config('content-ai.seo.site_name')).'</description>'
            .$items
            .'</channel></rss>';

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * Standalone sitemap for the blog. Hosts already running a sitemap package
     * can ignore this and query Article::published() instead.
     */
    public function sitemap(): Response
    {
        $articles = $this->model::query()->published()->latest('published_at')->get();

        $urls = $articles->map(fn (Article $a) => sprintf(
            '<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>weekly</changefreq></url>',
            e($a->url()),
            e($a->updated_at?->toAtomString() ?? '')
        ))->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
