<?php

namespace Tests\Feature\Content;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContentCrossProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::query()->create(['slug' => 'trial', 'name' => 'Trial', 'trial_days' => 14, 'is_active' => true]);
        Cache::forget('trial-status:days');
    }

    private function expiredUser(): User
    {
        return User::factory()->create(['created_at' => now()->subDays(30)]);
    }

    public function test_content_access_user_is_not_locked_out(): void
    {
        $user = $this->expiredUser();
        $site = Website::factory()->for($user)->create();
        $this->assertTrue(TrialStatus::isLockedOut($user)); // no content yet

        (new ContentEntitlements)->startTrial($user, $site);
        $this->assertFalse(TrialStatus::isLockedOut($user->fresh()));
    }

    /**
     * A lapsed user can still OPEN their content pages (the lockout allowlist
     * covers `content.*`), so every button on those pages has to work too.
     * Livewire posts each action to `livewire.update` regardless of origin, and
     * judging that route name sent them to the dashboard billing page instead
     * — "Write now" on the content calendar landed on SEO plans (prod
     * 2026-08-06). The page they can read and the actions they can fire must
     * agree.
     */
    public function test_a_livewire_action_from_a_content_page_is_not_bounced_to_dashboard_billing(): void
    {
        $user = $this->expiredUser();
        Website::factory()->for($user)->create();
        $this->assertTrue(TrialStatus::isLockedOut($user), 'precondition: dashboard trial lapsed, no content access');

        // The page itself is reachable...
        $this->actingAs($user)->get(route('content.get-started'))->assertOk();

        // ...so an action fired from it must not redirect to billing.
        $response = $this->actingAs($user)
            ->from(route('content.get-started'))
            ->post('/livewire/update', []);

        $this->assertNotSame(
            route('billing.show'),
            $response->headers->get('Location'),
            'a content-page action must never be answered with the SEO billing page',
        );
    }

    /** The lockout itself still holds for the dashboard surfaces it guards. */
    public function test_a_livewire_action_from_a_dashboard_page_stays_locked(): void
    {
        $user = $this->expiredUser();
        Website::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post('/livewire/update', [])
            ->assertRedirect(route('billing.show'));
    }

    /** A forged/foreign referer must not unlock anything. */
    public function test_an_offsite_referer_stays_locked(): void
    {
        $user = $this->expiredUser();
        Website::factory()->for($user)->create();

        $this->actingAs($user)
            ->from('https://evil.example.com/content')
            ->post('/livewire/update', [])
            ->assertRedirect(route('billing.show'));
    }

    public function test_content_only_user_gets_small_crawl_cap(): void
    {
        Setting::set('content.limits.content_only_crawl_pages', 150);
        $user = $this->expiredUser();
        $site = Website::factory()->for($user)->create();
        (new ContentEntitlements)->startTrial($user, $site);

        $this->assertTrue($user->fresh()->isContentOnly());
        $this->assertSame(150, $site->fresh()->crawlPageCap());
    }

    public function test_trial_cleanup_exempts_content_users_and_system_user(): void
    {
        // Content user: expired dashboard trial but active content trial.
        $content = $this->expiredUser();
        $contentSite = Website::factory()->for($content)->create();
        (new ContentEntitlements)->startTrial($content, $contentSite);

        // System (content-leads) user with a provisional website.
        $system = User::factory()->create(['is_system' => true, 'created_at' => now()->subDays(30)]);
        Website::factory()->for($system)->create();

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        // Both exempt: websites intact, no deletion notice started for them.
        $this->assertDatabaseHas('websites', ['id' => $contentSite->id]);
        $this->assertNull($content->fresh()->trial_deletion_notices);
        $this->assertNull($system->fresh()->trial_deletion_notices);
    }
}
