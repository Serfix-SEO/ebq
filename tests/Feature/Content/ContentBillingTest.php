<?php

namespace Tests\Feature\Content;

use App\Http\Controllers\StripeWebhookController;
use App\Models\ContentPlan;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBillingTest extends TestCase
{
    use RefreshDatabase;

    private function ent(): ContentEntitlements
    {
        return new ContentEntitlements();
    }

    /** Create an active `content` subscription with an addon item of $qty. */
    private function contentSub(User $user, int $addonQty = 0): void
    {
        Setting::set('content.pricing.addon_monthly_price_id', 'price_addon_m');
        $sub = $user->subscriptions()->create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_base_m',
            'quantity' => 1,
        ]);
        $sub->items()->create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'stripe_id' => 'si_base_'.uniqid(), 'stripe_product' => 'prod_base',
            'stripe_price' => 'price_base_m', 'quantity' => 1,
        ]);
        if ($addonQty > 0) {
            $sub->items()->create([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'stripe_id' => 'si_addon_'.uniqid(), 'stripe_product' => 'prod_addon',
                'stripe_price' => 'price_addon_m', 'quantity' => $addonQty,
            ]);
        }
        $user->load('subscriptions.items');
    }

    public function test_sites_allowed_is_base_plus_addon_quantity(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_x']);
        $this->contentSub($user, addonQty: 2);

        $this->assertTrue($this->ent()->hasContentSubscription($user));
        $this->assertSame(3, $this->ent()->sitesAllowed($user)); // 1 base + 2 addon
    }

    public function test_reconcile_coverage_clamps_to_allowance(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_y']);
        $this->contentSub($user, addonQty: 0); // allows 1 site

        $sites = collect(range(1, 3))->map(fn () => Website::factory()->for($user)->create());
        foreach ($sites as $s) {
            ContentPlan::query()->create(['website_id' => $s->id, 'billing_covered_at' => now()]);
        }
        $this->assertSame(3, $this->ent()->sitesCovered($user));

        $this->ent()->reconcileCoverage($user);

        $this->assertSame(1, $this->ent()->sitesCovered($user)); // clamped to base slot
    }

    public function test_content_subscription_does_not_change_dashboard_plan_slug(): void
    {
        // Dashboard 'pro' plan mapped to a price + a user snapshot on it.
        Plan::query()->create([
            'slug' => 'pro', 'name' => 'Pro', 'is_active' => true,
            'stripe_price_id_monthly' => 'price_pro_m',
        ]);
        $user = User::factory()->create(['stripe_id' => 'cus_z', 'current_plan_slug' => 'pro']);
        // Active default sub on the pro price.
        $user->subscriptions()->create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'type' => 'default', 'stripe_id' => 'sub_def', 'stripe_status' => 'active',
            'stripe_price' => 'price_pro_m', 'quantity' => 1,
        ]);
        // A content sub on a content price that is NOT in the plans table.
        $this->contentSub($user);

        // Fire the private plan-slug sync the webhook uses.
        $ctrl = new StripeWebhookController();
        $ref = new \ReflectionMethod($ctrl, 'syncPlanSlugFromStripeCustomer');
        $ref->setAccessible(true);
        $ref->invoke($ctrl, 'cus_z');

        // Content subscription must not have touched the dashboard plan slug.
        $this->assertSame('pro', $user->fresh()->current_plan_slug);
    }
}
