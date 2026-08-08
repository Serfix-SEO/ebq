<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin home dashboard (/admin): daily activity, customer segments,
 * revenue. Stripe is unreachable in tests, so every money tile must degrade
 * to "—" — a Stripe outage must never break the admin home.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_it_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();
    }

    public function test_it_counts_todays_activity_and_segments(): void
    {
        // Two customers today, one yesterday; one paid, one on trial.
        $paid = User::factory()->create();
        $paid->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION, 'stripe_id' => 'sub_x',
            'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
        ]);
        $trialist = User::factory()->create();
        app(ContentEntitlements::class)->startTrial($trialist, Website::factory()->for($trialist)->create());
        User::factory()->create(['created_at' => now()->subDay()->subHour()]);

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Signups today', $html);
        $this->assertStringContainsString('Trials started today', $html);
        $this->assertStringContainsString('Total customers', $html);
        $this->assertStringContainsString('Latest signups', $html);
        // The paid customer shows in the subscriptions feed.
        $this->assertStringContainsString($paid->email, $html);
    }

    /**
     * The counting rule that motivated this page: internal accounts must not
     * inflate customer numbers. The admin's own subscription made "Trial →
     * paid 2" read as two conversions when there was one (2026-08-08).
     */
    public function test_admin_accounts_are_not_counted_as_customers(): void
    {
        $admin = $this->admin();
        $admin->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION, 'stripe_id' => 'sub_admin',
            'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Total customers 0 (the only user is the admin) but 2 internal
        // accounts is wrong — 1. Assert through the rendered tiles.
        $this->assertMatchesRegularExpression(
            '/Total customers<\/p>\s*<p[^>]*>0</',
            $html,
            'the admin must not count as a customer',
        );
        // Their subscription still shows in the feed, marked internal.
        $this->assertStringContainsString('internal', $html);
    }

    /** Every tile links to a drill-down listing the records behind its number. */
    public function test_tiles_link_to_drill_downs_and_drills_list_the_records(): void
    {
        $paid = User::factory()->create(['name' => 'Paid Customer']);
        $paid->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION, 'stripe_id' => 'sub_d',
            'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
        ]);
        $free = User::factory()->create(['name' => 'Free Customer']);

        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        foreach (['customers', 'paid', 'on-trial', 'free', 'with-card', 'disabled',
            'signups-today', 'trials-today', 'articles-today', 'leads-today',
            'payments-today', 'payments-month', 'websites', 'articles-all', 'internal'] as $metric) {
            $this->assertStringContainsString(route('admin.dashboard.drill', $metric), $html, "tile links to {$metric}");
        }

        // The drill shows the records the tile counted — and only those.
        $this->actingAs($admin)->get(route('admin.dashboard.drill', 'paid'))
            ->assertOk()
            ->assertSee('Paid Customer')
            ->assertDontSee('Free Customer');
        $this->actingAs($admin)->get(route('admin.dashboard.drill', 'free'))
            ->assertOk()
            ->assertSee('Free Customer')
            ->assertDontSee('Paid Customer');

        // Unknown metric is a 404, not a 500.
        $this->actingAs($admin)->get(route('admin.dashboard.drill', 'nonsense'))->assertNotFound();
        // And the drill is admin-only like the dashboard.
        $this->actingAs($free)->get(route('admin.dashboard.drill', 'paid'))->assertForbidden();
    }

    public function test_stripe_being_unreachable_degrades_not_breaks(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Stripe unavailable', $html);
        $this->assertStringContainsString('MRR (Stripe)', $html);
    }

    public function test_published_articles_count_in_the_daily_tiles(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'status' => ContentTopic::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/Articles published today<\/p>\s*<p[^>]*>1</',
            $html,
        );
    }
}
