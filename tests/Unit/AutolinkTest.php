<?php

namespace Tests\Unit;

use App\Support\Autolink;
use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Support messages arrive with pasted URLs, not editor-made links — those
 * rendered as dead text the client had to copy by hand (reported 2026-08-18).
 * Autolink runs on already-safe HTML, so the risky cases are double-linking
 * and re-opening the XSS door the sanitizer closed.
 */
class AutolinkTest extends TestCase
{
    private function render(string $raw): string
    {
        $safe = preg_match('/<[a-z][^>]*>/i', $raw) === 1
            ? HtmlSanitizer::clean($raw)
            : nl2br(e($raw));

        return Autolink::apply($safe);
    }

    public function test_a_pasted_url_becomes_a_link(): void
    {
        $out = $this->render('See https://serfix.io/guide for setup.');

        $this->assertStringContainsString('<a href="https://serfix.io/guide"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('rel="noopener nofollow"', $out);
    }

    public function test_trailing_sentence_punctuation_stays_out_of_the_link(): void
    {
        $this->assertStringContainsString('href="https://serfix.io/guide"', $this->render('Read https://serfix.io/guide.'));
        $this->assertStringEndsWith('.', trim(strip_tags($this->render('Read https://serfix.io/guide.'))));

        // An unmatched closing bracket belongs to the sentence too.
        $out = $this->render('Docs (https://serfix.io/guide) help.');
        $this->assertStringContainsString('href="https://serfix.io/guide"', $out);
        $this->assertStringNotContainsString('guide)"', $out);
    }

    public function test_query_strings_survive(): void
    {
        $out = $this->render('Try https://serfix.io/r/abc?x=1&y=2 now');

        $this->assertStringContainsString('href="https://serfix.io/r/abc?x=1&amp;y=2"', $out);
    }

    public function test_bare_www_gets_a_scheme(): void
    {
        $this->assertStringContainsString('href="https://www.serfix.io"', $this->render('Visit www.serfix.io today'));
    }

    public function test_an_existing_link_is_not_touched(): void
    {
        $out = $this->render('<p>Go <a href="https://serfix.io">here</a></p>');

        $this->assertSame(1, substr_count($out, '<a '), 'must not wrap an anchor in another anchor');
    }

    public function test_a_link_whose_text_is_a_url_is_not_double_linked(): void
    {
        // The nastiest case: the anchor text itself matches the URL pattern.
        $out = $this->render('<p><a href="https://serfix.io">https://serfix.io</a></p>');

        $this->assertSame(1, substr_count($out, '<a '));
        $this->assertStringNotContainsString('<a href="https://serfix.io" rel="noopener nofollow" target="_blank"><a', $out);
    }

    public function test_dangerous_schemes_are_never_linked(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHN2Zz4=', 'file:///etc/passwd'] as $bad) {
            $out = $this->render('Do not click '.$bad.' please');
            $this->assertStringNotContainsString('<a ', $out, $bad.' must not become a link');
        }
    }

    public function test_text_without_a_url_is_returned_unchanged(): void
    {
        $html = '<p>Nothing to see</p>';
        $this->assertSame($html, Autolink::apply($html));
    }

    public function test_it_does_not_linkify_inside_tag_attributes(): void
    {
        // Sanitized output only carries href, but a future allowed attribute
        // must not get a nested anchor spliced into it.
        $out = Autolink::apply('<a href="https://serfix.io/a?b=https://x.test">text</a>');

        $this->assertSame(1, substr_count($out, '<a '));
    }
}
