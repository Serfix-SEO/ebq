<?php

namespace App\Services\Content\Publishing\RichText;

use App\Services\Content\Publishing\RichText\Blocks\Block;
use App\Services\Content\Publishing\RichText\Blocks\Blockquote;
use App\Services\Content\Publishing\RichText\Blocks\Heading;
use App\Services\Content\Publishing\RichText\Blocks\ImageBlock;
use App\Services\Content\Publishing\RichText\Blocks\ListBlock;
use App\Services\Content\Publishing\RichText\Blocks\Paragraph;
use App\Services\Content\Publishing\RichText\Blocks\TableBlock;
use Illuminate\Support\Str;

/**
 * Block model → Portable Text array (Sanity `body`).
 * Spec: https://github.com/portabletext/portabletext
 * Tables degrade to one paragraph per row (no standard PT table type).
 */
class PortableTextAdapter
{
    /**
     * @param  list<Block>  $blocks
     * @return list<array<string, mixed>>
     */
    public function convert(array $blocks, ?ImageRefResolver $images = null): array
    {
        $out = [];
        foreach ($blocks as $block) {
            foreach ($this->blocksFor($block, $images) as $node) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function blocksFor(Block $block, ?ImageRefResolver $images): array
    {
        if ($block instanceof Heading) {
            return [$this->textBlock('h'.$block->level, $block->inlines)];
        }

        if ($block instanceof Paragraph) {
            return [$this->textBlock('normal', $block->inlines)];
        }

        if ($block instanceof Blockquote) {
            return [$this->textBlock('blockquote', $block->inlines)];
        }

        if ($block instanceof ListBlock) {
            $nodes = [];
            foreach ($block->items as $inlines) {
                $node = $this->textBlock('normal', $inlines);
                $node['listItem'] = $block->ordered ? 'number' : 'bullet';
                $node['level'] = 1;
                $nodes[] = $node;
            }

            return $nodes;
        }

        if ($block instanceof ImageBlock) {
            $ref = $images?->resolve($block->src);
            if ($ref === null) {
                if ($block->alt !== '') {
                    return [$this->textBlock('normal', [new Inline($block->alt, italic: true)])];
                }

                return [];
            }

            $node = [
                '_type' => 'image',
                '_key' => $this->key(),
                'asset' => ['_type' => 'reference', '_ref' => $ref->ref],
            ];
            if ($block->alt !== '') {
                $node['alt'] = $block->alt;
            }

            return [$node];
        }

        if ($block instanceof TableBlock) {
            $nodes = [];
            foreach ($block->rows as $row) {
                $nodes[] = $this->textBlock('normal', [new Inline(implode(' — ', $row))]);
            }

            return $nodes;
        }

        return [];
    }

    /**
     * @param  list<Inline>  $inlines
     * @return array<string, mixed>
     */
    private function textBlock(string $style, array $inlines): array
    {
        $markDefs = [];
        $children = [];
        $linkKeys = []; // href → markDef _key, reused within the block

        foreach ($inlines as $inline) {
            $marks = [];
            if ($inline->bold) {
                $marks[] = 'strong';
            }
            if ($inline->italic) {
                $marks[] = 'em';
            }
            if ($inline->href !== null) {
                if (! isset($linkKeys[$inline->href])) {
                    $key = $this->key();
                    $linkKeys[$inline->href] = $key;
                    $markDefs[] = ['_key' => $key, '_type' => 'link', 'href' => $inline->href];
                }
                $marks[] = $linkKeys[$inline->href];
            }
            $children[] = [
                '_type' => 'span',
                '_key' => $this->key(),
                'text' => $inline->text,
                'marks' => $marks,
            ];
        }

        return [
            '_type' => 'block',
            '_key' => $this->key(),
            'style' => $style,
            'markDefs' => $markDefs,
            'children' => $children,
        ];
    }

    private function key(): string
    {
        return Str::lower(Str::random(12));
    }
}
