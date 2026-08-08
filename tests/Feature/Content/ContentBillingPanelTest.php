<?php

namespace Tests\Feature\Content;

use App\Livewire\Billing\ContentSubscriptionPanel;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Content Autopilot billing on the account billing page.
 *
 * Content is a separate Cashier subscription (`content`), so the dashboard
 * panel — which reads `default` — showed a paying content customer nothing:
 * no plan, no amount, no renewal, no way to cancel. A content-only client saw
 * a page telling them their trial had expired while they were being billed
 * every month (prod 2026-08-08).
 */
class ContentBillingPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function subscriber(): User
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create(['domain' => 'daoexample.com']);
        $user->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_content_1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_content_monthly',
            'quantity' => 1,
        ]);
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);

        return $user;
    }

    public function test_a_paying_content_customer_sees_their_plan_on_the_billing_page(): void
    {
        $user = $this->subscriber();

        $this->actingAs($user)->get(route('billing.show'))
            ->assertOk()
            ->assertSee('Content AI Autopilot')
            // The amount they are actually charged. Stripe is unreachable in
            // tests, so this exercises the configured-price fallback.
            ->assertSee(number_format((float) ContentAutopilotConfig::displayPrice('monthly'), 2))
            ->assertSee('daoexample.com')
            ->assertSee('Cancel content plan');
    }

    /** The two products are billed apart; the page must say so. */
    public function test_the_panel_says_it_is_separate_from_the_seo_plan(): void
    {
        $this->actingAs($this->subscriber())->get(route('billing.show'))
            ->assertSee('Billed separately from your SEO platform plan.');
    }

    /**
     * An SEO-only customer gets no content SUMMARY card — there is no
     * subscription to summarise — but the content plans stay browsable under
     * their own tab. Both products are listed; neither is asserted at anyone.
     */
    public function test_a_user_with_no_content_subscription_sees_plans_but_no_summary_card(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create();

        $this->actingAs($user)->get(route('billing.show'))
            ->assertOk()
            ->assertDontSee('Content AI Autopilot')
            ->assertSee('Content AI')
            ->assertSee('$'.ContentAutopilotConfig::displayPrice('monthly'));
    }

    /**
     * The page is BILLING first, pricing second. Both pricing tables used to
     * sit between the two summaries, so a paying customer scrolled past a wall
     * of plan cards to find what they were actually paying for.
     */
    public function test_subscriptions_come_before_the_pricing_tables(): void
    {
        $html = $this->actingAs($this->subscriber())->get(route('billing.show'))
            ->assertOk()->getContent();

        $plansHeading = strpos($html, '>'.__('Plans').'<');
        $this->assertNotFalse($plansHeading);
        $this->assertLessThan($plansHeading, strpos($html, 'Content AI Autopilot'), 'content billing above the plans');
        $this->assertLessThan($plansHeading, strpos($html, 'This month'), 'SEO billing above the plans');
    }

    /** Both products' pricing is reachable from the one page. */
    public function test_both_products_pricing_is_on_the_page_behind_tabs(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('billing.show'))->assertOk()->getContent();

        // SEO plan grid...
        $this->assertStringContainsString('Available plans', $html);
        // ...and the content grid, with its extra-website pricing.
        $this->assertStringContainsString('Extra websites', $html);
        $this->assertStringContainsString('$'.ContentAutopilotConfig::displayPrice('addon_monthly'), $html);
    }

    /**
     * Cancel is a two-step, and the copy has to make the blast radius clear:
     * cancelling content must not read as cancelling the SEO plan too.
     */
    public function test_cancelling_is_confirmed_and_scoped_to_the_content_product(): void
    {
        Livewire::actingAs($this->subscriber())
            ->test(ContentSubscriptionPanel::class)
            ->assertDontSee('Yes, cancel at period end')
            ->call('openCancelConfirm')
            ->assertSee('Your SEO platform plan is not affected.')
            ->assertSee('Yes, cancel at period end');
    }

    /** A cancelled-but-in-grace plan offers the way back. */
    public function test_a_cancelled_plan_offers_resume(): void
    {
        $user = $this->subscriber();
        $user->subscription(ContentEntitlements::SUBSCRIPTION)
            ->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->addDays(10)])->save();

        Livewire::actingAs($user->fresh())
            ->test(ContentSubscriptionPanel::class)
            ->assertSee('Resume content plan')
            ->assertDontSee('Cancel content plan');
    }

    /**
     * A content-only customer's paid product comes FIRST. Below the SEO plan
     * grid it sits under four upgrade cards on mobile — the one subscription
     * they are charged for, last on the page.
     */
    public function test_a_content_only_customer_sees_their_product_above_the_seo_plans(): void
    {
        $user = $this->subscriber();
        $user->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->assertTrue($user->fresh()->isContentOnly(), 'precondition: content sub, lapsed dashboard trial');

        $html = $this->actingAs($user->fresh())->get(route('billing.show'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Available plans'),
            strpos($html, 'Content AI Autopilot'),
            'the product they pay for must come before the plans they do not',
        );
    }

    /** A trialist sees the trial, its end date, and the way to convert. */
    public function test_a_content_trialist_sees_the_trial_state(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        app(ContentEntitlements::class)->startTrial($user, $website);

        Livewire::actingAs($user->fresh())
            ->test(ContentSubscriptionPanel::class)
            ->assertSee('Free trial')
            ->assertSee('Choose a content plan');
    }
}
