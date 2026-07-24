<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/api/v1/plans` feeds the WordPress plugin's setup-wizard pricing table.
 * Guards that (a) seeded plans expose a checkout_url when checkout-ready, and
 * (b) the empty-table fallback mirrors the CURRENT plans — no stale slugs like
 * `free`/`startup` or old prices (prod 2026-07-24: the fallback still showed
 * free/pro-$15/startup-$39/agency-$125, all "coming soon").
 */
class PublicPricingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_plans_expose_prices_and_checkout_urls(): void
    {
        $this->seed(PlanSeeder::class);

        $res = $this->getJson('/api/v1/plans')->assertOk();
        $slugs = array_column($res->json('plans'), 'slug');

        $this->assertContains('pro', $slugs);
        $this->assertNotContains('startup', $slugs, 'old slug retired');
        $this->assertNotContains('free', $slugs, 'old slug retired');

        // Every paid, checkout-ready plan must carry a checkout_url (else the
        // plugin renders "Coming soon").
        foreach ($res->json('plans') as $p) {
            $plan = Plan::where('slug', $p['slug'])->first();
            if ($plan && $plan->isCheckoutReady()) {
                $this->assertNotNull($p['checkout_url'], "{$p['slug']} checkout-ready → needs checkout_url");
            }
        }
    }

    public function test_empty_table_fallback_uses_current_plans_not_stale_ones(): void
    {
        Plan::query()->delete(); // force the fallback path

        $res = $this->getJson('/api/v1/plans')->assertOk();
        $slugs = array_column($res->json('plans'), 'slug');
        $byslug = collect($res->json('plans'))->keyBy('slug');

        // Current lineup, no legacy slugs / prices.
        $this->assertSame(['trial', 'solo', 'pro', 'agency', 'enterprise'], $slugs);
        $this->assertSame(19, $byslug['solo']['price_monthly_usd']);
        $this->assertSame(49, $byslug['pro']['price_monthly_usd']);
        $this->assertSame(99, $byslug['agency']['price_monthly_usd']);
    }
}
