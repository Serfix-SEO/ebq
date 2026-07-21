<?php

namespace Serfix\ContentAi\Services;

use Illuminate\Support\Str;
use Serfix\ContentAi\Events\ArticlePublished;
use Serfix\ContentAi\Events\ArticleReceived;
use Serfix\ContentAi\Events\ArticleUpdated;
use Serfix\ContentAi\Models\Article;

/**
 * Turns a Content AI webhook payload into a stored, renderable Article.
 *
 * Idempotency is the whole job. Serfix retries on any non-2xx, and the FIRST
 * delivery of an article carries no external_id (the publisher only learns our
 * id from the reply). So we resolve in that order:
 *
 *   external_id  → the publisher knows us; authoritative
 *   slug         → first delivery, or a retry whose reply we never sent
 *   otherwise    → create
 *
 * Matching on slug is what stops a lost 200 from creating duplicate posts.
 */
class ArticleImporter
{
    public function __construct(private readonly ImageLocalizer $images) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function import(array $payload): Article
    {
        $data = (array) ($payload['article'] ?? []);
        $externalId = $this->stringOrNull($payload['external_id'] ?? null);
        $slug = $this->resolveSlug($data);

        /** @var class-string<Article> $model */
        $model = config('content-ai.models.article', Article::class);

        $article = $this->locate($model, $externalId, $slug);
        $isNew = $article === null;
        $checksum = hash('sha256', json_encode($payload) ?: '');

        // Byte-identical re-delivery (a retry after our 200 was lost): nothing
        // to do, but still answer 200 so the publisher stops retrying.
        if (! $isNew && $article->checksum === $checksum) {
            return $article;
        }

        // A human edited this row and the host asked us to respect that.
        if (! $isNew
            && config('content-ai.publishing.preserve_local_edits', false)
            && $article->locally_edited_at !== null) {
            return $article;
        }

        $article ??= new $model;

        $attributes = [
            'external_id' => $externalId ?: $article->external_id,
            'slug' => $slug,
            'title' => $this->title($data),
            'h1' => $this->stringOrNull($data['h1'] ?? null),
            'markdown' => $this->stringOrNull($data['markdown'] ?? null),
            'excerpt' => $this->stringOrNull($data['excerpt'] ?? null),
            'meta_title' => $this->stringOrNull($data['meta_title'] ?? null),
            'meta_description' => $this->stringOrNull($data['meta_description'] ?? null),
            'canonical_url' => $this->stringOrNull($data['canonical_url'] ?? null),
            'language' => (string) ($data['language'] ?? 'en'),
            'target_keyword' => $this->stringOrNull($data['target_keyword'] ?? null),
            'secondary_keywords' => array_values((array) ($data['secondary_keywords'] ?? [])),
            'word_count' => (int) ($data['word_count'] ?? 0),
            'payload' => $payload,
            'checksum' => $checksum,
        ];

        if ($isNew) {
            $attributes['status'] = config('content-ai.publishing.auto_publish', true)
                ? Article::STATUS_PUBLISHED
                : Article::STATUS_DRAFT;
            $attributes['published_at'] = now();
        }

        // Save once WITHOUT html so the row has an id — images belong to an
        // article, and we cannot store them before the parent exists.
        $article->fill($attributes + ['html' => (string) ($data['html'] ?? '')])->save();

        // Mirror our own key into external_id on first import: that is the value
        // we hand back in the 200, and therefore the value the publisher will
        // send as external_id from now on. Storing it keeps later lookups on the
        // indexed column instead of the fallback above.
        if (blank($article->external_id)) {
            $article->forceFill(['external_id' => (string) $article->getKey()])->save();
        }

        $localized = $this->images->localize($article, (string) ($data['html'] ?? ''), (array) ($payload['images'] ?? []));
        $html = $localized['html'];

        if (config('content-ai.content.rewrite_internal_links', true)) {
            $html = $this->rewriteInternalLinks($html);
        }

        $article->forceFill(['html' => $html])->save();

        event(new ArticleReceived($article));
        event($isNew ? new ArticlePublished($article) : new ArticleUpdated($article));

        return $article->refresh();
    }

    /** Mark an article as pulled from the site without deleting the row. */
    public function unpublish(string $externalId, ?string $slug = null): ?Article
    {
        /** @var class-string<Article> $model */
        $model = config('content-ai.models.article', Article::class);
        $article = $this->locate($model, $externalId, $slug);
        $article?->forceFill(['status' => Article::STATUS_UNPUBLISHED])->save();

        return $article;
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @param class-string<Article> $model */
    private function locate(string $model, ?string $externalId, ?string $slug): ?Article
    {
        if ($externalId !== null && $externalId !== '') {
            $found = $model::query()->where('external_id', $externalId)->first();
            if ($found !== null) {
                return $found;
            }

            // Serfix has no id of its own to hand us: it stores whatever our 200
            // returned (`response['id'] ?? slug`) and sends THAT back as
            // external_id on the next edit. So a numeric value is our own key —
            // rows imported before we started mirroring it still resolve here.
            if (ctype_digit($externalId)) {
                $found = $model::query()->whereKey((int) $externalId)->first();
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $slug ? $model::query()->where('slug', $slug)->first() : null;
    }

    /**
     * Serfix slugs occasionally arrive with the keyword duplicated into the
     * title ("cool-pubg-names-cool-pubg-names-ideas"). Collapse a repeated
     * leading run rather than publish it — it is ugly and it dilutes the URL.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data): string
    {
        $slug = Str::slug((string) ($data['slug'] ?? '')) ?: Str::slug($this->title($data));
        $slug = $slug !== '' ? $slug : 'article-'.Str::random(8);

        $parts = explode('-', $slug);
        $count = count($parts);
        for ($len = intdiv($count, 2); $len >= 2; $len--) {
            if (array_slice($parts, 0, $len) === array_slice($parts, $len, $len)) {
                $parts = array_merge(array_slice($parts, 0, $len), array_slice($parts, $len * 2));
                $slug = implode('-', $parts);
                break;
            }
        }

        return Str::limit($slug, 180, '');
    }

    /** @param array<string, mixed> $data */
    private function title(array $data): string
    {
        return (string) ($data['h1'] ?? $data['meta_title'] ?? $data['title'] ?? 'Untitled');
    }

    private function rewriteInternalLinks(string $html): string
    {
        $prefix = trim((string) config('content-ai.route.prefix', 'blog'), '/');
        if ($prefix === '') {
            return $html;
        }

        // Serfix emits cross-article links as /{slug} or /blog/{slug}; point
        // them at the prefix THIS app is configured to serve from.
        return (string) preg_replace(
            '#href="/(?:blog/)?([a-z0-9][a-z0-9\-]{2,180})"#i',
            'href="/'.$prefix.'/$1"',
            $html
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
