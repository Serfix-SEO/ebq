<?php

namespace Tests\Feature;

use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two products are sold on two pages. They used to share one grid behind a
 * pill toggle, which first-time visitors never noticed — so half the catalogue
 * was invisible and the mixed numbers read as one confusing price list.
 */
class PricingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_seo_pricing_page_no_longer_mixes_in_the_content_product(): void
    {
        $response = $this->get(route('pricing'))->assertOk();
        $html = $response->getContent();

        // The Alpine product switch is gone entirely.
        $this->assertStringNotContainsString("product === 'content'", $html);
        $this->assertStringNotContainsString("product = 'content'", $html);

        // …replaced by a visible hand-off to the content product's own page.
        $response->assertSee(route('content.pricing'));
        $response->assertSee('Looking for Content AI Autopilot?');
    }

    public function test_content_pricing_page_renders_with_plan_prices_and_visuals(): void
    {
        $cfg = ContentAutopilotConfig::class;

        $response = $this->get(route('content.pricing'))->assertOk();

        $response->assertSee('Content AI Autopilot');
        // Prices come from ContentAutopilotConfig, never hardcoded in the view.
        $response->assertSee('$'.$cfg::displayPrice('monthly'), false);
        $response->assertSee('$'.$cfg::displayPrice('addon_monthly'), false);
        // Product visuals are wired up.
        foreach (['calendar', 'article-score', 'tracker', 'rank-chart'] as $shot) {
            $response->assertSee('images/content/'.$shot.'.webp');
            $this->assertFileExists(public_path('images/content/'.$shot.'.webp'));
        }
        // And it points back at the other product.
        $response->assertSee(route('pricing'));
    }

    /**
     * The content pricing page must sell the OUTCOME, never the machinery: no
     * data vendor, model or infrastructure name belongs on a public page.
     * (Client-owned integrations — their WordPress, their Search Console, their
     * Analytics — are the exception: those are part of what they connect.)
     */
    public function test_public_pages_never_name_our_suppliers(): void
    {
        $forbidden = [
            'DataForSEO', 'Moz', 'Serper', 'Ideogram', 'DeepSeek', 'Mistral',
            'OpenAI', 'GPT-', 'Anthropic', 'Firecrawl', 'OpenPageRank',
            'Keywords Everywhere', 'Common Crawl', 'Stripe', 'Horizon', 'Redis',
        ];

        foreach ([route('content.pricing'), route('pricing'), route('content.landing')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            // Strip the asset/manifest URLs — hashed build filenames are noise here.
            $visible = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $html) ?? $html;
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle, $visible, "$needle must not appear on $url",
                );
            }
        }
    }

    public function test_content_pricing_page_states_the_schema_markup_it_generates(): void
    {
        $this->get(route('content.pricing'))
            ->assertOk()
            ->assertSee('Automatic schema markup + full meta control')
            ->assertSee('Do the articles include schema markup?');
    }

    public function test_both_pricing_pages_are_reachable_from_the_marketing_nav(): void
    {
        foreach ([route('landing'), route('content.landing')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee(route('pricing'))
                ->assertSee(route('content.pricing'));
        }
    }
}
