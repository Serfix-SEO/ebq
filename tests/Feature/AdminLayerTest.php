<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_open_admin_clients(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.clients.index'))
            ->assertForbidden();
    }

    public function test_admin_can_open_admin_clients(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Clients');
    }

    public function test_clients_listing_hides_system_content_lead_accounts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['is_admin' => false, 'email' => 'real-client@example.com']);
        // A content-funnel throwaway owner (ContentOnboardingConverter::newLeadUser()).
        $lead = User::factory()->create(['email' => 'lead+ABC@leads.serfix.internal']);
        $lead->forceFill(['is_system' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('real-client@example.com')
            ->assertDontSee('leads.serfix.internal');
    }

    public function test_admin_can_impersonate_client(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        // Impersonation lands where the CLIENT would land after login (a
        // website-less user → the websites page), not on a hard-coded
        // 'dashboard' that 301s to Site Health when the SEO UI is off.
        $this->actingAs($admin)
            ->post(route('admin.clients.impersonate', $client))
            ->assertRedirect(route($client->firstAccessibleRoute(null)));

        $this->assertAuthenticatedAs($client);
        $this->assertDatabaseHas('client_activities', [
            'type' => 'admin.impersonation_started',
            'user_id' => $client->id,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_impersonated_activity_never_moves_the_clients_last_activity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        // The client's own action, then a LATER impersonated admin action.
        $own = \App\Models\ClientActivity::create([
            'user_id' => $client->id, 'type' => 'auth.login', 'is_impersonated' => false,
        ]);
        // created_at is not fillable — backdate directly.
        \Illuminate\Support\Facades\DB::table('client_activities')->where('id', $own->id)
            ->update(['created_at' => now()->subDays(3)]);
        \App\Models\ClientActivity::create([
            'user_id' => $client->id, 'type' => 'admin.impersonation_started',
            'is_impersonated' => true, 'actor_user_id' => $admin->id,
        ]);

        // Detail page stat uses the client's OWN latest activity.
        $profile = app(\App\Services\Admin\ClientProfileService::class)->profile($client);
        $this->assertSame(
            now()->subDays(3)->toDateTimeString(),
            (string) $profile['last_client_activity_at'],
        );

        // The full impersonate -> stop round trip leaves the stat untouched
        // (the end-marker used to log AFTER the session flag was cleared).
        $this->actingAs($admin)->post(route('admin.clients.impersonate', $client));
        $this->post(route('admin.impersonation.stop'));
        $this->assertSame(0, \App\Models\ClientActivity::query()
            ->where('user_id', $client->id)->where('is_impersonated', false)
            ->where('type', 'like', 'admin.%')->count(), 'impersonation markers must be flagged');
        $profile = app(\App\Services\Admin\ClientProfileService::class)->profile($client);
        $this->assertSame(now()->subDays(3)->toDateTimeString(), (string) $profile['last_client_activity_at']);

        // Listing column excludes impersonated rows too (existing behavior).
        $response = $this->actingAs($admin)->get(route('admin.clients.index'));
        $row = collect($response->viewData('clients')->items() ?? $response->viewData('clients'))
            ->first(fn ($c) => $c->id === $client->id);
        $this->assertSame(now()->subDays(3)->toDateTimeString(), (string) $row->last_activity_at);
    }

    public function test_admin_can_force_apply_plan_without_payment(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['current_plan_slug' => null]);

        Plan::create(['slug' => 'agency', 'name' => 'Agency', 'display_order' => 3, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'agency',
            ])
            ->assertRedirect(route('admin.clients.index'));

        $this->assertSame('agency', $client->fresh()->current_plan_slug);
        $this->assertDatabaseHas('client_activities', [
            'type' => 'admin.client_plan_forced',
            'user_id' => $client->id,
        ]);
    }

    public function test_force_apply_free_plan_clears_the_comp(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['current_plan_slug' => 'agency']);

        // The free tier was renamed to 'trial' (User::TIER_TRIAL) — only that
        // slug maps to a null current_plan_slug (matches a never-paid user).
        Plan::create(['slug' => 'trial', 'name' => 'Trial', 'display_order' => 0, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'trial',
            ])
            ->assertRedirect(route('admin.clients.index'));

        $this->assertNull($client->fresh()->current_plan_slug);
    }

    public function test_force_apply_rejects_unknown_plan_slug(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['current_plan_slug' => null]);

        Plan::create(['slug' => 'agency', 'name' => 'Agency', 'display_order' => 3, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'does-not-exist',
            ])
            ->assertSessionHasErrors('plan_slug');

        $this->assertNull($client->fresh()->current_plan_slug);
    }
}
