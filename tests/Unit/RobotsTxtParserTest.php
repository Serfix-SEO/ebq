<?php

namespace Tests\Unit;

use App\Support\Crawler\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    public function test_disallowed_path_under_wildcard_group_is_blocked(): void
    {
        $robots = "User-agent: *\nDisallow: /private/\n";

        $this->assertTrue(RobotsTxtParser::isBlocked($robots, '/private/page'));
        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/public/page'));
    }

    public function test_googlebot_specific_group_overrides_wildcard(): void
    {
        $robots = "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow: /no-google/\n";

        // Wildcard blocks everything, but our crawler matches the more specific
        // Googlebot group, which only blocks /no-google/.
        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/anything'));
        $this->assertTrue(RobotsTxtParser::isBlocked($robots, '/no-google/page'));
    }

    public function test_longer_allow_overrides_shorter_disallow(): void
    {
        $robots = "User-agent: *\nDisallow: /shop/\nAllow: /shop/public/\n";

        $this->assertTrue(RobotsTxtParser::isBlocked($robots, '/shop/private'));
        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/shop/public/item'));
    }

    public function test_wildcard_and_end_anchor_patterns(): void
    {
        $robots = "User-agent: *\nDisallow: /*.pdf$\n";

        $this->assertTrue(RobotsTxtParser::isBlocked($robots, '/files/report.pdf'));
        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/files/report.pdf.html'));
    }

    public function test_no_matching_group_means_nothing_is_blocked(): void
    {
        $robots = "User-agent: Bingbot\nDisallow: /\n";

        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/anything'));
    }
}
