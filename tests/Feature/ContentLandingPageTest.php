<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Serfix\ContentAi\Models\Article;
use Tests\TestCase;

/**
 * /content-autopilot is the page the content product is sold from. It was
 * rebuilt on 2026-07-30 from a short feature list into a full sales page, and
 * these tests pin the parts that silently rot: the product visuals, the
 * interactive tour embed, and — above all — that every price on the page is
 * READ from ContentAutopilotConfig rather than typed into the Blade.
 */
class ContentLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_the_hero_shows_the_real_calendar_screen_and_the_other_product_visuals(): void
    {
        $response = $this->get(route('content.landing'))->assertOk();

        foreach (['calendar', 'article-score', 'rank-chart'] as $shot) {
            $response->assertSee('images/content/'.$shot.'.webp');
            $this->assertFileExists(public_path('images/content/'.$shot.'.webp'));
        }
    }

    /** "See it in action" is an interactive walkthrough, not a screenshot. */
    public function test_the_interactive_product_tour_is_embedded(): void
    {
        $html = $this->get(route('content.landing'))->assertOk()->getContent();

        $this->assertStringContainsString('app.supademo.com/embed/', $html);
        // The vendor snippet's legacy fullscreen attributes are deliberately
        // dropped: `mozallowfullscreen` contains a supplier name that
        // PricingPagesTest forbids on public pages.
        $this->assertStringNotContainsString('mozallowfullscreen', $html);
    }

    /**
     * Every number on the page must move when the admin price moves. Prices
     * live in Setting rows (ContentAutopilotConfig reads them), so they are
     * written through Setting::set — which busts the read cache for us.
     */
    public function test_all_prices_come_from_the_config_including_the_extra_site_table(): void
    {
        Setting::set('content.pricing.monthly_usd', 44);
        Setting::set('content.pricing.annual_usd', 33);
        Setting::set('content.pricing.addon_monthly_usd', 17);
        Setting::set('content.pricing.addon_annual_usd', 12);

        // Guard the fixture: if these ever stop feeding displayPrice(), the
        // assertions below would pass against hardcoded Blade numbers.
        $this->assertSame(44, ContentAutopilotConfig::displayPrice('monthly'));
        $this->assertSame(12, ContentAutopilotConfig::displayPrice('addon_annual'));

        $response = $this->get(route('content.landing'))->assertOk();

        $response->assertSee('$44', false);          // monthly plan
        $response->assertSee('$33', false);          // yearly plan
        $response->assertSee('$17', false);          // each extra site, monthly
        $response->assertSee('$12', false);          // each extra site, yearly
        $response->assertSee('$78', false);          // worked example: 44 + 2×17
        $response->assertSee('$57', false);          // worked example: 33 + 2×12
        $response->assertSee('Each additional website');
    }

    /** The yearly saving is derived from the two prices, never written down. */
    public function test_the_yearly_saving_badge_is_derived_from_the_prices(): void
    {
        Setting::set('content.pricing.monthly_usd', 40);
        Setting::set('content.pricing.annual_usd', 30);

        $this->get(route('content.landing'))->assertOk()->assertSee('save 25%');
    }

    /**
     * The blog strip is real proof — our own articles, published by this very
     * product. It must render them, and must not blow up when there are none.
     */
    public function test_the_blog_strip_shows_published_articles_and_hides_itself_when_empty(): void
    {
        $this->get(route('content.landing'))
            ->assertOk()
            ->assertDontSee('Latest from the blog');

        Article::create([
            'slug' => 'how-to-rank-in-ai-search',
            'title' => 'How to Rank in the Era of AI Search',
            'html' => '<p>A worked example.</p>',
            'status' => Article::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
        // A draft must never leak onto a public page.
        Article::create([
            'slug' => 'unfinished-draft',
            'title' => 'Unfinished Draft',
            'html' => '<p>nope</p>',
            'status' => Article::STATUS_DRAFT,
        ]);

        $this->get(route('content.landing'))
            ->assertOk()
            ->assertSee('Latest from the blog')
            ->assertSee('How to Rank in the Era of AI Search')
            ->assertDontSee('Unfinished Draft');
    }

    /** Trial terms are config-driven too — the free-trial promise must be true. */
    public function test_the_trial_terms_track_the_configured_limits(): void
    {
        Setting::set('content.limits.trial_days', 7);
        Setting::set('content.limits.trial_articles', 4);

        $this->get(route('content.landing'))
            ->assertOk()
            ->assertSee('7-day free trial')
            ->assertSee('4 free articles');
    }
}
