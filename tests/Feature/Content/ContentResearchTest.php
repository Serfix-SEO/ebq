<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentResearch;
use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\ContentTopic;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Support\ContentAutopilotConfig;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Research page: the client-facing keyword-ideas feed built from
 * content_plan_keywords, with add-to-calendar actions and tenant scoping.
 */
class ContentResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake(); // mount() kicks research jobs; never run them here
    }

    /** @return array{0: User, 1: Website, 2: ContentPlan} */
    private function planFixture(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'keywords_classified_at' => now()->subDay(),
            'articles_per_week' => 7,
        ]);

        return [$user, $website, $plan];
    }

    private function keywordRow(ContentPlan $plan, string $keyword, array $extra = []): ContentPlanKeyword
    {
        return ContentPlanKeyword::create(array_merge([
            'plan_id' => $plan->id,
            'keyword' => $keyword,
            'keyword_hash' => KeywordMetric::hashKeyword($keyword),
            'type' => ContentPlanKeyword::TYPE_GAP,
            'country' => 'global',
            'search_volume' => 1200,
            'competition' => 0.2,
            'search_intent' => 'informational',
        ], $extra));
    }

    public function test_research_page_requires_auth(): void
    {
        $this->get('/content/research')->assertRedirect();
    }

    public function test_page_renders_with_seeded_keywords(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('content.research'))
            ->assertOk()
            ->assertSee(__('Keyword ideas'))
            ->assertSee('best coffee grinder')
            ->assertSee('1,200');
    }

    public function test_add_to_calendar_creates_approved_topic_and_dedupes(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)->call('addToCalendar', 'best coffee grinder', 1200);

        $topic = $plan->topics()->where('target_keyword', 'best coffee grinder')->first();
        $this->assertNotNull($topic);
        $this->assertSame(ContentTopic::STATUS_APPROVED, $topic->status);
        $this->assertSame('research', $topic->source);
        $this->assertSame(1200, $topic->keyword_volume);
        $this->assertNotNull($topic->scheduled_for);
        $this->assertTrue($topic->scheduled_for->isFuture());

        // Second add is refused, not duplicated.
        Livewire::test(ContentResearch::class)->call('addToCalendar', 'best coffee grinder', 1200);
        $this->assertSame(1, $plan->topics()->where('target_keyword', 'best coffee grinder')->count());
    }

    public function test_add_to_calendar_enriches_secondary_keywords_from_library(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');
        $this->keywordRow($plan, 'manual coffee grinder review', ['search_volume' => 900]);
        $this->keywordRow($plan, 'coffee grinder burr vs blade', ['search_volume' => 700]);
        $this->keywordRow($plan, 'unrelated espresso machine', ['search_volume' => 5000]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)->call('addToCalendar', 'best coffee grinder', 1200);

        $topic = $plan->topics()->where('target_keyword', 'best coffee grinder')->first();
        $secondary = (array) $topic->secondary_keywords;
        $this->assertContains('manual coffee grinder review', $secondary);
        $this->assertContains('coffee grinder burr vs blade', $secondary);
        $this->assertNotContains('best coffee grinder', $secondary);
        $this->assertLessThanOrEqual(8, count($secondary));
    }

    public function test_planned_keyword_shows_in_calendar_state(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');
        ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'target_keyword' => 'best coffee grinder',
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)
            ->assertSee(__('In your calendar'));
    }

    public function test_full_calendar_blocks_adding(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');
        ContentTopic::factory()->count((int) ContentAutopilotConfig::monthlyArticlesPerWebsite())->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_APPROVED,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)->call('addToCalendar', 'best coffee grinder', 1200);

        $this->assertSame(0, $plan->topics()->where('target_keyword', 'best coffee grinder')->count());
    }

    public function test_tenant_scoping_hides_other_plans_keywords(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'my own keyword');

        [$otherUser, $otherWebsite, $otherPlan] = $this->planFixture();
        $this->keywordRow($otherPlan, 'someone elses keyword');

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)
            ->assertSee('my own keyword')
            ->assertDontSee('someone elses keyword');
    }

    public function test_difficulty_filter_narrows_feed(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'easy keyword', ['competition' => 0.1]);
        $this->keywordRow($plan, 'hard keyword', ['competition' => 0.9]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentResearch::class)
            ->set('difficulty', 'easy')
            ->assertSee('easy keyword')
            ->assertDontSee('hard keyword');
    }

    public function test_no_internal_vendor_names_leak(): void
    {
        [$user, $website, $plan] = $this->planFixture();
        $this->keywordRow($plan, 'best coffee grinder');

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('content.research'))
            ->assertOk()
            ->assertDontSee('DataForSEO')
            ->assertDontSee('LLM')
            ->assertDontSee('classify');
    }
}
