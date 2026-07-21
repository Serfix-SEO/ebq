<?php

namespace Serfix\ContentAi\Services;

use Serfix\ContentAi\Models\Article;

/**
 * JSON-LD for an article: BlogPosting (+ BreadcrumbList, + FAQPage).
 *
 * The FAQPage node is free rich-result eligibility: Content AI already writes
 * an FAQ section into the HTML when the plan's `faq` toggle is on, so we lift
 * the existing <h2>question</h2><p>answer</p> pairs rather than asking the
 * host to author structured data by hand.
 */
class SchemaBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(Article $article): array
    {
        if (! config('content-ai.schema.enabled', true)) {
            return [];
        }

        $graph = [$this->blogPosting($article)];

        if (config('content-ai.schema.breadcrumbs', true)) {
            $graph[] = $this->breadcrumbs($article);
        }

        if (config('content-ai.schema.faq', true)) {
            $faq = $this->faq($article);
            if ($faq !== null) {
                $graph[] = $faq;
            }
        }

        return $graph;
    }

    public function toJson(Article $article): string
    {
        $graph = $this->for($article);
        if ($graph === []) {
            return '';
        }

        return (string) json_encode(
            count($graph) === 1 ? $graph[0] : ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    // ── nodes ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function blogPosting(Article $article): array
    {
        $image = $article->featuredImage();

        $node = [
            '@context' => 'https://schema.org',
            '@type' => (string) config('content-ai.schema.type', 'BlogPosting'),
            'headline' => $article->meta_title ?: $article->title,
            'description' => $article->summary(),
            'inLanguage' => $article->language,
            'wordCount' => $article->word_count,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $article->url()],
            'url' => $article->url(),
            'datePublished' => $article->published_at?->toIso8601String(),
            'dateModified' => $article->updated_at?->toIso8601String(),
            'author' => array_filter([
                '@type' => (string) config('content-ai.schema.author.type', 'Organization'),
                'name' => (string) config('content-ai.schema.author.name'),
                'url' => config('content-ai.schema.author.url'),
            ]),
            'publisher' => array_filter([
                '@type' => 'Organization',
                'name' => (string) config('content-ai.schema.publisher.name'),
                'logo' => config('content-ai.schema.publisher.logo')
                    ? ['@type' => 'ImageObject', 'url' => config('content-ai.schema.publisher.logo')]
                    : null,
            ]),
        ];

        if ($image !== null) {
            $node['image'] = ['@type' => 'ImageObject', 'url' => $image->url()];
        }

        if ($article->target_keyword) {
            $node['keywords'] = implode(', ', array_filter(array_merge(
                [$article->target_keyword],
                (array) $article->secondary_keywords
            )));
        }

        return array_filter($node, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** @return array<string, mixed> */
    private function breadcrumbs(Article $article): array
    {
        $prefix = trim((string) config('content-ai.route.prefix', 'blog'), '/');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => url($prefix)],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $article->title, 'item' => $article->url()],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function faq(Article $article): ?array
    {
        $html = (string) $article->html;

        // Content AI wraps its FAQ in a section carrying this class; without it
        // we would misread ordinary H2s as questions.
        if (! preg_match('#<(section|div)[^>]*class="[^"]*\bfaq\b[^"]*"[^>]*>(.*?)</\1>#is', $html, $block)) {
            return null;
        }

        if (! preg_match_all('#<h[23][^>]*>(.*?)</h[23]>\s*(.*?)(?=<h[23]|\z)#is', $block[2], $pairs, PREG_SET_ORDER)) {
            return null;
        }

        $entities = [];
        foreach ($pairs as $pair) {
            $question = trim(strip_tags($pair[1]));
            $answer = trim(strip_tags($pair[2] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($entities === []) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    }
}
