<?php

namespace Tests\Feature\Content;

use App\Jobs\CrawlWebsitePagesJob;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\CrawlFrontierBuilder;
use App\Services\WebsiteAttachService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The two products are independent: SERFIX SEO (dashboard plan, website limit,
 * freeze) and CONTENT AUTOPILOT (own subscription, own per-site coverage).
 *
 * A dashboard plan limit must never disable something the customer separately
 * pays for. Prod 2026-07-21 showed the coupling twice — a covered site frozen
 * out of its own feature flag, and the crawl that feeds it being skipped.
 */
class ContentProductIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: Website} A frozen site the user pays content for. */
    private function frozenContentSite(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        Website::factory()->for($user)->create([
            'domain' => 'dashboard-site.test',
            'created_at' => now()->subDay(),
        ]);
        $content = Website::factory()->for($user)->create(['domain' => 'content-site.test']);
        ContentPlan::factory()->create([
            'website_id' => $content->id,
            'billing_covered_at' => now(),
        ]);

        return [$user, $content->fresh()];
    }

    /**
     * The crawl feeds the business profile, internal linking, cannibalization
     * checks and keyword seeds. Skipping it on a paid content site degrades the
     * product silently — no error, just steadily worse articles.
     */
    public function test_a_frozen_content_site_is_still_crawled(): void
    {
        [, $website] = $this->frozenContentSite();
        $this->assertTrue($website->isFrozen(), 'precondition');

        Log::spy();
        (new CrawlWebsitePagesJob($website->id))->handle(app(CrawlFrontierBuilder::class));

        Log::shouldNotHaveReceived('info', ["CrawlWebsitePagesJob: skipping frozen website {$website->id}"]);
    }

    /** A frozen site with NO content entitlement is still skipped, as before. */
    public function test_a_frozen_site_without_content_is_still_skipped(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create(['domain' => 'first.test', 'created_at' => now()->subDay()]);
        $extra = Website::factory()->for($user)->create(['domain' => 'extra.test'])->fresh();

        $this->assertTrue($extra->isFrozen());

        Log::spy();
        (new CrawlWebsitePagesJob($extra->id))->handle(app(CrawlFrontierBuilder::class));

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m) => str_contains((string) $m, 'skipping frozen website'))
            ->once();
    }

    /**
     * Paying the per-extra-site addon must actually let you add that site.
     * Otherwise the customer is billed for a slot the dashboard limit blocks.
     */
    public function test_a_free_content_slot_allows_adding_a_website_past_the_dashboard_limit(): void
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        // Dashboard allowance consumed, and NOT used by content.
        Website::factory()->for($user)->create(['domain' => 'dashboard-site.test']);

        $this->assertTrue($user->hasFreeContentSlot(), 'trial covers one site, none covered yet');
        $this->assertTrue($user->canAddWebsite(), 'the paid content slot must permit the add');
    }

    /** With every content slot already used, the dashboard limit still applies. */
    public function test_no_free_content_slot_means_the_dashboard_limit_still_blocks(): void
    {
        [$user] = $this->frozenContentSite();   // trial = 1 site, already covered

        $this->assertFalse($user->hasFreeContentSlot());
        $this->assertFalse($user->canAddWebsite());

        $result = app(WebsiteAttachService::class)->attach($user, 'another-one.test');
        $this->assertSame('plan_limit', $result['blocked']);
    }

    /** A user with no content product at all is unaffected by any of this. */
    public function test_a_plain_seo_user_is_unaffected(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create(['domain' => 'only-site.test']);

        $this->assertFalse($user->hasFreeContentSlot());
        $this->assertFalse($user->canAddWebsite());
    }
}
