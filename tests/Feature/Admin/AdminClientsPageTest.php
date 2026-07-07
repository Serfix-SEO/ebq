<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /admin/clients additions (2026-07-07): search by website domain +
 * "Trial → paid" and "Trial + card added" KPI cards.
 */
class AdminClientsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_search_finds_client_by_website_domain(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner-x@example.com']);
        Website::factory()->create(['user_id' => $owner->id, 'domain' => 'findme-by-domain.test']);
        $other = User::factory()->create(['name' => 'Other Person', 'email' => 'other-y@example.com']);
        Website::factory()->create(['user_id' => $other->id, 'domain' => 'unrelated.test']);

        $this->actingAs($this->admin())
            ->get(route('admin.clients.index', ['q' => 'findme-by-domain']))
            ->assertOk()
            ->assertSee('owner-x@example.com')
            ->assertDontSee('other-y@example.com');
    }

    public function test_kpi_cards_count_paid_and_trial_with_card(): void
    {
        // Paid: active Cashier subscription.
        $paid = User::factory()->create();
        DB::table('subscriptions')->insert([
            'id' => 1,
            'user_id' => $paid->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Trial with a saved card, no subscription.
        User::factory()->create(['pm_type' => 'visa', 'pm_last_four' => '4242']);

        // Plain trial user: neither card nor sub — must count in neither KPI.
        User::factory()->create();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Trial → paid')
            ->assertSee('Trial + card added');

        $summary = $response->viewData('summary');
        $this->assertSame(1, $summary['converted_paid']);
        $this->assertSame(1, $summary['trial_with_card']);
    }

    public function test_comped_plan_does_not_count_as_converted(): void
    {
        // Admin force-apply sets current_plan_slug without a Stripe sub —
        // must NOT count as a trial->paid conversion.
        User::factory()->create(['current_plan_slug' => 'agency']);

        $response = $this->actingAs($this->admin())->get(route('admin.clients.index'))->assertOk();
        $this->assertSame(0, $response->viewData('summary')['converted_paid']);
    }
}
