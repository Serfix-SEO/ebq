<?php

namespace App\Services\Content\Publishing\RichText;

use App\Services\Content\Publishing\RichText\Blocks\Block;
use App\Services\Content\Publishing\RichText\Blocks\Blockquote;
use App\Services\Content\Publishing\RichText\Blocks\Heading;
use App\Services\Content\Publishing\RichText\Blocks\ImageBlock;
use App\Services\Content\Publishing\RichText\Blocks\ListBlock;
use App\Services\Content\Publishing\RichText\Blocks\Paragraph;
use App\Services\Content\Publishing\RichText\Blocks\TableBlock;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Bounded HTML → block-model converter for destinations that reject raw HTML
 * (Wix Ricos, Sanity Portable Text). Bounded on purpose: it only understands
 * the tags our own article generator emits — h2/h3, p, ul/ol/li, a,
 * strong/b/em/i, img/figure/figcaption, blockquote, table. Anything else
 * degrades to a plain-text paragraph rather than being dropped, so a future
 * generator change can never silently lose article content.
 */
class HtmlBlockParser
{
    /** @return list<Block> */
    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Numeric entities make DOMDocument treat the input as UTF-8 without
        // relying on a meta charset tag; the wrapper div gives libxml the
        // single root NOIMPLIED requires.
        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        $dom->loadHTML('<div>'.$encoded.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return [];
        }

        $blocks = [];
        foreach (iterator_to_array($root->childNodes) as $node) {
            foreach ($this->blockify($node) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @return list<Block> */
    private function blockify(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            // Stray top-level text (shouldn't occur in generated HTML).
            $text = $this->squish($node->textContent);

            return $text === '' ? [] : [new Paragraph([new Inline($text)])];
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        switch (strtolower($node->tagName)) {
            case 'h1':
            case 'h2':
                return $this->wrapInlines(fn ($inlines) => new Heading(2, $inlines), $node);
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return $this->wrapInlines(fn ($inlines) => new Heading(3, $inlines), $node);
            case 'p':
                // A paragraph that only wraps an image is an image block.
                $img = $this->onlyChildImage($node);
                if ($img instanceof DOMElement) {
                    return $this->imageBlocks($img);
                }

                return $this->wrapInlines(fn ($inlines) => new Paragraph($inlines), $node);
            case 'blockquote':
                return $this->wrapInlines(fn ($inlines) => new Blockquote($inlines), $node);
            case 'ul':
            case 'ol':
                $items = $this->listItems($node);

                return $items === [] ? [] : [new ListBlock(strtolower($node->tagName) === 'ol', $items)];
            case 'img':
                return $this->imageBlocks($node);
            case 'figure':
                return $this->figureBlocks($node);
            case 'table':
                $rows = $this->tableRows($node);

                return $rows === [] ? [] : [new TableBlock($rows)];
            case 'div':
            case 'section':
            case 'article':
                // Transparent containers: recurse into children.
                $blocks = [];
                foreach (iterator_to_array($node->childNodes) as $child) {
                    foreach ($this->blockify($child) as $block) {
                        $blocks[] = $block;
                    }
                }

                return $blocks;
            default:
                // Unknown element → its visible text as a plain paragraph.
                $text = $this->squish($node->textContent);

                return $text === '' ? [] : [new Paragraph([new Inline($text)])];
        }
    }

    /**
     * @param  callable(list<Inline>): Block  $factory
     * @return list<Block>
     */
    private function wrapInlines(callable $factory, DOMElement $element): array
    {
        $inlines = $this->inlines($element);
        if ($inlines === []) {
            return [];
        }

        return [$factory($inlines)];
    }

    /**
     * Collect styled text runs from an element's subtree. Bold/italic/link
     * state nests (strong > em > a all combine); <br> becomes a space;
     * adjacent same-style runs merge.
     *
     * @return list<Inline>
     */
    private function inlines(DOMElement $element, bool $bold = false, bool $italic = false, ?string $href = null): array
    {
        $runs = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                $runs[] = new Inline($child->textContent, $bold, $italic, $href);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            switch (strtolower($child->tagName)) {
                case 'strong':
                case 'b':
                    $runs = array_merge($runs, $this->inlines($child, true, $italic, $href));
                    break;
                case 'em':
                case 'i':
                    $runs = array_merge($runs, $this->inlines($child, $bold, true, $href));
                    break;
                case 'a':
                    $url = trim($child->getAttribute('href'));
                    $runs = array_merge($runs, $this->inlines($child, $bold, $italic, $url !== '' ? $url : $href));
                    break;
                case 'br':
                    $runs[] = new Inline(' ', $bold, $italic, $href);
                    break;
                case 'ul':
                case 'ol':
                    // Nested list inside an inline context (an <li>) is handled
                    // by listItems(); skip here so its text isn't duplicated.
                    break;
                default:
                    // span/code/etc — keep the text, current style.
                    $runs = array_merge($runs, $this->inlines($child, $bold, $italic, $href));
                    break;
            }
        }

        return $this->normalizeRuns($runs);
    }

    /**
     * Merge adjacent same-style runs, collapse whitespace, trim the edges of
     * the whole sequence, drop empties.
     *
     * @param  list<Inline>  $runs
     * @return list<Inline>
     */
    private function normalizeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            if ($last !== null && $last->sameStyle($run)) {
                $merged[count($merged) - 1] = $last->withText($last->text.$run->text);
            } else {
                $merged[] = $run;
            }
        }

        $out = [];
        foreach ($merged as $i => $run) {
            $text = preg_replace('/\s+/u', ' ', $run->text) ?? $run->text;
            if ($i === 0) {
                $text = ltrim($text);
            }
            if ($i === count($merged) - 1) {
                $text = rtrim($text);
            }
            if ($text !== '') {
                $out[] = $run->withText($text);
            }
        }

        return array_values($out);
    }

    /**
     * Items of a ul/ol. Our lists are flat; a nested list found inside an
     * <li> is flattened — its items are appended as siblings.
     *
     * @return list<list<Inline>>
     */
    private function listItems(DOMElement $list): array
    {
        $items = [];
        foreach ($list->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $inlines = $this->inlines($li);
            if ($inlines !== []) {
                $items[] = $inlines;
            }
            foreach ($li->childNodes as $nested) {
                if ($nested instanceof DOMElement && in_array(strtolower($nested->tagName), ['ul', 'ol'], true)) {
                    $items = array_merge($items, $this->listItems($nested));
                }
            }
        }

        return $items;
    }

    /** The lone <img> child of a paragraph whose remaining content is blank, or null. */
    private function onlyChildImage(DOMElement $p): ?DOMElement
    {
        $img = null;
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->textContent) !== '') {
                    return null;
                }

                continue;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'img') {
                if ($img !== null) {
                    return null;
                }
                $img = $child;

                continue;
            }
            if ($child instanceof DOMElement) {
                return null;
            }
        }

        return $img;
    }

    /** @return list<Block> */
    private function imageBlocks(DOMElement $img): array
    {
        $src = trim($img->getAttribute('src'));
        if ($src === '') {
            return [];
        }

        return [new ImageBlock($src, $this->squish($img->getAttribute('alt')))];
    }

    /** figure → image + italic caption paragraph. */
    private function figureBlocks(DOMElement $figure): array
    {
        $blocks = [];
        foreach ($figure->getElementsByTagName('img') as $img) {
            $blocks = array_merge($blocks, $this->imageBlocks($img));
            break;
        }
        foreach ($figure->getElementsByTagName('figcaption') as $caption) {
            $text = $this->squish($caption->textContent);
            if ($text !== '') {
                $blocks[] = new Paragraph([new Inline($text, italic: true)]);
            }
            break;
        }

        return $blocks;
    }

    /** @return list<list<string>> */
    private function tableRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->squish($cell->textContent);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function squish(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
