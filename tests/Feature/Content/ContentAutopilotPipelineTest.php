<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Services\Content\ContentArticleProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentAutopilotPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        config(['services.mistral.key' => 'test-key']);
        // No Serper key → briefs fail-soft (no_serp_data) → writer works
        // from topic + business profile alone. That's the degradation path.
    }

    /** Fake the LLM: draft-shaped JSON for writes, patch-shaped for revisions. */
    private function fakeLlm(string $draftHtml, ?string $revisedHtml = null): void
    {
        Http::fake([
            'api.mistral.ai/*' => function ($request) use ($draftHtml, $revisedHtml) {
                $body = json_encode($request->data());
                $isRevision = str_contains($body, 'PROBLEMS TO FIX');

                $payload = $isRevision
                    ? [
                        'html' => $revisedHtml ?? $draftHtml,
                        'meta_title' => 'Blue Widget Cleaning Guide For Homes',
                        'meta_description' => 'Learn blue widget cleaning with this practical walkthrough covering tools, steps, and mistakes to avoid so your widgets stay spotless year round.',
                        'h1' => 'Blue Widget Cleaning Done Right',
                    ]
                    : [
                        'summary' => 'A practical walkthrough of blue widget cleaning covering tools, steps, and common mistakes to avoid for spotless widgets year round in any home.',
                        'h1' => 'Blue Widget Cleaning Done Right',
                        'sections' => [
                            ['kind' => 'add', 'title' => 'Why blue widget cleaning matters', 'proposed_html' => '<p>'.$draftHtml.'</p>'],
                            ['kind' => 'add', 'title' => 'Tools you need', 'proposed_html' => '<p>Short list. A soft brush works well because it lifts grime without scratching the coating on most widgets sold today.</p>'],
                            ['kind' => 'add', 'title' => 'Step by step process', 'proposed_html' => '<p>Start dry. Then work through each surface with slow passes, checking corners where dust builds up over weeks of normal use.</p>'],
                            ['kind' => 'add', 'title' => 'Mistakes to avoid', 'proposed_html' => '<p>Skip harsh solvents. They strip finish fast, and replacing a damaged widget costs far more than five careful minutes.</p>'],
                        ],
                    ];

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($payload)]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 500, 'total_tokens' => 600],
                ]);
            },
        ]);
    }

    public function test_producer_writes_scores_and_marks_ready_or_failed(): void
    {
        $plan = ContentPlan::factory()->create();
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'target_keyword' => 'blue widget cleaning',
            'title' => 'Blue Widget Cleaning Done Right',
        ]);

        $this->fakeLlm('Blue widget cleaning starts with the right approach. This guide walks through every step with concrete detail so you can finish fast.');

        $article = app(ContentArticleProducer::class)->produce($topic->fresh());

        $this->assertNotNull($article);
        $this->assertNotNull($article->seo_score);
        $this->assertNotEmpty($article->html);
        $this->assertContains($topic->fresh()->status, [
            ContentTopic::STATUS_READY, ContentTopic::STATUS_FAILED,
        ]);
        // Versions recorded, current flag unique.
        $this->assertSame(1, $topic->articles()->where('is_current', true)->count());
    }

    public function test_revision_loop_produces_new_versions_when_score_low(): void
    {
        $plan = ContentPlan::factory()->create();
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'target_keyword' => 'blue widget cleaning',
        ]);

        // Draft body deliberately thin → low score → revision fires.
        $this->fakeLlm('Generic text without the phrase.', '<h2>Blue widget cleaning basics</h2><p>Blue widget cleaning explained properly with details.</p>');

        app(ContentArticleProducer::class)->produce($topic->fresh());

        $this->assertGreaterThanOrEqual(2, $topic->articles()->count());
    }

    public function test_dispatcher_reaps_stuck_topics(): void
    {
        $plan = ContentPlan::factory()->create();
        $stuck = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'status' => ContentTopic::STATUS_WRITING,
            'stage_started_at' => now()->subHours(2),
        ]);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        $this->assertSame(ContentTopic::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertStringContainsString('reaped', $stuck->fresh()->last_error);
    }

    public function test_dispatcher_claims_due_topics_one_per_website(): void
    {
        Queue::fake();

        $plan = ContentPlan::factory()->create();
        ContentTopic::factory()->for($plan, 'plan')->count(3)->create([
            'website_id' => $plan->website_id,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay(),
        ]);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        // 3 due topics, same website → exactly ONE production dispatched.
        Queue::assertPushed(ProduceContentArticleJob::class, 1);
    }

    public function test_dispatcher_skips_far_future_topics(): void
    {
        Queue::fake();

        $plan = ContentPlan::factory()->create();
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDays(10),
        ]);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        Queue::assertNotPushed(ProduceContentArticleJob::class);
    }

    public function test_dispatcher_tops_up_thin_calendars(): void
    {
        Queue::fake();

        ContentPlan::factory()->create(); // zero future topics → thin

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        Queue::assertPushed(PlanContentTopicsJob::class, 1);
    }

    public function test_spend_cap_stops_claiming(): void
    {
        Queue::fake();
        config(['services.content_autopilot.llm_monthly_cap_usd' => 10]);

        $meter = app(\App\Services\Content\ContentLlmSpendMeter::class);
        $meter->add(11.0);

        $plan = ContentPlan::factory()->create();
        ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay(),
        ]);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        Queue::assertNotPushed(ProduceContentArticleJob::class);
    }
}
