<?php

namespace Tests\Unit\Content;

use App\Services\AiContentBriefService;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Content\ContentSeoScorer;
use App\Services\Content\HumanizerService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * normalizeStructure() must deterministically enforce ordering — the
 * keyphrase-led opening paragraph FIRST, then the "Key takeaways" box — even
 * when the LLM leads the draft with the takeaways box (which would otherwise
 * bury the opener and make the on-page analyzer read the summary bullets as the
 * intro / first-100-words). Prod 2026-07-28.
 */
class ContentArticleProducerStructureTest extends TestCase
{
    private function normalize(string $html, string $h1 = '', bool $withToc = false): string
    {
        // normalizeStructure() is a pure string transform — it never touches the
        // injected services — so build the producer with mocked deps (avoids the
        // real deps' DB access in this no-DB unit test).
        $producer = new ContentArticleProducer(
            Mockery::mock(AiContentBriefService::class),
            new ContentSeoScorer,
            Mockery::mock(HumanizerService::class),
            Mockery::mock(ContentLlmSpendMeter::class),
        );
        $m = new ReflectionMethod($producer, 'normalizeStructure');
        $m->setAccessible(true);

        return $m->invoke($producer, $html, $h1, $withToc);
    }

    public function test_leading_takeaways_box_is_moved_after_the_opener(): void
    {
        // Draft leads with the Key-takeaways box, BEFORE any opening paragraph.
        $html = '<div class="key-takeaways"><h2>Key takeaways</h2><ul><li>Point A.</li><li>Point B.</li></ul></div>'
            .'<h2>Why it matters</h2><p>The keyphrase-led opening paragraph goes here.</p>'
            .'<h2>Details</h2><p>More body content.</p>';

        $out = $this->normalize($html);

        $openerAt = strpos($out, 'The keyphrase-led opening paragraph');
        $boxAt = strpos($out, 'key-takeaways');
        $this->assertNotFalse($openerAt);
        $this->assertNotFalse($boxAt);
        // The opener now precedes the takeaways box.
        $this->assertLessThan($boxAt, $openerAt);
        // The box is not duplicated.
        $this->assertSame(1, substr_count($out, 'class="key-takeaways"'));
    }

    public function test_takeaways_already_after_opener_is_left_in_place(): void
    {
        $html = '<p>The opening paragraph carries the keyphrase.</p>'
            .'<div class="key-takeaways"><h2>Key takeaways</h2><ul><li>Point A.</li></ul></div>'
            .'<h2>Body</h2><p>Content.</p>';

        $out = $this->normalize($html);

        $this->assertLessThan(strpos($out, 'key-takeaways'), strpos($out, 'The opening paragraph'));
        $this->assertSame(1, substr_count($out, 'class="key-takeaways"'));
    }
}
