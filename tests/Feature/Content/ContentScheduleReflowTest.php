<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentTopicPlanner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a topic (wizard ✗ / calendar Skip) must not leave its publish
 * date as a silent hole — the next planned article takes the vacated slot
 * and the calendar's date set is preserved (brigid 2026-08-21: the client
 * ✗-ed four wizard suggestions and their first article quietly slid from
 * "today" to four days later).
 */
class ContentScheduleReflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: ContentPlan, 1: list<ContentTopic>} */
    private function planWithTopics(array $topics): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);

        $rows = [];
        foreach ($topics as $i => [$status, $daysFromNow]) {
            $rows[] = ContentTopic::create([
                'plan_id' => $plan->id, 'website_id' => $website->id,
                'title' => 'Topic '.$i, 'target_keyword' => 'keyword '.$i,
                'status' => $status,
                'scheduled_for' => now()->addDays($daysFromNow)->toDateString(),
            ]);
        }

        return [$plan, $rows];
    }

    public function test_next_planned_article_takes_the_vacated_date(): void
    {
        [$plan, $t] = $this->planWithTopics([
            [ContentTopic::STATUS_APPROVED, 0],
            [ContentTopic::STATUS_APPROVED, 1],
            [ContentTopic::STATUS_APPROVED, 2],
            [ContentTopic::STATUS_APPROVED, 3],
        ]);

        $t[0]->update(['status' => ContentTopic::STATUS_SKIPPED]);
        app(ContentTopicPlanner::class)->fillVacatedDate($plan, $t[0]->scheduled_for);

        $this->assertSame(now()->toDateString(), $t[1]->fresh()->scheduled_for->toDateString(), 'next article takes today');
        $this->assertSame(now()->addDay()->toDateString(), $t[2]->fresh()->scheduled_for->toDateString());
        $this->assertSame(now()->addDays(2)->toDateString(), $t[3]->fresh()->scheduled_for->toDateString(), 'cadence preserved, tail date freed');
    }

    public function test_in_flight_and_ready_topics_never_move(): void
    {
        [$plan, $t] = $this->planWithTopics([
            [ContentTopic::STATUS_APPROVED, 0],
            [ContentTopic::STATUS_READY, 1],     // already written for its date
            [ContentTopic::STATUS_APPROVED, 2],
        ]);

        $t[0]->update(['status' => ContentTopic::STATUS_SKIPPED]);
        app(ContentTopicPlanner::class)->fillVacatedDate($plan, $t[0]->scheduled_for);

        $this->assertSame(now()->addDay()->toDateString(), $t[1]->fresh()->scheduled_for->toDateString(), 'ready article keeps its date');
        $this->assertSame(now()->toDateString(), $t[2]->fresh()->scheduled_for->toDateString(), 'movable one fills the hole past it');
    }

    public function test_a_past_vacated_date_fills_from_today(): void
    {
        [$plan, $t] = $this->planWithTopics([
            [ContentTopic::STATUS_APPROVED, -3],
            [ContentTopic::STATUS_APPROVED, 2],
        ]);

        $t[0]->update(['status' => ContentTopic::STATUS_SKIPPED]);
        app(ContentTopicPlanner::class)->fillVacatedDate($plan, $t[0]->scheduled_for);

        $this->assertSame(now()->toDateString(), $t[1]->fresh()->scheduled_for->toDateString(), 'never re-dated into the past');
    }

    public function test_null_vacated_date_is_a_no_op(): void
    {
        [$plan, $t] = $this->planWithTopics([[ContentTopic::STATUS_APPROVED, 1]]);

        app(ContentTopicPlanner::class)->fillVacatedDate($plan, null);

        $this->assertSame(now()->addDay()->toDateString(), $t[0]->fresh()->scheduled_for->toDateString());
    }
}
