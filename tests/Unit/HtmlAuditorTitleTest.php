<?php

namespace Tests\Unit;

use App\Support\Audit\HtmlAuditor;
use PHPUnit\Framework\TestCase;

/**
 * The page title must come from the HEAD <title>, never from a <title>
 * inside an inline SVG icon sprite — rendered Shopify DOMs put sprite
 * symbols ("icon-X") ahead of everything, and `string(//title)` stamped
 * 503 cocomii pages with the icon's name, mass-producing false
 * duplicate_title findings (2026-08-20).
 */
class HtmlAuditorTitleTest extends TestCase
{
    public function test_svg_symbol_titles_never_win_over_the_head_title(): void
    {
        $html = '<html><head><title>Real Page Title | Shop</title></head><body>'
            .'<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'
            .'<symbol id="icon-X"><title>icon-X</title><path d="M0 0"/></symbol>'
            .'<symbol id="bag"><title>shopify-bag-outline</title></symbol>'
            .'</svg><h1>Hello</h1></body></html>';

        $meta = (new HtmlAuditor($html, 'https://example.com/'))->metadata();

        $this->assertSame('Real Page Title | Shop', $meta['title']);
    }

    public function test_sprite_before_head_title_in_malformed_dom_still_loses(): void
    {
        // Malformed markup can hoist body content — emulate a document where
        // the sprite's <title> precedes the head one in document order.
        $html = '<html><body>'
            .'<svg><symbol><title>icon-X</title></symbol></svg>'
            .'<title>Actual Title</title>'
            .'</body></html>';

        $meta = (new HtmlAuditor($html, 'https://example.com/'))->metadata();

        $this->assertSame('Actual Title', $meta['title']);
    }

    public function test_head_title_absent_falls_back_to_first_non_svg_title(): void
    {
        $html = '<div><svg><title>icon-X</title></svg><title>Fragment Title</title></div>';

        $meta = (new HtmlAuditor($html, 'https://example.com/'))->metadata();

        $this->assertSame('Fragment Title', $meta['title']);
    }
}
