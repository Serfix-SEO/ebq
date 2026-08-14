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

    public function test_admin_can_comp_content_autopilot_free_sites(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $client = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'trial',
                'content_comp_sites' => 3,
                'content_comp_until' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect(route('admin.clients.index'));

        $client->refresh();
        $this->assertSame(3, (int) $client->content_comp_sites);
        $this->assertNotNull($client->content_comp_until);
        // Grant takes effect through the entitlement layer.
        $this->assertTrue(app(\App\Services\Content\ContentEntitlements::class)->hasContentAccess($client));
        $this->assertSame(3, app(\App\Services\Content\ContentEntitlements::class)->sitesAllowed($client));
    }

    public function test_blank_expiry_is_a_permanent_content_comp(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $client = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name, 'email' => $client->email, 'plan_slug' => 'trial',
                'content_comp_sites' => 1, 'content_comp_until' => '',
            ])->assertRedirect();

        $client->refresh();
        $this->assertSame(1, (int) $client->content_comp_sites);
        $this->assertNull($client->content_comp_until, 'blank date = permanent');
    }

    /**
     * The list renders twice: cards below the md breakpoint, the table above
     * it. Both must carry the client, and every id crossing into an Alpine
     * expression must be a quoted JS string — an (int) cast on ULIDs made
     * `isSelected(01m0…)` a syntax error and killed bulk select.
     */
    public function test_list_renders_mobile_cards_and_desktop_table(): void
    {
        $client = User::factory()->create(['email' => 'layout-check@example.com']);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('layout-check@example.com')
            ->getContent();

        $this->assertStringContainsString('id="row-m-'.$client->id.'"', $html, 'mobile card missing');
        $this->assertStringContainsString('space-y-2 md:hidden', $html, 'cards must hide from md up');
        $this->assertStringContainsString('hidden overflow-x-auto', $html, 'table must hide below md');
        $this->assertStringContainsString("isSelected('".$client->id."')", $html, 'ids must reach Alpine quoted');
        $this->assertStringNotContainsString('isSelected('.$client->id.')', $html);
    }
}
