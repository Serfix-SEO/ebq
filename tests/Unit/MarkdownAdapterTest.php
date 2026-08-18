<?php

namespace Tests\Unit;

use App\Services\Content\Publishing\RichText\HtmlBlockParser;
use App\Services\Content\Publishing\RichText\MarkdownAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Third adapter over the shared HtmlBlockParser (after Ricos and Portable
 * Text). Covers the tags our generator emits — anything unknown must degrade
 * to plain text rather than vanish.
 */
class MarkdownAdapterTest extends TestCase
{
    private function md(string $html): string
    {
        return (new MarkdownAdapter)->render((new HtmlBlockParser)->parse($html));
    }

    public function test_headings_keep_their_level(): void
    {
        $out = $this->md('<h2>Section</h2><h3>Sub</h3>');

        $this->assertStringContainsString('## Section', $out);
        $this->assertStringContainsString('### Sub', $out);
    }

    public function test_emphasis_markers_hug_the_words(): void
    {
        // "** bold **" renders as literal asterisks, not bold.
        $out = $this->md('<p>Use <strong>warm </strong>water and <em>mild </em>soap.</p>');

        $this->assertStringContainsString('**warm**', $out);
        $this->assertStringContainsString('*mild*', $out);
        $this->assertStringNotContainsString('** ', $out);
    }

    public function test_links_and_images(): void
    {
        $out = $this->md('<p><a href="https://x.test/a">anchor</a></p><img src="https://x.test/i.png" alt="A rug">');

        $this->assertStringContainsString('[anchor](https://x.test/a)', $out);
        $this->assertStringContainsString('![A rug](https://x.test/i.png)', $out);
    }

    public function test_both_list_kinds(): void
    {
        $out = $this->md('<ul><li>one</li><li>two</li></ul><ol><li>first</li><li>second</li></ol>');

        $this->assertStringContainsString('- one', $out);
        $this->assertStringContainsString('- two', $out);
        $this->assertStringContainsString('1. first', $out);
        $this->assertStringContainsString('2. second', $out);
    }

    public function test_blockquote_and_table(): void
    {
        $out = $this->md('<blockquote>Quoted</blockquote><table><tr><td>Head</td><td>Other</td></tr><tr><td>a</td><td>b</td></tr></table>');

        $this->assertStringContainsString('> Quoted', $out);
        $this->assertStringContainsString('| Head | Other |', $out);
        $this->assertStringContainsString('| --- | --- |', $out);
        $this->assertStringContainsString('| a | b |', $out);
    }

    public function test_markdown_syntax_in_the_text_is_escaped(): void
    {
        // A literal asterisk in prose must not turn into emphasis downstream.
        $out = $this->md('<p>Costs 5 * 3 dollars and uses _underscores_ [brackets]</p>');

        $this->assertStringContainsString('\\*', $out);
        $this->assertStringContainsString('\\_', $out);
        $this->assertStringContainsString('\\[', $out);
    }

    public function test_unknown_tags_degrade_to_text_instead_of_disappearing(): void
    {
        $out = $this->md('<aside>Side note worth keeping</aside>');

        $this->assertStringContainsString('Side note worth keeping', $out);
    }

    public function test_empty_input_is_empty_output(): void
    {
        $this->assertSame('', trim($this->md('')));
    }
}
