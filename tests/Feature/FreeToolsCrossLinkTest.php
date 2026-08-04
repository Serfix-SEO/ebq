<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four free tools are the top of the funnel, and most visitors arrive on
 * one of them straight from search rather than via the home page. Until
 * 2026-08-02 only the home page listed the whole set; a tool page carried a
 * single "Also free: …" pill, so someone who landed on the rank checker had no
 * way to discover the other three.
 *
 * Every page now renders the same partial (`partials/free-tools-row`), which is
 * what makes this testable as one rule instead of four.
 */
class FreeToolsCrossLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TOOLS = [
        'tools.pagespeed',
        'tools.audit',
        'tools.rank-tracker',
        'tools.keyword-volume',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_every_free_tool_page_links_to_every_other_free_tool(): void
    {
        foreach (self::TOOLS as $current) {
            $html = $this->get(route($current))->assertOk()->getContent();

            foreach (self::TOOLS as $other) {
                if ($other === $current) {
                    continue;
                }
                $this->assertStringContainsString(
                    'href="'.route($other).'"',
                    $html,
                    route($current).' must link to '.route($other),
                );
            }
        }
    }

    /** A tool never advertises itself in its own row of chips. */
    public function test_a_tool_page_does_not_link_to_itself_in_the_tools_row(): void
    {
        foreach (self::TOOLS as $current) {
            $html = $this->get(route($current))->assertOk()->getContent();

            // The chip markup is unique to the row, so counting it isolates the
            // row from the page's canonical/schema self-references.
            $chips = substr_count($html, 'rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5');
            // Exactly three chips per row × two rows. A self-link would make it
            // four per row, so the count is the self-exclusion check.
            $this->assertSame(
                (count(self::TOOLS) - 1) * 2,
                $chips,
                route($current).' should render the other three tools twice (header + footer), and never itself',
            );
        }
    }

    /** The home page keeps the full set — it is the one page that lists all four. */
    public function test_the_home_page_still_lists_all_four_tools(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        foreach (self::TOOLS as $tool) {
            $this->assertStringContainsString('href="'.route($tool).'"', $html);
        }
    }
}
