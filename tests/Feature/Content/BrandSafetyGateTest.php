<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\CleanBlockedTermsJob;
use App\Jobs\PublishContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\HumanizerService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The "blocked competitor terms are solved in ALL cases" package
 * (cocomii 2026-08-20):
 *
 *  - manual terms are never exempted by topic keywords (that silently
 *    disabled the client's own block list);
 *  - the lint sees meta fields, the slug, and image alt text;
 *  - the publish job re-lints and refuses to send a dirty article
 *    (first trip → scrub job, second trip → brand_safety failure);
 *  - a total delivery failure returns the topic to READY, never FAILED
 *    (FAILED = "regenerate me" to the dispatcher, which wiped client
 *    edits 13 versions deep).
 */
class BrandSafetyGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: Website, 1: ContentPlan, 2: ContentTopic} */
    private function guardedTopic(string $keyword = 'how to choose a phone case'): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'billing_covered_at' => now(),
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'harmful' => true,
                'auto' => [
                    ['brand' => 'otterbox', 'domain' => 'otterbox.com', 'reason' => 'competitor'],
                ],
                'manual' => ['squared'],
                'removed' => [],
            ],
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Test topic', 'target_keyword' => $keyword,
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->toDateString(),
        ]);

        return [$website, $plan, $topic];
    }

    private function currentArticle(ContentTopic $topic, string $html, array $extra = []): ContentArticle
    {
        return ContentArticle::create($extra + [
            'topic_id' => $topic->id,
            'version' => 1,
            'is_current' => true,
            'h1' => 'How to choose a phone case',
            'meta_title' => 'How to choose a phone case',
            'meta_description' => 'A practical guide.',
            'slug' => 'how-to-choose-a-phone-case',
            'html' => $html,
            'seo_score' => 95,
        ]);
    }

    // ── termsForTopic scoping ───────────────────────────────────────────

    public function test_manual_term_is_enforced_even_when_the_topic_keyword_contains_it(): void
    {
        [, $plan, $topic] = $this->guardedTopic('best squared iphone case');

        $terms = app(CompetitorMentionGuard::class)->termsForTopic($plan, $topic);

        $this->assertContains('squared', $terms,
            'a manually blocked word must survive topics that contain it — the client explicitly asked');
    }

    public function test_auto_brand_exemption_requires_a_word_boundary_match(): void
    {
        // "otterboxes" (no word-boundary match for "otterbox") must NOT exempt the brand.
        [, $plan, $topic] = $this->guardedTopic('otterboxes roundup');

        $this->assertContains('otterbox',
            app(CompetitorMentionGuard::class)->termsForTopic($plan, $topic));

        // A real word-boundary hit still exempts — "otterbox alternatives" articles are legit.
        [, $plan2, $topic2] = $this->guardedTopic('otterbox alternatives');
        $this->assertNotContains('otterbox',
            app(CompetitorMentionGuard::class)->termsForTopic($plan2, $topic2));
    }

    // ── lint scope ──────────────────────────────────────────────────────

    public function test_lint_catches_blocked_terms_in_extra_text_and_alt_attributes(): void
    {
        $lint = fn (string $html, string $extra = '') => array_column(
            app(HumanizerService::class)->lint($html, ['squared'], [], $extra), 'code');

        $this->assertNotContains('competitor_mentions', $lint('<p>A clean body.</p>'));
        $this->assertContains('competitor_mentions',
            $lint('<p>A clean body.</p>', 'Best Squared cases 2026'), 'meta text must be linted');
        $this->assertContains('competitor_mentions',
            $lint('<p>Case pic <img src="/x.jpg" alt="a squared case on a desk"></p>'),
            'alt attributes must be linted');
    }

    // ── publish-time gate ───────────────────────────────────────────────

    public function test_publish_refuses_a_dirty_article_and_queues_the_scrub(): void
    {
        Queue::fake();
        [$website, , $topic] = $this->guardedTopic();
        $this->currentArticle($topic, '<p>Squared cases are trendy.</p>');
        ContentIntegration::create([
            'website_id' => $website->id, 'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['secret' => 'x'], 'config' => ['url' => 'https://example.com/hook'],
        ]);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_PUBLISHING, $topic->status);
        Queue::assertPushed(CleanBlockedTermsJob::class, fn ($job) => $job->topicId === $topic->id);
        $this->assertDatabaseCount('content_publications', 0); // nothing was sent
    }

    public function test_publish_after_scrub_fails_the_topic_when_still_dirty(): void
    {
        Queue::fake();
        [$website, , $topic] = $this->guardedTopic();
        $this->currentArticle($topic, '<p>Still very Squared.</p>');
        ContentIntegration::create([
            'website_id' => $website->id, 'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['secret' => 'x'], 'config' => ['url' => 'https://example.com/hook'],
        ]);

        (new PublishContentArticleJob($topic->id, afterScrub: true))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_FAILED, $topic->status);
        $this->assertStringStartsWith('brand_safety:', (string) $topic->last_error);
        Queue::assertNotPushed(CleanBlockedTermsJob::class);
    }

    public function test_clean_article_passes_the_gate_untouched(): void
    {
        Queue::fake();
        [$website, , $topic] = $this->guardedTopic();
        $this->currentArticle($topic, '<p>A perfectly generic case guide.</p>');
        // No integrations connected → the job returns leaving the topic
        // SCHEDULED (publishes once one is connected) — but the brand gate
        // must NOT have tripped on the way.
        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_SCHEDULED, $topic->status);
        Queue::assertNotPushed(CleanBlockedTermsJob::class);
    }

    // ── delivery failure never regenerates ──────────────────────────────

    public function test_total_delivery_failure_returns_the_topic_to_ready_not_failed(): void
    {
        \Illuminate\Support\Facades\Http::fake(['example.com/*' => \Illuminate\Support\Facades\Http::response('nope', 401)]);
        [$website, , $topic] = $this->guardedTopic();
        $this->currentArticle($topic, '<p>A perfectly generic case guide.</p>');
        ContentIntegration::create([
            'website_id' => $website->id, 'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['secret' => 'x'], 'config' => ['url' => 'https://example.com/hook'],
        ]);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_READY, $topic->status,
            'FAILED would make the dispatcher regenerate a perfectly good article');
        $this->assertNotEmpty($topic->last_error);
    }

    // ── dispatcher holds brand_safety failures ──────────────────────────

    public function test_dispatcher_does_not_regenerate_brand_safety_failures(): void
    {
        Queue::fake();
        [, , $topic] = $this->guardedTopic();
        $topic->forceFill([
            'status' => ContentTopic::STATUS_FAILED,
            'last_error' => 'brand_safety: could not remove blocked terms',
            'scheduled_for' => now()->subDay()->toDateString(),
        ])->save();

        // Ordinary failures on the same plan still regenerate.
        $retryable = ContentTopic::create([
            'plan_id' => $topic->plan_id, 'website_id' => $topic->website_id,
            'title' => 'Retryable', 'target_keyword' => 'phone grip tips',
            'status' => ContentTopic::STATUS_FAILED,
            'last_error' => 'draft_failed: timeout',
            'scheduled_for' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(\App\Jobs\ProduceContentArticleJob::class,
            fn ($job) => $job->topicId === $retryable->id);
        Queue::assertNotPushed(\App\Jobs\ProduceContentArticleJob::class,
            fn ($job) => $job->topicId === $topic->id);
    }

    public function test_dispatcher_stops_regenerating_after_the_write_attempt_cap(): void
    {
        // 2026-08-29 runaway: a below-floor topic was regenerated every tick
        // (64 writes, 191 revises, all billed) until version overflowed at 255.
        Queue::fake();
        [, , $topic] = $this->guardedTopic();
        $topic->forceFill([
            'status' => ContentTopic::STATUS_FAILED,
            'last_error' => 'below_publish_floor: score 50',
            'scheduled_for' => now()->subDay()->toDateString(),
        ])->save();
        foreach (range(1, 4) as $v) {
            ContentArticle::create([
                'topic_id' => $topic->id, 'version' => $v, 'is_current' => $v === 4,
                'h1' => 'T', 'html' => '<p>x</p>', 'seo_score' => 50,
                'generation_meta' => ['stage' => 'write'],
            ]);
        }

        $this->artisan('ebq:content-autopilot');

        Queue::assertNotPushed(\App\Jobs\ProduceContentArticleJob::class,
            fn ($job) => $job->topicId === $topic->id);
    }

    public function test_dispatcher_still_regenerates_below_the_write_attempt_cap(): void
    {
        Queue::fake();
        [, , $topic] = $this->guardedTopic();
        $topic->forceFill([
            'status' => ContentTopic::STATUS_FAILED,
            'last_error' => 'below_publish_floor: score 50',
            'scheduled_for' => now()->subDay()->toDateString(),
        ])->save();
        ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'T', 'html' => '<p>x</p>', 'seo_score' => 50,
            'generation_meta' => ['stage' => 'write'],
        ]);

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(\App\Jobs\ProduceContentArticleJob::class,
            fn ($job) => $job->topicId === $topic->id);
    }

    // ── reviseCurrentArticle (context refresh) ──────────────────────────

    public function test_revise_current_article_rescores_and_returns_to_ready(): void
    {
        [, , $topic] = $this->guardedTopic();
        $topic->forceFill(['status' => ContentTopic::STATUS_READY])->save();
        $this->currentArticle($topic, '<p>A clean guide about choosing a case.</p>');

        $article = app(\App\Services\Content\ContentArticleProducer::class)->reviseCurrentArticle($topic);

        $this->assertNotNull($article);
        $this->assertTrue((bool) $article->is_current);
        $this->assertSame('context_rescore', $article->generation_meta['stage'] ?? null,
            'no LLM available in tests → the rescore version is the final one');
        $this->assertSame(ContentTopic::STATUS_READY, $topic->fresh()->status);
    }
}
