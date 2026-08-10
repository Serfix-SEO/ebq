<?php

namespace Tests\Unit\Content;

use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\ImageRef;
use App\Services\Content\Publishing\RichText\ImageRefResolver;
use App\Services\Content\Publishing\RichText\RicosAdapter;
use PHPUnit\Framework\TestCase;

class ConverterRicosTest extends TestCase
{
    /** Strip the random node ids so structures compare deterministically (only Ricos nodes carry 'type' — media src ids stay). */
    private function stripIds(array $node): array
    {
        if (isset($node['type'])) {
            unset($node['id']);
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = array_is_list($v)
                    ? array_map(fn ($c) => is_array($c) ? $this->stripIds($c) : $c, $v)
                    : $this->stripIds($v);
            }
        }

        return $node;
    }

    private function convert(string $html, ?ImageRefResolver $images = null): array
    {
        $doc = (new RicosAdapter)->convert((new HtmlBlockParser)->parse($html), $images);

        return array_map(fn ($n) => $this->stripIds($n), $doc['nodes']);
    }

    public function test_document_shell_has_nodes_and_metadata(): void
    {
        $doc = (new RicosAdapter)->convert((new HtmlBlockParser)->parse('<p>Hi</p>'));
        $this->assertSame(['version' => 1], $doc['metadata']);
        $this->assertCount(1, $doc['nodes']);
        $this->assertNotSame('', $doc['nodes'][0]['id']);
    }

    public function test_paragraph_with_decorations(): void
    {
        $nodes = $this->convert('<p>Go <strong>bold</strong> or <a href="https://x.test">link</a></p>');

        $this->assertSame([[
            'type' => 'PARAGRAPH',
            'nodes' => [
                ['type' => 'TEXT', 'textData' => ['text' => 'Go ', 'decorations' => []]],
                ['type' => 'TEXT', 'textData' => ['text' => 'bold', 'decorations' => [
                    ['type' => 'BOLD', 'fontWeightValue' => 700],
                ]]],
                ['type' => 'TEXT', 'textData' => ['text' => ' or ', 'decorations' => []]],
                ['type' => 'TEXT', 'textData' => ['text' => 'link', 'decorations' => [
                    ['type' => 'LINK', 'linkData' => ['link' => ['url' => 'https://x.test', 'target' => 'BLANK']]],
                ]]],
            ],
        ]], $nodes);
    }

    public function test_heading_levels(): void
    {
        $nodes = $this->convert('<h2>Two</h2><h3>Three</h3>');
        $this->assertSame('HEADING', $nodes[0]['type']);
        $this->assertSame(['level' => 2], $nodes[0]['headingData']);
        $this->assertSame(['level' => 3], $nodes[1]['headingData']);
    }

    public function test_lists_wrap_items_in_paragraphs(): void
    {
        $nodes = $this->convert('<ul><li>A</li><li>B</li></ul><ol><li>C</li></ol>');

        $this->assertSame('BULLETED_LIST', $nodes[0]['type']);
        $this->assertSame('ORDERED_LIST', $nodes[1]['type']);
        $item = $nodes[0]['nodes'][0];
        $this->assertSame('LIST_ITEM', $item['type']);
        $this->assertSame('PARAGRAPH', $item['nodes'][0]['type']);
        $this->assertSame('A', $item['nodes'][0]['nodes'][0]['textData']['text']);
    }

    public function test_blockquote_wraps_a_paragraph(): void
    {
        $nodes = $this->convert('<blockquote>Wise words</blockquote>');
        $this->assertSame('BLOCKQUOTE', $nodes[0]['type']);
        $this->assertSame(['indentation' => 1], $nodes[0]['blockquoteData']);
        $this->assertSame('Wise words', $nodes[0]['nodes'][0]['nodes'][0]['textData']['text']);
    }

    public function test_image_with_resolver_uses_media_id_and_dimensions(): void
    {
        $resolver = new class implements ImageRefResolver
        {
            public function resolve(string $src): ?ImageRef
            {
                return new ImageRef('wix-media-123', 1024, 768);
            }
        };

        $nodes = $this->convert('<img src="https://local.test/a.png" alt="A chart">', $resolver);

        $this->assertSame([[
            'type' => 'IMAGE',
            'imageData' => [
                'image' => ['src' => ['id' => 'wix-media-123'], 'width' => 1024, 'height' => 768],
                'altText' => 'A chart',
            ],
        ]], $nodes);
    }

    public function test_unresolved_image_degrades_to_alt_paragraph(): void
    {
        $nodes = $this->convert('<img src="https://local.test/a.png" alt="A chart">');

        $this->assertSame('PARAGRAPH', $nodes[0]['type']);
        $text = $nodes[0]['nodes'][0]['textData'];
        $this->assertSame('A chart', $text['text']);
        $this->assertSame([['type' => 'ITALIC', 'italicData' => true]], $text['decorations']);
    }

    public function test_unresolved_image_without_alt_is_dropped(): void
    {
        $this->assertSame([], $this->convert('<img src="https://local.test/a.png">'));
    }

    public function test_table_degrades_to_row_paragraphs(): void
    {
        $nodes = $this->convert('<table><tr><th>K</th><th>V</th></tr><tr><td>A</td><td>1</td></tr></table>');

        $this->assertCount(2, $nodes);
        $this->assertSame('K — V', $nodes[0]['nodes'][0]['textData']['text']);
        $this->assertSame('A — 1', $nodes[1]['nodes'][0]['textData']['text']);
    }
}
