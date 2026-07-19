<?php

namespace Tests\Feature\Content;

use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
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

        (new ContentEntitlements())->startTrial($user, $site);
        $this->assertFalse(TrialStatus::isLockedOut($user->fresh()));
    }

    public function test_content_only_user_gets_small_crawl_cap(): void
    {
        \App\Models\Setting::set('content.limits.content_only_crawl_pages', 150);
        $user = $this->expiredUser();
        $site = Website::factory()->for($user)->create();
        (new ContentEntitlements())->startTrial($user, $site);

        $this->assertTrue($user->fresh()->isContentOnly());
        $this->assertSame(150, $site->fresh()->crawlPageCap());
    }

    public function test_trial_cleanup_exempts_content_users_and_system_user(): void
    {
        // Content user: expired dashboard trial but active content trial.
        $content = $this->expiredUser();
        $contentSite = Website::factory()->for($content)->create();
        (new ContentEntitlements())->startTrial($content, $contentSite);

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
