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
 * Block model → Ricos rich-content document (Wix Blog `richContent`).
 * Tables degrade to one paragraph per row (the Ricos TABLE node is
 * plugin-gated and not guaranteed available on every site).
 */
class RicosAdapter
{
    /**
     * @param  list<Block>  $blocks
     * @return array{nodes: list<array<string, mixed>>, metadata: array{version: int}}
     */
    public function convert(array $blocks, ?ImageRefResolver $images = null): array
    {
        $nodes = [];
        foreach ($blocks as $block) {
            foreach ($this->nodesFor($block, $images) as $node) {
                $nodes[] = $node;
            }
        }

        return ['nodes' => $nodes, 'metadata' => ['version' => 1]];
    }

    /** @return list<array<string, mixed>> */
    private function nodesFor(Block $block, ?ImageRefResolver $images): array
    {
        if ($block instanceof Heading) {
            return [[
                'type' => 'HEADING',
                'id' => $this->id(),
                'headingData' => ['level' => $block->level],
                'nodes' => $this->textNodes($block->inlines),
            ]];
        }

        if ($block instanceof Paragraph) {
            return [$this->paragraphNode($block->inlines)];
        }

        if ($block instanceof Blockquote) {
            return [[
                'type' => 'BLOCKQUOTE',
                'id' => $this->id(),
                'blockquoteData' => ['indentation' => 1],
                'nodes' => [$this->paragraphNode($block->inlines)],
            ]];
        }

        if ($block instanceof ListBlock) {
            $items = [];
            foreach ($block->items as $inlines) {
                $items[] = [
                    'type' => 'LIST_ITEM',
                    'id' => $this->id(),
                    'nodes' => [$this->paragraphNode($inlines)],
                ];
            }

            return [[
                'type' => $block->ordered ? 'ORDERED_LIST' : 'BULLETED_LIST',
                'id' => $this->id(),
                'nodes' => $items,
            ]];
        }

        if ($block instanceof ImageBlock) {
            $ref = $images?->resolve($block->src);
            if ($ref === null) {
                // Couldn't re-host on Wix — keep the alt text visible instead
                // of dropping the image silently.
                if ($block->alt !== '') {
                    return [$this->paragraphNode([new Inline($block->alt, italic: true)])];
                }

                return [];
            }

            $image = ['src' => ['id' => $ref->ref]];
            if ($ref->width !== null && $ref->height !== null) {
                $image['width'] = $ref->width;
                $image['height'] = $ref->height;
            }
            $data = ['image' => $image];
            if ($block->alt !== '') {
                $data['altText'] = $block->alt;
            }

            return [['type' => 'IMAGE', 'id' => $this->id(), 'imageData' => $data]];
        }

        if ($block instanceof TableBlock) {
            $nodes = [];
            foreach ($block->rows as $row) {
                $nodes[] = $this->paragraphNode([new Inline(implode(' — ', $row))]);
            }

            return $nodes;
        }

        return [];
    }

    /**
     * @param  list<Inline>  $inlines
     * @return array<string, mixed>
     */
    private function paragraphNode(array $inlines): array
    {
        return [
            'type' => 'PARAGRAPH',
            'id' => $this->id(),
            'nodes' => $this->textNodes($inlines),
        ];
    }

    /**
     * @param  list<Inline>  $inlines
     * @return list<array<string, mixed>>
     */
    private function textNodes(array $inlines): array
    {
        $nodes = [];
        foreach ($inlines as $inline) {
            $decorations = [];
            if ($inline->bold) {
                $decorations[] = ['type' => 'BOLD', 'fontWeightValue' => 700];
            }
            if ($inline->italic) {
                $decorations[] = ['type' => 'ITALIC', 'italicData' => true];
            }
            if ($inline->href !== null) {
                $decorations[] = [
                    'type' => 'LINK',
                    'linkData' => ['link' => ['url' => $inline->href, 'target' => 'BLANK']],
                ];
            }
            $nodes[] = [
                'type' => 'TEXT',
                'id' => '',
                'textData' => ['text' => $inline->text, 'decorations' => $decorations],
            ];
        }

        return $nodes;
    }

    private function id(): string
    {
        return Str::lower(Str::random(5));
    }
}
