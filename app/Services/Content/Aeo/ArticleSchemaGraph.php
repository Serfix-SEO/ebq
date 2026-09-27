<?php

namespace App\Services\Content\Aeo;

use App\Models\ContentArticle;
use App\Models\ContentAuthor;
use App\Models\ContentTopic;
use App\Services\Content\ContentArticleSchema;

/**
 * The structured description of an article, as one JSON-LD `@graph`.
 *
 * Before this, three half-implementations disagreed: `ContentArticleSchema`
 * emitted FAQPage and only to WordPress, the PHP kit built its own BlogPosting
 * from the fields it happened to have, and `ArticleReview::seoKit()` produced a
 * copy-paste Article that **no driver ever published**. All three named the
 * author as `Organization: <bare domain>`, which is the weakest claim to
 * authorship there is.
 *
 * One builder now serves every destination, so what a client copies out of the
 * SEO kit is what actually ships. Nodes are linked by `@id` rather than nested,
 * which is what lets a consumer resolve the author of the article to the same
 * Person it saw elsewhere on the site.
 *
 * Nothing here is invented. No author entity means no Person node — an article
 * attributed to a name the client never gave us would be a fabrication, and a
 * missing byline is a finding for them to fix, not a gap for us to fill.
 */
class ArticleSchemaGraph
{
    /**
     * @return array<string, mixed> the @graph document, or [] when there is
     *                              nothing trustworthy to say
     */
    public function build(ContentArticle $article, ContentTopic $topic, ?string $publishedUrl = null): array
    {
        $website = $topic->website;
        $plan = $topic->plan;
        if ($website === null) {
            return [];
        }

        $siteUrl = 'https://'.ltrim((string) ($website->normalized_domain ?: $website->domain), '/');
        $url = $publishedUrl ?: (string) ($article->canonical_url ?: $siteUrl);
        $author = ContentAuthor::defaultFor((string) $website->id);

        $orgId = $siteUrl.'/#organization';
        $authorId = $siteUrl.'/#author';

        $graph = [];
        $graph[] = $this->organization($orgId, $siteUrl, $website, $plan);

        if ($author !== null) {
            $graph[] = $this->person($authorId, $author, $orgId);
        }

        $graph[] = $this->article($article, $topic, $url, $orgId, $author !== null ? $authorId : $orgId);

        if (($faq = $this->faq($article)) !== null) {
            $graph[] = $faq;
        }
        if (($howTo = $this->howTo($article, $url)) !== null) {
            $graph[] = $howTo;
        }
        $graph[] = $this->breadcrumb($siteUrl, $url, (string) $article->h1);

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /** The document a page can drop straight into its head or body. */
    public function script(ContentArticle $article, ContentTopic $topic, ?string $publishedUrl = null): string
    {
        $graph = $this->build($article, $topic, $publishedUrl);
        if ($graph === []) {
            return '';
        }

        return '<script type="application/ld+json">'
            .json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .'</script>';
    }

    private function organization(string $id, string $siteUrl, $website, $plan): array
    {
        $node = [
            '@type' => 'Organization',
            '@id' => $id,
            // The legal name when the client gave us one; the domain is a
            // fallback, not an identity.
            'name' => trim((string) ($plan?->org_legal_name ?: $website->domain)),
            'url' => $siteUrl,
        ];

        if (filled($plan?->org_logo_url)) {
            $node['logo'] = ['@type' => 'ImageObject', 'url' => (string) $plan->org_logo_url];
        }
        $sameAs = array_values(array_filter(
            array_map('trim', (array) ($plan?->org_same_as ?? [])),
            static fn (string $u): bool => $u !== '' && filter_var($u, FILTER_VALIDATE_URL) !== false
        ));
        if ($sameAs !== []) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }

    private function person(string $id, ContentAuthor $author, string $orgId): array
    {
        $node = [
            '@type' => 'Person',
            '@id' => $id,
            'name' => (string) $author->name,
            'worksFor' => ['@id' => $orgId],
        ];

        foreach ([
            'jobTitle' => $author->role,
            'description' => $author->bio,
            'knowsAbout' => $author->credentials,
            'image' => $author->avatar_url,
        ] as $key => $value) {
            if (filled($value)) {
                $node[$key] = (string) $value;
            }
        }
        if (($sameAs = $author->sameAsUrls()) !== []) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }

    private function article(ContentArticle $article, ContentTopic $topic, string $url, string $orgId, string $authorId): array
    {
        $node = [
            '@type' => 'BlogPosting',
            '@id' => $url.'#article',
            'headline' => (string) ($article->meta_title ?: $article->h1),
            'name' => (string) $article->h1,
            'description' => (string) $article->meta_description,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'url' => $url,
            'author' => ['@id' => $authorId],
            'publisher' => ['@id' => $orgId],
            'datePublished' => ($topic->published_at ?? $article->created_at)?->toIso8601String(),
            'dateModified' => $article->updated_at?->toIso8601String(),
            'inLanguage' => (string) ($topic->plan?->language ?: 'en'),
            'wordCount' => (int) $article->word_count,
        ];

        if (filled($article->og_image)) {
            $node['image'] = ['@type' => 'ImageObject', 'url' => (string) $article->og_image];
        }
        if (filled($article->focus_keyword)) {
            $node['keywords'] = (string) $article->focus_keyword;
        }

        // Speakable points an assistant at the passage worth reading aloud:
        // the article's opening answer. normalizeStructure() already gives
        // every heading an id, and the opening paragraph sits directly under
        // the H1, so a CSS selector is enough.
        $node['speakable'] = [
            '@type' => 'SpeakableSpecification',
            'cssSelector' => ['.ca-preview > p:first-of-type', 'h1'],
        ];

        return $node;
    }

    private function faq(ContentArticle $article): ?array
    {
        $questions = app(ContentArticleSchema::class)->faqPairs((string) $article->html);
        if (count($questions) < 2) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $q): array => [
                '@type' => 'Question',
                'name' => $q['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['answer']],
            ], $questions),
        ];
    }

    /**
     * A HowTo, but only for an article that really is a sequence of steps.
     *
     * Marking a listicle as HowTo is the kind of overreach that gets structured
     * data ignored, so this needs both an intent signal in the title and an
     * ordered list with enough steps to be a procedure.
     */
    private function howTo(ContentArticle $article, string $url): ?array
    {
        $title = (string) ($article->h1 ?: $article->meta_title);
        if (preg_match('/\b(how to|step[- ]by[- ]step|tutorial|guide to)\b/iu', $title) !== 1) {
            return null;
        }
        if (preg_match('#<ol\b[^>]*>(.*?)</ol>#is', (string) $article->html, $m) !== 1) {
            return null;
        }
        preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $m[1], $items, PREG_SET_ORDER);
        $steps = [];
        foreach ($items as $i => $item) {
            $text = trim(html_entity_decode(strip_tags($item[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }
            $steps[] = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'text' => mb_substr($text, 0, 500),
                'url' => $url.'#step-'.($i + 1),
            ];
        }
        if (count($steps) < 3) {
            return null;
        }

        return [
            '@type' => 'HowTo',
            'name' => $title,
            'step' => $steps,
        ];
    }

    private function breadcrumb(string $siteUrl, string $url, string $title): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $url],
            ],
        ];
    }
}
