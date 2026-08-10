<?php

namespace Tests\Unit\Content;

use App\Services\Content\Publishing\RichText\Blocks\Blockquote;
use App\Services\Content\Publishing\RichText\Blocks\Heading;
use App\Services\Content\Publishing\RichText\Blocks\ImageBlock;
use App\Services\Content\Publishing\RichText\Blocks\ListBlock;
use App\Services\Content\Publishing\RichText\Blocks\Paragraph;
use App\Services\Content\Publishing\RichText\Blocks\TableBlock;
use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use PHPUnit\Framework\TestCase;

class HtmlBlockParserTest extends TestCase
{
    private function blocks(): array
    {
        $html = file_get_contents(__DIR__.'/../../Fixtures/content/converter-article.html');

        return (new HtmlBlockParser)->parse($html);
    }

    public function test_fixture_produces_the_expected_block_sequence(): void
    {
        $types = array_map(fn ($b) => get_class($b), $this->blocks());

        $this->assertSame([
            Paragraph::class,   // opener
            Heading::class,     // h2
            Paragraph::class,   // unicode
            ListBlock::class,   // ul (nested flattened)
            Heading::class,     // h3
            ListBlock::class,   // ol
            Blockquote::class,
            ImageBlock::class,  // figure img
            Paragraph::class,   // figcaption (italic)
            ImageBlock::class,  // p-wrapped img
            TableBlock::class,
            Paragraph::class,   // aside fallback
        ], $types);
    }

    public function test_inline_styles_nest_and_merge(): void
    {
        $opener = $this->blocks()[0];
        $this->assertInstanceOf(Paragraph::class, $opener);

        $runs = array_map(
            fn ($i) => [$i->text, $i->bold, $i->italic, $i->href],
            $opener->inlines,
        );

        $this->assertSame([
            ['Opening paragraph with ', false, false, null],
            ['bold', true, false, null],
            [', ', false, false, null],
            ['italic', false, true, null],
            [' and a ', false, false, null],
            ['nested ', false, false, 'https://example.com/guide'],
            ['bold link', true, false, 'https://example.com/guide'],
            // <br> collapses to a space and merges into the neighbouring run.
            [' plus a line break.', false, false, null],
        ], $runs);
    }

    public function test_headings_map_h2_and_h3(): void
    {
        [$h2, $h3] = [$this->blocks()[1], $this->blocks()[4]];
        $this->assertSame(2, $h2->level);
        $this->assertSame('First section', $h2->inlines[0]->text);
        $this->assertSame(3, $h3->level);
    }

    public function test_nested_list_is_flattened_and_ol_is_ordered(): void
    {
        $ul = $this->blocks()[3];
        $this->assertFalse($ul->ordered);
        $this->assertCount(3, $ul->items); // First, Second (styled), Nested
        $this->assertSame('First item', $ul->items[0][0]->text);
        $this->assertSame('Nested item', $ul->items[2][0]->text);
        // The nested item's text must not leak into its parent item.
        $secondText = implode('', array_map(fn ($i) => $i->text, $ul->items[1]));
        $this->assertSame('Second styled item', $secondText);

        $ol = $this->blocks()[5];
        $this->assertTrue($ol->ordered);
        $this->assertCount(2, $ol->items);
    }

    public function test_figure_becomes_image_plus_italic_caption(): void
    {
        $image = $this->blocks()[7];
        $this->assertSame('https://cdn.serfix.io/content/images/abc.png', $image->src);
        $this->assertSame('Chart of results', $image->alt);

        $caption = $this->blocks()[8];
        $this->assertTrue($caption->inlines[0]->italic);
        $this->assertSame('Results over time', $caption->inlines[0]->text);
    }

    public function test_table_rows_are_plain_text(): void
    {
        $table = $this->blocks()[10];
        $this->assertSame([['Metric', 'Value'], ['Traffic', '1,200']], $table->rows);
    }

    public function test_unknown_element_falls_back_to_plain_paragraph(): void
    {
        $aside = $this->blocks()[11];
        $this->assertInstanceOf(Paragraph::class, $aside);
        $this->assertSame('Unknown wrapper text survives as a paragraph.', $aside->inlines[0]->text);
    }

    public function test_unicode_survives(): void
    {
        $this->assertSame('Plain text — with unicode: café, 中文.', $this->blocks()[2]->inlines[0]->text);
    }

    public function test_empty_and_blank_html_yield_no_blocks(): void
    {
        $parser = new HtmlBlockParser;
        $this->assertSame([], $parser->parse(''));
        $this->assertSame([], $parser->parse("  \n  "));
        $this->assertSame([], $parser->parse('<p>   </p>'));
    }
}
