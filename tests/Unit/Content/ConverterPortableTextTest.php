<?php

namespace Tests\Unit\Content;

use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\ImageRef;
use App\Services\Content\Publishing\RichText\ImageRefResolver;
use App\Services\Content\Publishing\RichText\PortableTextAdapter;
use PHPUnit\Framework\TestCase;

class ConverterPortableTextTest extends TestCase
{
    /** Strip random _key values (link marks reference markDef keys — normalize those too). */
    private function normalize(array $blocks): array
    {
        foreach ($blocks as $b => $block) {
            $linkMap = [];
            foreach ($block['markDefs'] ?? [] as $i => $def) {
                $linkMap[$def['_key']] = 'link'.$i;
                $blocks[$b]['markDefs'][$i]['_key'] = 'link'.$i;
            }
            unset($blocks[$b]['_key']);
            foreach ($block['children'] ?? [] as $c => $child) {
                unset($blocks[$b]['children'][$c]['_key']);
                $blocks[$b]['children'][$c]['marks'] = array_map(fn ($m) => $linkMap[$m] ?? $m, $child['marks']);
            }
        }

        return $blocks;
    }

    private function convert(string $html, ?ImageRefResolver $images = null): array
    {
        $blocks = (new PortableTextAdapter)->convert((new HtmlBlockParser)->parse($html), $images);

        return $this->normalize($blocks);
    }

    public function test_paragraph_with_marks_and_link(): void
    {
        $blocks = $this->convert('<p>Go <strong><em>wild</em></strong> or <a href="https://x.test">read</a></p>');

        $this->assertSame([[
            '_type' => 'block',
            'style' => 'normal',
            'markDefs' => [['_key' => 'link0', '_type' => 'link', 'href' => 'https://x.test']],
            'children' => [
                ['_type' => 'span', 'text' => 'Go ', 'marks' => []],
                ['_type' => 'span', 'text' => 'wild', 'marks' => ['strong', 'em']],
                ['_type' => 'span', 'text' => ' or ', 'marks' => []],
                ['_type' => 'span', 'text' => 'read', 'marks' => ['link0']],
            ],
        ]], $blocks);
    }

    public function test_heading_and_blockquote_styles(): void
    {
        $blocks = $this->convert('<h2>Two</h2><h3>Three</h3><blockquote>Quote</blockquote>');
        $this->assertSame('h2', $blocks[0]['style']);
        $this->assertSame('h3', $blocks[1]['style']);
        $this->assertSame('blockquote', $blocks[2]['style']);
    }

    public function test_lists_emit_one_block_per_item(): void
    {
        $blocks = $this->convert('<ul><li>A</li><li>B</li></ul><ol><li>C</li></ol>');

        $this->assertSame('bullet', $blocks[0]['listItem']);
        $this->assertSame(1, $blocks[0]['level']);
        $this->assertSame('A', $blocks[0]['children'][0]['text']);
        $this->assertSame('bullet', $blocks[1]['listItem']);
        $this->assertSame('number', $blocks[2]['listItem']);
        $this->assertSame('C', $blocks[2]['children'][0]['text']);
    }

    public function test_image_with_resolver_becomes_asset_reference(): void
    {
        $resolver = new class implements ImageRefResolver
        {
            public function resolve(string $src): ?ImageRef
            {
                return new ImageRef('image-abc123-1024x768-png');
            }
        };

        $blocks = $this->convert('<img src="https://local.test/a.png" alt="A chart">', $resolver);

        $this->assertSame([[
            '_type' => 'image',
            'asset' => ['_type' => 'reference', '_ref' => 'image-abc123-1024x768-png'],
            'alt' => 'A chart',
        ]], $blocks);
    }

    public function test_unresolved_image_degrades_to_alt_span(): void
    {
        $blocks = $this->convert('<img src="https://local.test/a.png" alt="A chart">');
        $this->assertSame('normal', $blocks[0]['style']);
        $this->assertSame('A chart', $blocks[0]['children'][0]['text']);
        $this->assertSame(['em'], $blocks[0]['children'][0]['marks']);
    }

    public function test_table_degrades_to_row_blocks(): void
    {
        $blocks = $this->convert('<table><tr><td>A</td><td>1</td></tr></table>');
        $this->assertSame('A — 1', $blocks[0]['children'][0]['text']);
    }

    public function test_every_block_and_span_has_a_key(): void
    {
        $raw = (new PortableTextAdapter)->convert((new HtmlBlockParser)->parse('<p>Hi <b>there</b></p>'));
        $this->assertNotEmpty($raw[0]['_key']);
        foreach ($raw[0]['children'] as $child) {
            $this->assertNotEmpty($child['_key']);
        }
    }
}
