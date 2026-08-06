<?php

namespace Tests\Feature\Content;

use App\Models\User;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Google Ads conversion for a Content Autopilot subscription.
 *
 * Two properties matter more than the tag itself, because both failures are
 * invisible until the ad spend has already been misallocated:
 *  - it fires ONCE. Stripe's success URL is a plain GET a customer can refresh
 *    or bookmark, so the event is queued as one-request flash data rather than
 *    rendered from any persistent state.
 *  - it fires only for a LIVE subscription. Stripe redirects on its own
 *    schedule, and an abandoned or still-processing checkout must never be
 *    reported to Google as a sale.
 */
class ContentAdsConversionTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_TO = 'AW-18374890122/YhmCCK3Vm90cEIql6rlE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** Comped access is not a purchase — it must not be reported as one. */
    public function test_no_conversion_is_queued_without_a_live_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('content.billing.success'))
            ->assertRedirect();

        $this->assertNull(session('ads_conversion'), 'an unpaid visitor must never be counted as a conversion');
    }

    public function test_a_live_subscription_queues_the_conversion_once(): void
    {
        $user = User::factory()->create();
        // A comp grant makes hasContentSubscription() false, so subscribe for
        // real via the same path Cashier uses.
        $user->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_test_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('content.billing.success'));
        $response->assertRedirect();

        $conv = session('ads_conversion');
        $this->assertIsArray($conv, 'a paid subscription must queue the conversion');
        $this->assertSame(self::SEND_TO, $conv['send_to']);
        // The real subscription amount, in the currency Stripe charges in —
        // not a flat placeholder. Stripe is unreachable in tests, so this
        // exercises the config fallback: the monthly plan's list price.
        $this->assertSame('USD', $conv['currency']);
        $this->assertSame(
            (float) \App\Support\ContentAutopilotConfig::displayPrice('monthly'),
            $conv['value'],
        );
        // Google Ads de-duplicates on this, so it must carry the real id.
        $this->assertSame('sub_test_123', $conv['transaction_id']);
    }

    /**
     * The yearly plan is DISPLAYED per month ($29) but CHARGED once a year, so
     * the fallback has to multiply. Reporting 29 for a $348 sale would tell
     * Smart Bidding an annual customer is worth less than a monthly one.
     */
    public function test_the_annual_plan_reports_the_yearly_charge_not_the_monthly_display_price(): void
    {
        // Price ids live in Settings, which the test database starts empty.
        \App\Models\Setting::set('content.pricing.annual_price_id', 'price_annual_test');
        $annualPriceId = \App\Support\ContentAutopilotConfig::priceId('annual');
        $this->assertSame('price_annual_test', $annualPriceId);

        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_test_year',
            'stripe_status' => 'active',
            'stripe_price' => $annualPriceId,
            'quantity' => 1,
        ]);

        $this->actingAs($user)->get(route('content.billing.success'))->assertRedirect();

        $conv = session('ads_conversion');
        $this->assertSame(
            (float) \App\Support\ContentAutopilotConfig::displayPrice('annual') * 12,
            $conv['value'],
        );
        $this->assertSame('USD', $conv['currency']);
    }

    /**
     * Flash data is consumed by the request that renders it. Following the
     * redirect once fires the tag; landing on the same page again must not.
     */
    public function test_the_tag_renders_on_the_landing_page_and_not_on_a_refresh(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_test_456',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $landing = $this->actingAs($user)
            ->get(route('content.billing.success'))
            ->assertRedirect();

        $first = $this->actingAs($user)->get($landing->headers->get('Location'));
        $first->assertOk();
        $first->assertSee(self::SEND_TO, false);
        $first->assertSee("'event', 'conversion'", false);

        // Same URL, no flash left: the tag is gone.
        $this->actingAs($user)
            ->get($landing->headers->get('Location'))
            ->assertOk()
            ->assertDontSee(self::SEND_TO, false);
    }

    /** The Ads tag has to be configured, or the event is silently dropped. */
    public function test_the_google_ads_tag_is_configured_alongside_analytics(): void
    {
        $html = $this->get(route('content.landing'))->assertOk()->getContent();

        $this->assertStringContainsString("gtag('config', 'AW-18374890122')", $html);
        $this->assertStringContainsString("gtag('config', 'G-PS1SPVQXZR')", $html);
        $this->assertStringContainsString('function gtag_report_conversion(url)', $html);
    }
}
