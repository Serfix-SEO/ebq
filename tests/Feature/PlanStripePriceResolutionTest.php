<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe price → plan/interval resolution (used by the billing webhook,
 * User::planFromSubscription and the swap flow). Open coverage gap since the
 * 2026-07-06 monthly-billing fix; also pins the unknown-price → NULL interval
 * rule (a legacy/renamed price must fail safe, never default to 'annual' and
 * silently flip a monthly subscriber to yearly billing on swap).
 */
class PlanStripePriceResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create([
            'slug' => 'pro', 'name' => 'Pro',
            'price_monthly_usd' => 49, 'price_yearly_usd' => 468,
            'stripe_price_id_monthly' => 'price_month_123',
            'stripe_price_id_yearly' => 'price_year_456',
        ]);
    }

    public function test_find_by_stripe_price_matches_both_intervals(): void
    {
        $plan = $this->plan();

        $this->assertTrue($plan->is(Plan::findByStripePrice('price_month_123')));
        $this->assertTrue($plan->is(Plan::findByStripePrice('price_year_456')));
        $this->assertNull(Plan::findByStripePrice('price_unknown'));
        $this->assertNull(Plan::findByStripePrice(null));
        $this->assertNull(Plan::findByStripePrice(''));
    }

    public function test_interval_resolution_and_unknown_price_is_null_not_annual(): void
    {
        $this->plan();

        $this->assertSame('monthly', Plan::intervalForStripePrice('price_month_123'));
        $this->assertSame('annual', Plan::intervalForStripePrice('price_year_456'));
        // The regression this file exists for:
        $this->assertNull(Plan::intervalForStripePrice('price_legacy_renamed'));
        $this->assertNull(Plan::intervalForStripePrice(null));
    }
}
