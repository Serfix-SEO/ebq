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
            ->assertSee('Admin Clients');
    }

    public function test_admin_can_impersonate_client(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.clients.impersonate', $client))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($client);
        $this->assertDatabaseHas('client_activities', [
            'type' => 'admin.impersonation_started',
            'user_id' => $client->id,
            'actor_user_id' => $admin->id,
        ]);
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

        Plan::create(['slug' => 'free', 'name' => 'Free', 'display_order' => 0, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'email' => $client->email,
                'plan_slug' => 'free',
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
