<?php

namespace Tests\Unit\Content;

use App\Services\Content\ContentArticleProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * normalizeStructure() is a pure HTML transform — tested directly via
 * reflection so we don't need the whole LLM produce loop. Guards the
 * owner requirement (2026-07-18): the "In this article" TOC sits AFTER the
 * opening paragraph, never before it.
 */
class ArticleStructureTest extends TestCase
{
    use RefreshDatabase;

    private function normalize(string $html, string $h1 = 'Title', bool $withToc = true): string
    {
        $producer = app(ContentArticleProducer::class);
        $m = new ReflectionMethod($producer, 'normalizeStructure');
        $m->setAccessible(true);

        return $m->invoke($producer, $html, $h1, $withToc);
    }

    public function test_toc_is_placed_after_the_opening_paragraph(): void
    {
        $html = '<p>The pubg name generator opener with the keyphrase.</p>'
            .'<h2>How it works</h2><p>Body one.</p>'
            .'<h2>Second section</h2><p>Body two.</p>';

        $out = $this->normalize($html);

        $pPos = strpos($out, '</p>');
        $tocPos = strpos($out, 'content-toc');
        $this->assertNotFalse($tocPos, 'TOC must be present when enabled with ≥2 H2s');
        $this->assertGreaterThan($pPos, $tocPos, 'TOC must come AFTER the first closing </p>');

        // The opener paragraph is still the first <p> — the intro keyphrase
        // check (first <p>) must not be hijacked by the TOC.
        $this->assertMatchesRegularExpression('/^\s*<p>The pubg name generator opener/i', $out);
    }

    public function test_toc_falls_back_to_prepend_when_no_opening_paragraph(): void
    {
        // No <p> anywhere in the draft.
        $html = '<h2>First</h2><ul><li>a</li></ul><h2>Second</h2><ul><li>b</li></ul>';
        $out = $this->normalize($html);

        // No paragraph to anchor after: TOC prepends rather than vanishing.
        $this->assertStringContainsString('content-toc', $out);
        $this->assertLessThan(strpos($out, '<h2'), strpos($out, 'content-toc'));
    }

    public function test_no_toc_when_disabled(): void
    {
        $html = '<p>Opener.</p><h2>A</h2><p>x</p><h2>B</h2><p>y</p>';
        $out = $this->normalize($html, 'Title', false);
        $this->assertStringNotContainsString('content-toc', $out);
    }
}
