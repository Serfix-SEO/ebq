<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Studio is disabled globally while in beta (config features.ai_studio,
 * env FEATURE_AI_STUDIO). When off: its routes 404 regardless of team
 * permission, and it is never a post-login landing route.
 */
class AiStudioKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_studio_route_404s_when_feature_disabled(): void
    {
        config(['features.ai_studio' => false]);
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('ai-studio.index'))
            ->assertNotFound();
    }

    public function test_ai_studio_route_reachable_when_feature_enabled(): void
    {
        config(['features.ai_studio' => true]);
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        // Owner has full feature access, so the per-team `feature:` gate passes;
        // with the kill-switch on, the page renders (200) instead of 404.
        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('ai-studio.index'))
            ->assertOk();
    }

    public function test_disabled_ai_studio_is_never_a_landing_route(): void
    {
        config(['features.ai_studio' => false]);
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();

        // firstAccessibleRoute must skip ai_studio when disabled — otherwise a
        // redirect target would 404 and loop.
        $this->assertNotSame('ai-studio.index', $user->firstAccessibleRoute((string) $website->id));
    }
}
