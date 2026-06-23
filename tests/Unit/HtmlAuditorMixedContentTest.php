<?php

namespace Tests\Unit;

use App\Support\Audit\HtmlAuditor;
use PHPUnit\Framework\TestCase;

class HtmlAuditorMixedContentTest extends TestCase
{
    public function test_plain_http_resources_on_an_https_page_are_flagged(): void
    {
        $html = '<html><body>'
            .'<img src="http://cdn.example.com/logo.png">'
            .'<script src="https://cdn.example.com/safe.js"></script>'
            .'<link rel="stylesheet" href="http://cdn.example.com/style.css">'
            .'<img src="/relative/image.png">'
            .'<img src="//cdn.example.com/protocol-relative.png">'
            .'</body></html>';

        $found = (new HtmlAuditor($html, 'https://example.com/page'))->mixedContentUrls();

        $this->assertSame(['http://cdn.example.com/logo.png', 'http://cdn.example.com/style.css'], $found);
    }

    public function test_http_page_is_never_flagged_for_mixed_content(): void
    {
        $html = '<html><body><img src="http://cdn.example.com/logo.png"></body></html>';

        $found = (new HtmlAuditor($html, 'http://example.com/page'))->mixedContentUrls();

        $this->assertSame([], $found);
    }
}
