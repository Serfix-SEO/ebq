<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\RewriteArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentRewriteCreditEvent as Event;
use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\RewriteCredits;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RewriteArticleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.mistral.key' => 'test-key', 'services.deepseek.key' => 'test-key']);
    }

    /** @return array{0: User, 1: ContentTopic, 2: ContentRewriteRequest} */
    private function fixture(string $topicStatus = ContentTopic::STATUS_READY): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'billing_covered_at' => now(),
            'business_description' => 'A shop selling fruit-themed phone cases.',
            'offerings' => ['sell' => ['phone cases'], 'dont_sell' => []],
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => $topicStatus,
        ]);
        ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'stable-slug', 'html' => '<p>Original body of the article.</p>',
            'seo_score' => 99,
        ]);
        $request = ContentRewriteRequest::create([
            'topic_id' => $topic->id, 'user_id' => $user->id, 'website_id' => $website->id,
            'prompt' => 'Make it friendlier', 'status' => ContentRewriteRequest::STATUS_QUEUED,
            'prior_status' => $topicStatus,
        ]);
        // Spend-at-FINALIZE (owner rule): nothing is spent at dispatch — the
        // queued request itself reserves the credit granted here.
        app(RewriteCredits::class)->grantAdmin($user, 1);

        return [$user, $topic, $request];
    }

    private function runJob(ContentRewriteRequest $request, ContentTopic $topic): void
    {
        (new RewriteArticleJob($request->id, $topic->id))
            ->handle(app(ContentArticleProducer::class), app(RewriteCredits::class));
    }

    public function test_successful_rewrite_marks_done_and_keeps_the_credit(): void
    {
        [$user, $topic, $request] = $this->fixture();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>friendlier body</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h'])]]]])]);

        $this->runJob($request, $topic);

        $request->refresh();
        $this->assertSame(ContentRewriteRequest::STATUS_DONE, $request->status);
        $this->assertNotNull($request->article_version);
        $this->assertSame(ContentTopic::STATUS_READY, $topic->fresh()->status);
        // Credit charged exactly at finalization — never before.
        $this->assertSame(1, Event::query()->where('kind', Event::KIND_SPEND)->count());
        $this->assertNotNull($request->credit_event_id);
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_REFUND)->count());
        $this->assertStringContainsString('friendlier', (string) $topic->articles()->where('is_current', true)->value('html'));
    }

    public function test_published_topic_stays_published_and_slug_unchanged(): void
    {
        [, $topic, $request] = $this->fixture(ContentTopic::STATUS_PUBLISHED);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>new</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h', 'slug' => 'hacked-slug'])]]]])]);

        $this->runJob($request, $topic);

        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->fresh()->status);
        $this->assertSame('stable-slug', (string) $topic->articles()->where('is_current', true)->value('slug'));
    }

    public function test_llm_failure_refunds_and_restores_prior_status(): void
    {
        [, $topic, $request] = $this->fixture();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json at all']]]])]);

        $this->runJob($request, $topic);

        $request->refresh();
        $this->assertSame(ContentRewriteRequest::STATUS_FAILED, $request->status);
        // Nothing was spent, so nothing to refund — the balance never moved.
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_SPEND)->count());
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_REFUND)->count());
        $this->assertSame(ContentTopic::STATUS_READY, $topic->fresh()->status);
    }

    public function test_failed_hook_refunds_exactly_once(): void
    {
        [, $topic, $request] = $this->fixture();

        $job = new RewriteArticleJob($request->id, $topic->id);
        $job->failed(new \RuntimeException('boom'));
        $job->failed(new \RuntimeException('boom again'));

        $this->assertSame(ContentRewriteRequest::STATUS_FAILED, $request->fresh()->status);
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_SPEND)->count());
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_REFUND)->count());
    }

    public function test_legacy_dispatch_time_spend_is_refunded_on_failure(): void
    {
        [$user, $topic, $request] = $this->fixture();
        // Pre-migration row: credit was spent at dispatch.
        $spend = app(RewriteCredits::class)->spend($user, $topic, $request->id);
        $request->update(['credit_event_id' => $spend->id]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json at all']]]])]);

        $this->runJob($request, $topic);

        $this->assertSame(1, Event::query()->where('kind', Event::KIND_REFUND)->count());
    }
}
