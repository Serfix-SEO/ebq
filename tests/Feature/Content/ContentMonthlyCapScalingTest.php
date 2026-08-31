<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentTopicPlanner;
use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The monthly article cap scales with the calendar month's day count — the
 * product promise is one article every day (owner 2026-08-31): 31 in January,
 * 28/29 in February. A flat 30 silently blocked day 31 of long months.
 */
class ContentMonthlyCapScalingTest extends TestCase
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
        // The array cache outlives RefreshDatabase within a process — a cached
        // Setting from this class must not leak into later test classes.
        \Illuminate\Support\Facades\Cache::flush();
        parent::tearDown();
    }

    public function test_cap_scales_with_the_months_day_count(): void
    {
        $this->assertSame(31, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2027-01-15')));
        $this->assertSame(28, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2027-02-15')));
        $this->assertSame(29, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2028-02-15')));
        $this->assertSame(30, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2027-04-15')));
    }

    public function test_admin_setting_scales_proportionally_and_small_caps_are_stable(): void
    {
        Setting::set('content.limits.monthly_articles_per_website', 60);
        $this->assertSame(56, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2027-02-15')));
        $this->assertSame(62, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse('2027-01-15')));

        Setting::set('content.limits.monthly_articles_per_website', 2);
        foreach (['2027-01-15', '2027-02-15', '2027-04-15'] as $d) {
            $this->assertSame(2, ContentAutopilotConfig::monthlyArticlesFor(Carbon::parse($d)));
        }
    }

    /** @return array{ContentPlan, ContentTopic, User, Website} */
    private function coveredTopic(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Next up', 'target_keyword' => 'k',
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        return [$plan, $topic, $user, $website];
    }

    private function burnArticles(ContentPlan $plan, Website $website, int $n): void
    {
        foreach (range(1, $n) as $i) {
            $t = ContentTopic::create([
                'plan_id' => $plan->id, 'website_id' => $website->id,
                'title' => 'T'.$i, 'target_keyword' => 'k'.$i,
                'status' => ContentTopic::STATUS_READY,
            ]);
            ContentArticle::create([
                'topic_id' => $t->id, 'version' => 1, 'is_current' => true,
                'h1' => 'A'.$i, 'html' => '<p>x</p>', 'seo_score' => 90,
            ]);
        }
    }

    public function test_the_31st_article_is_allowed_in_a_31_day_month(): void
    {
        Carbon::setTestNow('2027-01-20 12:00:00');
        // Comp the trial cap out of the way — we're testing the monthly cap.
        [$plan, $topic, $user, $website] = $this->coveredTopic();
        $user->forceFill(['content_comp_sites' => 1])->save();

        $this->burnArticles($plan, $website, 30);
        $this->assertNull(app(ContentEntitlements::class)->blockReason($topic->refresh()),
            'article #31 must be allowed in January — one per calendar day');

        $this->burnArticles($plan, $website, 1);
        $this->assertSame('monthly_limit', app(ContentEntitlements::class)->blockReason($topic->refresh()));
    }

    public function test_february_blocks_at_its_day_count(): void
    {
        Carbon::setTestNow('2027-02-20 12:00:00');
        [$plan, $topic, $user, $website] = $this->coveredTopic();
        $user->forceFill(['content_comp_sites' => 1])->save();

        $this->burnArticles($plan, $website, 28);
        $this->assertSame('monthly_limit', app(ContentEntitlements::class)->blockReason($topic->refresh()),
            'February allows exactly its 28 days');
    }

    public function test_available_dates_offers_a_full_31_day_month(): void
    {
        Carbon::setTestNow('2026-12-31 12:00:00');
        [$plan] = $this->coveredTopic();
        $plan->forceFill(['articles_per_week' => 7, 'publish_days' => [1, 2, 3, 4, 5, 6, 7]])->save();

        $dates = app(ContentTopicPlanner::class)->availableDates($plan->refresh(), 40);
        $january = array_filter($dates, fn (string $d) => str_starts_with($d, '2027-01'));

        $this->assertCount(31, $january, 'every January day must be offerable');
    }
}
