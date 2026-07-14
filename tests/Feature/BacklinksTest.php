<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_backlinks(): void
    {
        $this->get(route('backlinks.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_accessible_website_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('backlinks.index'))->assertRedirect(route('onboarding'));
    }

    public function test_user_with_only_shared_website_can_view_backlinks_page(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'shared-backlinks.test']);
        $website->members()->attach($member->id);

        $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk();
    }

    public function test_onboarded_user_can_view_backlinks_page(): void
    {
        // /backlinks is the read-only Site Explorer backlink view since
        // 2026-07-14 — the legacy manual "Add backlink" CRUD was unrouted.
        // No snapshot exists for this factory domain and DataForSEO isn't
        // configured in tests, so the "unavailable" state renders (never
        // the old CRUD form, never a provider call).
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk()
            ->assertSee('Backlinks')
            ->assertDontSee('Add backlink')
            ->assertDontSee('Bulk edit by date');
    }
}
