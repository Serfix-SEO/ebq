<?php

namespace App\Services\Content;

use App\Models\ContentArticle;

/**
 * Builds structured-data (schema.org) entries for a generated article in the
 * shape the Serfix WordPress plugin stores under the `_ebq_schemas` post-meta
 * key: a list of `{id, template, type, enabled, data}` entries the plugin's
 * EBQ_Schema_Templates::render() turns into JSON-LD graph nodes.
 *
 * The plugin already auto-emits the Article / WebPage / BreadcrumbList /
 * Organization graph for every post, and auto-detects FAQPage/HowTo ONLY from
 * recognised Gutenberg blocks (yoast/rank-math/ebq faq blocks). Our articles
 * ship as plain HTML, so that auto-detection never fires — we hand the plugin
 * an explicit FAQPage entry parsed from the article's FAQ section instead.
 * A user-defined FAQPage suppresses the block-driven one, so there's never a
 * duplicate.
 *
 * Pure + side-effect free: HTML in, entries out. Empty list when the article
 * has no parseable FAQ (the plugin still emits the auto Article schema).
 */
class ContentArticleSchema
{
    /** @return list<array{id:string, template:string, type:string, enabled:bool, data:array}> */
    public function entries(ContentArticle $article): array
    {
        $entries = [];

        $questions = $this->faqQuestions((string) $article->html);
        if (count($questions) >= 2) {
            $entries[] = [
                'id' => 'faq',
                'template' => 'faq',
                'type' => 'FAQPage',
                'enabled' => true,
                'data' => ['questions' => $questions],
            ];
        }

        return $entries;
    }

    /** JSON string for the `_ebq_schemas` meta, or '' when there's nothing. */
    public function json(ContentArticle $article): string
    {
        $entries = $this->entries($article);

        return $entries === [] ? '' : (string) json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extract question/answer pairs from the article's FAQ section: the H2
     * whose text (or id) marks the FAQ, followed by H3 questions each with the
     * flowing content up to the next H3/H2 as its answer.
     *
     * @return list<array{question:string, answer:string}>
     */
    private function faqQuestions(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        // Force UTF-8 + wrap so a fragment parses; suppress HTML5 warnings.
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="ca-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('ca-root');
        if ($root === null) {
            return [];
        }

        // Walk the top-level flow in document order.
        $nodes = iterator_to_array($root->childNodes);
        $inFaq = false;
        $questions = [];
        $current = null; // ['question'=>..., 'answer'=>[]]

        $flush = static function () use (&$current, &$questions): void {
            if ($current !== null) {
                $answer = trim(preg_replace('/\s+/', ' ', implode(' ', $current['answer'])) ?? '');
                $q = trim($current['question']);
                if ($q !== '' && $answer !== '') {
                    $questions[] = ['question' => $q, 'answer' => $answer];
                }
                $current = null;
            }
        };

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);

            if ($tag === 'h2') {
                // Leaving the FAQ block once another H2 starts.
                if ($inFaq) {
                    $flush();
                    $inFaq = false;
                }
                if ($this->looksLikeFaqHeading($node)) {
                    $inFaq = true;
                }

                continue;
            }

            if (! $inFaq) {
                continue;
            }

            if ($tag === 'h3' || $tag === 'h4') {
                $flush();
                $current = ['question' => trim($node->textContent), 'answer' => []];

                continue;
            }

            // Body content between questions accumulates as the answer.
            if ($current !== null) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $current['answer'][] = $text;
                }
            }
        }
        $flush();

        // Cap to keep the payload tight (plugin caps at 20 anyway).
        return array_slice($questions, 0, 12);
    }

    private function looksLikeFaqHeading(\DOMElement $h2): bool
    {
        $id = strtolower((string) $h2->getAttribute('id'));
        if (str_starts_with($id, 'faq') || str_contains($id, 'frequently-asked')) {
            return true;
        }
        $text = strtolower(trim($h2->textContent));

        return $text === 'faq'
            || $text === 'faqs'
            || str_contains($text, 'frequently asked')
            || str_contains($text, 'common questions');
    }
}
