<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\PriorityActionQueue;
use App\Models\RankTrackingKeyword;
use App\Models\User;
use App\Models\Website;
use App\Services\ActionQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActionQueueTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        return [$user, $website];
    }

    private function trackKeyword(Website $website, User $user, string $keyword, ?int $change): void
    {
        RankTrackingKeyword::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'keyword' => $keyword,
            'keyword_hash' => hash('sha256', $keyword),
            'target_domain' => 'example.com',
            'is_active' => true,
            'current_position' => 12,
            'best_position' => 4,
            'position_change' => $change,
        ]);
    }

    public function test_rank_drops_are_grouped_and_threshold_filtered(): void
    {
        [$user, $website] = $this->makeWebsite();

        $this->trackKeyword($website, $user, 'dropped big', -8);   // counts (>= 5 drop)
        $this->trackKeyword($website, $user, 'dropped small', -2); // below threshold, excluded
        $this->trackKeyword($website, $user, 'improved', 5);       // improvement, excluded

        $items = app(ActionQueueService::class)->groupedActions($website->id);

        // Only rank_drops should appear — no GSC/audit data exists, so every
        // other group is zero-count and filtered out.
        $this->assertCount(1, $items);
        $this->assertSame('rank_drops', $items[0]['key']);
        $this->assertSame(1, $items[0]['count']);
        $this->assertSame(ActionQueueService::SEV_HIGH, $items[0]['severity']);
    }

    public function test_group_count_is_not_capped_at_fifty(): void
    {
        [$user, $website] = $this->makeWebsite();

        for ($i = 0; $i < 60; $i++) {
            $this->trackKeyword($website, $user, "dropped {$i}", -6);
        }

        $service = app(ActionQueueService::class);
        $items = $service->groupedActions($website->id);

        $this->assertSame('rank_drops', $items[0]['key']);
        // The old 50-row default would have reported "50"; the real count is 60.
        $this->assertSame(60, $items[0]['count']);
        // And the slide-over returns every issue, not a capped subset.
        $this->assertCount(60, $service->issueRows('rank_drops', $website->id));
    }

    public function test_issue_rows_carry_fix_url_and_metric(): void
    {
        [$user, $website] = $this->makeWebsite();
        $this->trackKeyword($website, $user, 'dropped big', -8);

        $rows = app(ActionQueueService::class)->issueRows('rank_drops', $website->id);

        $this->assertCount(1, $rows);
        $this->assertSame('dropped big', $rows[0]['title']);
        $this->assertSame('↓ 8 positions', $rows[0]['metric']);
        $this->assertStringContainsString('/rank-tracking/', $rows[0]['fix_url']);
        $this->assertSame('rank_tracking', $rows[0]['fix_feature']);
    }

    public function test_unknown_issue_key_returns_no_rows(): void
    {
        [, $website] = $this->makeWebsite();

        $this->assertSame([], app(ActionQueueService::class)->issueRows('not_a_real_key', $website->id));
    }

    /**
     * The slide-over this test originally exercised (open()/close() +
     * openIssue/openRows state) was replaced by the dedicated SiteIssues
     * detail page — group rows now deep-link to route('issues.show').
     */
    public function test_component_renders_groups_linking_to_issue_detail_page(): void
    {
        [$user, $website] = $this->makeWebsite();
        $this->trackKeyword($website, $user, 'dropped big', -8);

        session(['current_website_id' => $website->id]);

        // The component is #[Lazy]; without this only the placeholder renders.
        Livewire::withoutLazyLoading();

        Livewire::actingAs($user)
            ->test(PriorityActionQueue::class)
            ->set('websiteId', $website->id)
            ->assertSee('Priority Action Queue')
            ->assertSee('Ranking drops')
            ->assertSeeHtml(route('issues.show', ['key' => 'rank_drops']));
    }
}
