<?php

namespace Tests\Unit\Content;

use App\Services\Content\ContentTopicPlanner;
use Tests\TestCase;

class FreshenYearsTest extends TestCase
{
    public function test_stale_recent_years_become_current(): void
    {
        $year = now()->year;

        $this->assertSame(
            "Top 50 PUBG Stylish Names for Girls in {$year}",
            ContentTopicPlanner::freshenYears('Top 50 PUBG Stylish Names for Girls in 2024')
        );
        $this->assertSame(
            "best tools {$year} guide",
            ContentTopicPlanner::freshenYears('best tools 2025 guide')
        );
    }

    public function test_current_year_and_history_untouched(): void
    {
        $year = now()->year;

        $this->assertSame(
            "Guide for {$year}",
            ContentTopicPlanner::freshenYears("Guide for {$year}")
        );
        // Pre-2020 years are deliberate historical references.
        $this->assertSame(
            'PUBG launched in 2017 and changed gaming',
            ContentTopicPlanner::freshenYears('PUBG launched in 2017 and changed gaming')
        );
        // Non-year numbers untouched.
        $this->assertSame(
            'Top 2000 names list',
            ContentTopicPlanner::freshenYears('Top 2000 names list')
        );
    }
}
