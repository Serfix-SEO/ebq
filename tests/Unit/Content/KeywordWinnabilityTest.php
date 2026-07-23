<?php

namespace Tests\Unit\Content;

use App\Services\Content\KeywordWinnability;
use PHPUnit\Framework\TestCase;

class KeywordWinnabilityTest extends TestCase
{
    public function test_ceiling_scales_with_authority_and_assumes_small_when_unknown(): void
    {
        $this->assertSame(30, KeywordWinnability::difficultyCeiling(null));
        $this->assertSame(20, KeywordWinnability::difficultyCeiling(5));
        $this->assertSame(30, KeywordWinnability::difficultyCeiling(15));
        $this->assertSame(40, KeywordWinnability::difficultyCeiling(32));
        $this->assertSame(55, KeywordWinnability::difficultyCeiling(45));
        $this->assertSame(70, KeywordWinnability::difficultyCeiling(80));
    }

    public function test_easy_keyword_beats_hard_keyword_for_a_small_site(): void
    {
        // DA 15 (ceiling 30): difficulty 12 is comfortably winnable,
        // difficulty 60 is a head term it will never rank for.
        $easy = KeywordWinnability::score(12, 'unknown', 15);
        $hard = KeywordWinnability::score(60, 'unknown', 15);

        $this->assertGreaterThan(0.8, $easy);
        $this->assertLessThan(0.3, $hard);
        $this->assertGreaterThan(0.0, $hard, 'never zero — a tiny pool must still rank something');
    }

    public function test_same_keyword_becomes_winnable_as_authority_grows(): void
    {
        $small = KeywordWinnability::score(50, 'unknown', 12);
        $big = KeywordWinnability::score(50, 'unknown', 65);

        $this->assertGreaterThan($small, $big);
        $this->assertGreaterThanOrEqual(0.7, $big);
    }

    public function test_without_difficulty_the_competition_tier_decides(): void
    {
        $this->assertSame(0.9, KeywordWinnability::score(null, 'low', null));
        $this->assertSame(0.6, KeywordWinnability::score(null, 'medium', null));
        $this->assertSame(0.3, KeywordWinnability::score(null, 'high', null));
        $this->assertSame(0.5, KeywordWinnability::score(null, 'unknown', null));
    }
}
