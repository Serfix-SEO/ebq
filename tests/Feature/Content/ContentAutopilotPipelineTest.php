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

    /**
     * Fake the LLM. Writes are CHUNKED (2026-07-22): an outline call
     * ("PLANNING STEP") then one call per section ("WRITING STEP — write
     * ONLY section N"), so the fake speaks that protocol; revisions
     * ("PROBLEMS TO FIX") stay single-call patch-shaped.
     */
    private function fakeLlm(string $draftHtml, ?string $revisedHtml = null): void
    {
        $sections = [
            ['heading' => 'Why blue widget cleaning matters', 'html' => '<h2>Why blue widget cleaning matters</h2><p>'.$draftHtml.'</p>'],
            ['heading' => 'Tools you need', 'html' => '<h2>Tools you need</h2><p>Short list. A soft brush works well because it lifts grime without scratching the coating on most widgets sold today.</p>'],
            ['heading' => 'Step by step process', 'html' => '<h2>Step by step process</h2><p>Start dry. Then work through each surface with slow passes, checking corners where dust builds up over weeks of normal use.</p>'],
            ['heading' => 'Mistakes to avoid', 'html' => '<h2>Mistakes to avoid</h2><p>Skip harsh solvents. They strip finish fast, and replacing a damaged widget costs far more than five careful minutes.</p>'],
        ];

        Http::fake([
            'api.mistral.ai/*' => function ($request) use ($draftHtml, $revisedHtml, $sections) {
                $body = json_encode($request->data());

                if (str_contains($body, 'PROBLEMS TO FIX')) {
                    $payload = [
                        'html' => $revisedHtml ?? $draftHtml,
                        'meta_title' => 'Blue Widget Cleaning Guide For Homes',
                        'meta_description' => 'Learn blue widget cleaning with this practical walkthrough covering tools, steps, and mistakes to avoid so your widgets stay spotless year round.',
                        'h1' => 'Blue Widget Cleaning Done Right',
                    ];
                } elseif (str_contains($body, 'PLANNING STEP')) {
                    $payload = [
                        'h1' => 'Blue Widget Cleaning Done Right',
                        'summary' => 'A practical walkthrough of blue widget cleaning covering tools, steps, and common mistakes to avoid for spotless widgets year round in any home.',
                        'outline' => array_map(static fn ($s) => [
                            'heading' => $s['heading'], 'focus' => 'cover it well', 'word_target' => 250,
                        ], $sections),
                    ];
                } elseif (preg_match('/write ONLY section (\d+)/', $body, $m)) {
                    $payload = $sections[((int) $m[1]) - 1] ?? $sections[0];
                } else {
                    // Legacy single-call draft shape (non-chunked callers).
                    $payload = [
                        'summary' => 'A practical walkthrough of blue widget cleaning covering tools, steps, and common mistakes to avoid for spotless widgets year round in any home.',
                        'h1' => 'Blue Widget Cleaning Done Right',
                        'sections' => array_map(static fn ($s) => [
                            'kind' => 'add', 'title' => $s['heading'], 'proposed_html' => $s['html'],
                        ], $sections),
                    ];
                }

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

    public function test_toc_is_built_with_anchor_links_when_enabled(): void
    {
        $plan = ContentPlan::factory()->create([
            'toggles' => ['toc' => true, 'key_takeaways' => false, 'faq' => false],
        ]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'target_keyword' => 'blue widget cleaning',
        ]);

        $revised = '<h2>Blue widget cleaning basics</h2><p>Real content explaining the basics with concrete detail and enough words to count.</p>'
            .'<h2>Tools you need for blue widget cleaning</h2><p>A soft brush works well because it lifts grime without scratching the coating.</p>'
            .'<h2>Step by step blue widget cleaning</h2><p>Start dry, then work each surface slowly, checking the corners where dust builds up.</p>';
        $this->fakeLlm('Blue widget cleaning intro with enough real words to matter here.', $revised);

        $article = app(ContentArticleProducer::class)->produce($topic->fresh());

        $this->assertNotNull($article);
        // TOC nav present.
        $this->assertStringContainsString('class="content-toc"', $article->html);
        // Every H2 carries an id.
        preg_match_all('/<h2\b[^>]*\bid="([^"]+)"/i', $article->html, $ids);
        $this->assertNotEmpty($ids[1]);
        // Every TOC link targets a real heading id.
        preg_match_all('/<a href="#([^"]+)"/i', $article->html, $links);
        $this->assertNotEmpty($links[1]);
        foreach ($links[1] as $anchor) {
            $this->assertContains($anchor, $ids[1], "TOC anchor #{$anchor} has no matching heading id");
        }
    }

    public function test_toc_absent_when_disabled(): void
    {
        $plan = ContentPlan::factory()->create([
            'toggles' => ['toc' => false, 'key_takeaways' => false, 'faq' => false],
        ]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $plan->website_id,
            'target_keyword' => 'blue widget cleaning',
        ]);

        $this->fakeLlm('Blue widget cleaning intro text with real words here to fill.');

        $article = app(ContentArticleProducer::class)->produce($topic->fresh());

        $this->assertStringNotContainsString('class="content-toc"', $article->html);
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
        // Owner needs content access for the dispatcher to claim (plan is
        // billing-covered via the factory).
        $plan->website->user->forceFill([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ])->save();
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

    /**
     * Meta descriptions must never truncate mid-clause (live serfix.io meta
     * ended "…a detailed checklist, and" — 2026-07-22). Sentence-aware first,
     * then word boundary with dangling conjunctions stripped.
     */
    public function test_meta_clamp_is_sentence_aware_and_strips_dangling_words(): void
    {
        $producer = app(\App\Services\Content\ContentArticleProducer::class);
        $m = (new \ReflectionClass($producer))->getMethod('clampLength');
        $m->setAccessible(true);

        // A full sentence fits comfortably → cut lands on its period.
        $twoSentences = 'A complete SEO audit guide tailored for 2026, covering every step from preparation to reporting in plain language. It includes key takeaways, a detailed checklist, and more beyond.';
        $clamped = $m->invoke($producer, $twoSentences, 155);
        $this->assertSame('A complete SEO audit guide tailored for 2026, covering every step from preparation to reporting in plain language.', $clamped);

        // No usable sentence end → word boundary, dangling conjunction dropped.
        $noSentence = str_repeat('word ', 28).'a detailed checklist and everything else that follows';
        $clamped2 = $m->invoke($producer, $noSentence, 155);
        $this->assertLessThanOrEqual(155, mb_strlen($clamped2));
        $this->assertDoesNotMatchRegularExpression('/\s(?:and|or|with|for|to|the|a|an|of|in)$/i', $clamped2);

        // Short strings pass through untouched.
        $this->assertSame('Short and sweet.', $m->invoke($producer, 'Short and sweet.', 155));
    }
}
