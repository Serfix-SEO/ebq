<?php

namespace Tests\Feature\Content;

use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DeepSeek off-peak steering (owner 2026-08-22): peak hours (01–04 + 06–10
 * UTC per their pricing page) bill 2×, so the dispatcher's bulk write-ahead
 * claims wait for off-peak; topics due within CATCH_UP_HOURS still generate
 * immediately so no publish slot or review window is ever risked.
 */
class ContentOffPeakDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_off_peak_window_matches_deepseeks_utc_definition(): void
    {
        $expect = [
            0 => true, 1 => false, 2 => false, 3 => false, 4 => true, 5 => true,
            6 => false, 7 => false, 8 => false, 9 => false, 10 => true,
            12 => true, 18 => true, 23 => true,
        ];
        foreach ($expect as $hour => $offPeak) {
            $this->assertSame($offPeak,
                ContentAutopilotConfig::isDeepSeekOffPeak(Carbon::parse(sprintf('2026-09-01 %02d:30:00', $hour), 'UTC')),
                "hour {$hour} UTC");
        }
        // Timezone trap: 08:00 Karachi (+05) = 03:00 UTC = PEAK, whatever zone the input carries.
        $this->assertFalse(ContentAutopilotConfig::isDeepSeekOffPeak(Carbon::parse('2026-09-01 08:00:00', 'Asia/Karachi')));
    }

    /** @return array{0: ContentTopic, 1: ContentTopic} far-ahead + due-soon */
    private function seededTopics(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $mk = function (int $days) use ($user) {
            $website = Website::factory()->for($user)->create();
            $plan = ContentPlan::factory()->create([
                'website_id' => $website->id, 'billing_covered_at' => now(),
                'status' => ContentPlan::STATUS_ACTIVE,
            ]);

            $topic = ContentTopic::create([
                'plan_id' => $plan->id, 'website_id' => $website->id,
                'title' => 'T'.$days, 'target_keyword' => 'kw '.$days,
                'status' => ContentTopic::STATUS_APPROVED,
                'scheduled_for' => now()->addDays($days)->toDateString(),
            ]);
            // Store the bare DATE string: sqlite compares strings, and the
            // date-cast's "Y-m-d 00:00:00" breaks the dispatcher's
            // `<= toDateString()` boundary that a MySQL DATE column handles.
            \Illuminate\Support\Facades\DB::table('content_topics')->where('id', $topic->id)
                ->update(['scheduled_for' => now()->addDays($days)->toDateString()]);

            return $topic->fresh();
        };

        return [$mk(2), $mk(0)];
    }

    public function test_peak_hours_claim_only_due_soon_topics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 07:00:00', 'UTC')); // peak
        Queue::fake();
        [$farAhead, $dueSoon] = $this->seededTopics();

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(ProduceContentArticleJob::class, fn ($j) => $j->topicId === $dueSoon->id);
        Queue::assertNotPushed(ProduceContentArticleJob::class, fn ($j) => $j->topicId === $farAhead->id);
    }

    public function test_off_peak_claims_the_full_write_ahead_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC')); // off-peak
        Queue::fake();
        [$farAhead, $dueSoon] = $this->seededTopics();

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(ProduceContentArticleJob::class, fn ($j) => $j->topicId === $dueSoon->id);
        Queue::assertPushed(ProduceContentArticleJob::class, fn ($j) => $j->topicId === $farAhead->id);
    }

    public function test_kill_switch_restores_peak_claiming(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 07:00:00', 'UTC')); // peak
        \App\Models\Setting::set('content.dispatch.offpeak_only', false);
        Queue::fake();
        [$farAhead] = $this->seededTopics();

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(ProduceContentArticleJob::class, fn ($j) => $j->topicId === $farAhead->id);
    }
}
