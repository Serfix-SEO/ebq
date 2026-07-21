<?php

namespace Serfix\ContentAi\Services;

use Serfix\ContentAi\Models\Article;

/**
 * Head tags for an article: title, description, canonical, Open Graph,
 * Twitter card, robots.
 *
 * Returned as a plain array so a host can render it with the bundled Blade
 * component, feed it to their own SEO package, or serialise it into an
 * Inertia/Livewire page — the package never assumes it owns the <head>.
 */
class MetaBuilder
{
    /**
     * @return array{title: string, description: string, canonical: string, robots: string,
     *               og: array<string, string>, twitter: array<string, string>}
     */
    public function for(Article $article): array
    {
        $title = $this->title($article);
        $description = $article->meta_description ?: $article->summary();
        $canonical = $this->canonical($article);
        $image = $article->featuredImage()?->url() ?? config('content-ai.seo.default_og_image');

        $og = array_filter([
            'og:type' => 'article',
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $canonical,
            'og:site_name' => (string) config('content-ai.seo.site_name'),
            'og:locale' => str_replace('-', '_', $article->language),
            'og:image' => $image,
            'article:published_time' => $article->published_at?->toIso8601String(),
            'article:modified_time' => $article->updated_at?->toIso8601String(),
        ]);

        $twitter = array_filter([
            'twitter:card' => $image ? 'summary_large_image' : 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
            'twitter:site' => config('content-ai.seo.twitter_site'),
        ]);

        return [
            'title' => $title,
            'description' => (string) $description,
            'canonical' => $canonical,
            // Drafts and unpublished articles must never be indexed — they are
            // reachable via signed preview links, and Google follows those too.
            'robots' => $article->isPublished()
                ? 'index, follow'
                : (string) config('content-ai.seo.robots_drafts', 'noindex, nofollow'),
            'og' => $og,
            'twitter' => $twitter,
        ];
    }

    private function title(Article $article): string
    {
        $title = $article->meta_title ?: $article->title;
        $suffix = config('content-ai.seo.title_suffix');

        return $suffix ? $title.' '.$suffix : $title;
    }

    /**
     * Serfix's canonical wins when present (it knows if the article also lives
     * elsewhere); otherwise the local URL, optionally rebased onto the host's
     * public domain for apps sitting behind a proxy with a different APP_URL.
     */
    private function canonical(Article $article): string
    {
        if ($article->canonical_url) {
            return $article->canonical_url;
        }

        $url = $article->url();
        $base = config('content-ai.seo.canonical_base');
        if (! $base) {
            return $url;
        }

        return rtrim((string) $base, '/').'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
