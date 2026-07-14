<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Throwaway verification test for the site-explorer quota dedup logic added
 * 2026-07-14 (WebsiteAnalyzeController::store()) — not meant to stay in the
 * suite long-term in this exact shape, just proving the three scenarios the
 * user described actually behave as specified. Http::fake() so no real
 * DataForSEO calls fire even though a non-admin user is used.
 */
class SiteExplorerLimitDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        config(['services.recaptcha.enabled' => false]);
    }

    private function userWithLimit(int $limit): User
    {
        $plan = Plan::create([
            'slug' => 'test-'.uniqid(),
            'name' => 'Test',
            'price_monthly_usd' => 0,
            'max_websites' => 5,
            'max_seats' => 1,
            'site_explorer_limit' => $limit,
            'site_explorer_window_hours' => 24,
        ]);

        return User::factory()->create(['current_plan_slug' => $plan->slug]);
    }

    public function test_own_attached_website_never_counts_toward_the_limit(): void
    {
        $user = $this->userWithLimit(1);
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'valustrat.com']);

        // Analyze the OWN site 3 times — a limit of 1 would otherwise block by the 2nd call.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
                ->assertStatus(202);
        }
    }

    public function test_repeat_lookup_of_the_same_domain_counts_once(): void
    {
        $user = $this->userWithLimit(1);

        // Same domain analyzed twice — limit of 1 must NOT block the 2nd call.
        $this->actingAs($user)
            ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
            ->assertStatus(202);

        $this->actingAs($user)
            ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
            ->assertStatus(202);
    }

    public function test_a_genuinely_new_domain_consumes_the_quota_and_blocks_at_the_limit(): void
    {
        $user = $this->userWithLimit(1);

        $this->actingAs($user)
            ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
            ->assertStatus(202);

        // A DIFFERENT, never-seen domain is the 2nd distinct lookup — over the limit of 1.
        $this->actingAs($user)
            ->postJson(route('analyze.store'), ['url' => 'another-domain.com'])
            ->assertStatus(429);
    }

    public function test_a_different_user_analyzing_the_same_domain_is_unaffected(): void
    {
        $userA = $this->userWithLimit(1);
        $userB = $this->userWithLimit(1);

        $this->actingAs($userA)
            ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
            ->assertStatus(202);

        // userB's first-ever lookup of the SAME domain — must count for THEM (first time), not be blocked.
        $this->actingAs($userB)
            ->postJson(route('analyze.store'), ['url' => 'valustrat.com'])
            ->assertStatus(202);

        // userB's SECOND distinct domain now exceeds their own limit of 1.
        $this->actingAs($userB)
            ->postJson(route('analyze.store'), ['url' => 'another-domain.com'])
            ->assertStatus(429);
    }
}
