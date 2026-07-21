<?php

namespace Serfix\ContentAi\Rendering;

use Illuminate\Support\Collection;
use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Services\MetaBuilder;
use Serfix\ContentAi\Services\SchemaBuilder;

/**
 * Renders an article as three drop-in chunks so a host can keep their own
 * design and simply place ours inside it:
 *
 *   <head>  {!! $serfix_head !!}        title, description, canonical, robots,
 *                                       Open Graph, Twitter card, JSON-LD
 *   <body>  {!! $serfix_body !!}        the article HTML (images already local)
 *           {!! $serfix_body_below !!}  related articles + anything that belongs
 *                                       after the content
 *
 * The "current" article is set by our controller, or by the host calling
 * `ContentAi::use($article)` when they render articles in their own views.
 * With no current article every chunk is an empty string, so the variables are
 * safe to leave in a shared layout used by every page on the site.
 */
class Renderer
{
    private ?Article $current = null;

    public function __construct(
        private readonly MetaBuilder $meta,
        private readonly SchemaBuilder $schema,
    ) {}

    public function use(?Article $article): static
    {
        $this->current = $article;

        return $this;
    }

    public function current(): ?Article
    {
        return $this->current;
    }

    /** Everything that belongs in <head>. */
    public function head(?Article $article = null): string
    {
        $article ??= $this->current;
        if ($article === null) {
            return '';
        }

        $m = $this->meta->for($article);

        $tags = [
            '<title>'.e($m['title']).'</title>',
            $this->tag('meta', ['name' => 'description', 'content' => $m['description']]),
            $this->tag('meta', ['name' => 'robots', 'content' => $m['robots']]),
            $this->tag('link', ['rel' => 'canonical', 'href' => $m['canonical']]),
        ];

        foreach ($m['og'] as $property => $content) {
            $tags[] = $this->tag('meta', ['property' => $property, 'content' => $content]);
        }

        foreach ($m['twitter'] as $name => $content) {
            $tags[] = $this->tag('meta', ['name' => $name, 'content' => $content]);
        }

        if (config('content-ai.render.schema_in', 'head') === 'head') {
            $tags[] = $this->schemaTag($article);
        }

        return implode("\n", array_filter($tags))."\n";
    }

    /**
     * The article body. Raw HTML by design — it is the whole product, and the
     * host is expected to style it with their own typography.
     */
    public function body(?Article $article = null): string
    {
        $article ??= $this->current;

        return $article === null ? '' : (string) $article->html;
    }

    /** Anything that belongs after the content: related articles, late JSON-LD. */
    public function bodyBelow(?Article $article = null): string
    {
        $article ??= $this->current;
        if ($article === null) {
            return '';
        }

        $out = '';

        $limit = (int) config('content-ai.render.related', 3);
        if ($limit > 0) {
            $related = $this->related($article, $limit);
            if ($related->isNotEmpty()) {
                $out .= view('content-ai::partials.related', [
                    'article' => $article,
                    'related' => $related,
                ])->render();
            }
        }

        if (config('content-ai.render.schema_in', 'head') === 'body_below') {
            $out .= $this->schemaTag($article);
        }

        return $out;
    }

    /**
     * Same-language siblings, newest first. Keyword overlap would be nicer, but
     * it costs a scan per request — the host can override this view/logic.
     *
     * @return Collection<int, Article>
     */
    public function related(Article $article, int $limit = 3)
    {
        /** @var class-string<Article> $model */
        $model = config('content-ai.models.article', Article::class);

        return $model::query()
            ->published()
            ->where('id', '!=', $article->getKey())
            ->inLanguage($article->language)
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    // ── internals ───────────────────────────────────────────────────────

    private function schemaTag(Article $article): string
    {
        $json = $this->schema->toJson($article);

        return $json === '' ? '' : '<script type="application/ld+json">'.$json.'</script>';
    }

    /** @param array<string, string> $attributes */
    private function tag(string $name, array $attributes): string
    {
        $attributes = array_filter($attributes, fn ($v) => $v !== null && $v !== '');
        if ($attributes === []) {
            return '';
        }

        $rendered = [];
        foreach ($attributes as $key => $value) {
            $rendered[] = $key.'="'.e($value).'"';
        }

        return '<'.$name.' '.implode(' ', $rendered).'>';
    }
}
