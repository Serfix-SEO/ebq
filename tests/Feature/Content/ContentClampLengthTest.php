<?php

namespace Tests\Feature\Content;

use App\Services\Content\ContentArticleProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (2026-08-23): clampLength() ended with a byte-based rtrim whose
 * charlist held the en/em-dash bytes (E2 80 93/94) — also the trailing bytes
 * of many Devanagari/CJK characters. A Hindi meta_description ending in ी
 * (E0 A5 80) lost its final byte, producing invalid UTF-8 that MySQL
 * rejected with error 1366 and failing the whole ProduceContentArticleJob.
 */
class ContentClampLengthTest extends TestCase
{
    use RefreshDatabase;

    private function clamp(string $s, int $max): string
    {
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'clampLength');
        $m->setAccessible(true);

        return $m->invoke(app(ContentArticleProducer::class), $s, $max);
    }

    public function test_clamped_hindi_text_stays_valid_utf8(): void
    {
        // Build a string whose clamp boundary lands right after ी (E0 A5 80).
        $hindi = str_repeat('क', 150).' साथ ही'.str_repeat(' और भी लंबा वाक्य', 10);

        foreach ([155, 156, 157, 158] as $max) {
            $out = $this->clamp($hindi, $max);
            $this->assertTrue(mb_check_encoding($out, 'UTF-8'), "clamp at {$max} produced invalid UTF-8");
        }
    }

    public function test_trailing_dashes_and_punctuation_still_trimmed(): void
    {
        $out = $this->clamp(str_repeat('word ', 40).'ending —', 200);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringEndsNotWith('—', $out);
        $this->assertStringEndsNotWith('-', $out);
        $this->assertStringEndsNotWith(',', $out);
    }
}
