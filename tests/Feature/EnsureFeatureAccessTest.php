<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression test for a fail-open bug (found 2026-07-06): EnsureFeatureAccess
 * used to let any request through unconditionally when the `feature:` route
 * arg wasn't a known TeamPermissions::FEATURES key — a typo in a route
 * definition silently bypassed gating entirely. Fixed by removing that
 * bypass; TeamPermissions::allows() already denies safely on an unknown key
 * for restricted members.
 */
class EnsureFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private function registerProbeRoute(): void
    {
        Route::middleware(['web', 'auth', 'feature:this_key_does_not_exist_in_team_permissions'])
            ->get('/__test/feature-probe', fn () => 'ok')
            ->name('test.feature-probe');
    }

    public function test_restricted_member_is_denied_on_an_unknown_feature_key(): void
    {
        $this->registerProbeRoute();

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $website->members()->attach($member->id, [
            'role' => 'member',
            'permissions' => json_encode(['dashboard']),
        ]);

        $response = $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get('/__test/feature-probe');

        // Denied the probe route — redirected to a route they CAN access
        // (dashboard, their one permission) rather than let through. Before
        // the fix this was 200 "ok": the bogus feature key bypassed gating
        // entirely.
        $response->assertRedirect(route('dashboard'));
    }

    public function test_owner_is_unaffected_by_an_unknown_feature_key(): void
    {
        $this->registerProbeRoute();

        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->withSession(['current_website_id' => $website->id])
            ->get('/__test/feature-probe');

        $response->assertOk()->assertSee('ok');
    }

    public function test_full_access_member_is_unaffected_by_an_unknown_feature_key(): void
    {
        $this->registerProbeRoute();

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $website->members()->attach($member->id, [
            'role' => 'member',
            'permissions' => null,
        ]);

        $response = $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get('/__test/feature-probe');

        $response->assertOk()->assertSee('ok');
    }
}
