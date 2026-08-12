<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_allowed_formatting_survives(): void
    {
        $html = '<p>Hi <strong>there</strong>, <em>please</em> check:</p><ul><li>one</li><li>two</li></ul>';
        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_script_and_event_handlers_are_stripped(): void
    {
        $out = HtmlSanitizer::clean('<p onclick="alert(1)">Hi</p><script>alert(2)</script><img src=x onerror=alert(3)>');
        $this->assertSame('<p>Hi</p>', $out);
    }

    public function test_javascript_links_lose_the_href_but_keep_text(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">click</a> <a href="https://x.test/a">ok</a>');
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('click', $out);
        $this->assertStringContainsString('<a href="https://x.test/a" rel="noopener nofollow" target="_blank">ok</a>', $out);
    }

    public function test_contenteditable_divs_become_paragraphs(): void
    {
        $this->assertSame('<p>line one</p><p>line two</p>', HtmlSanitizer::clean('<div>line one</div><div>line two</div>'));
    }

    public function test_unknown_wrappers_keep_their_text(): void
    {
        $this->assertSame('wrapped text', HtmlSanitizer::clean('<span style="color:red">wrapped text</span>'));
    }

    public function test_text_helper_strips_markup_for_length_checks(): void
    {
        $this->assertSame('Hi there', HtmlSanitizer::text('<p>Hi <b>there</b></p>'));
        $this->assertSame('', HtmlSanitizer::text('<p> </p><br>'));
    }

    public function test_unicode_survives(): void
    {
        $this->assertSame('<p>café — 中文</p>', HtmlSanitizer::clean('<p>café — 中文</p>'));
    }
}
