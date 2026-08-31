<?php

namespace Tests\Feature\Content;

use App\Jobs\GenerateContentImagesJob;
use App\Jobs\PublishContentArticleJob;
use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\CompetitorMentionGuard;
use App\Services\Content\ContentArticleProducer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Self-healing for articles that reach publish without images, and the
 * deterministic brand-scrub fallback (prod 2026-08-31, simcardairportbali.com:
 * the brand gate failed produce() with scores 91/92, so the READY-gated image
 * dispatch never ran; a human rescued the topics via repeated manual scrubs
 * and both articles published with ZERO images — no featured thumbnail).
 */
class PublishImagesSelfHealTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Website $website;

    private ContentPlan $plan;

    private ContentTopic $topic;

    private ContentArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config([
            'services.ideogram.key' => 'fake-key',
            'services.ideogram.base_url' => 'https://api.ideogram.ai/v1',
        ]);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });

        $this->user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $this->website = Website::factory()->for($this->user)->create();
        $this->plan = ContentPlan::factory()->create([
            'website_id' => $this->website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
            'images_enabled' => true,
        ]);
        $this->topic = ContentTopic::create([
            'plan_id' => $this->plan->id, 'website_id' => $this->website->id,
            'title' => 'T', 'target_keyword' => 'test keyword',
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->toDateString(),
        ]);
        $this->article = ContentArticle::create([
            'topic_id' => $this->topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'A Test Article', 'meta_title' => 'A Test Article',
            'meta_description' => 'Description.', 'slug' => 'a-test-article',
            'html' => '<p>Body without any figure.</p>', 'seo_score' => 95,
        ]);
    }

    private function connectedWebhook(): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $this->website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/receive', 'secret' => str_repeat('s', 40)],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
    }

    private function runPublish(bool $forceUpdate = false): void
    {
        (new PublishContentArticleJob($this->topic->id, forceUpdate: $forceUpdate))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );
    }

    // ── publish-time image backstop ─────────────────────────────────────

    public function test_publish_without_images_dispatches_generation_backstop(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/a'], 200)]);
        $this->connectedWebhook();

        $this->runPublish();

        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $this->topic->refresh()->status);
        Queue::assertPushed(GenerateContentImagesJob::class, fn ($job) => $job->articleId === $this->article->id);
        $this->assertTrue(Cache::has('content:images:pending:'.$this->article->id),
            'the calendar "Finalizing images…" flag must be set at dispatch');
    }

    public function test_backstop_stays_quiet_when_images_already_exist(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/a'], 200)]);
        $this->connectedWebhook();
        ContentImage::query()->create([
            'article_id' => $this->article->id, 'role' => ContentImage::ROLE_FEATURED,
            'prompt' => 'x', 'disk_path' => 'content/images/x.png', 'status' => ContentImage::STATUS_GENERATED,
        ]);

        $this->runPublish();

        Queue::assertNotPushed(GenerateContentImagesJob::class);
    }

    public function test_backstop_respects_the_clients_images_opt_out(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/a'], 200)]);
        $this->connectedWebhook();
        $this->plan->forceFill(['images_enabled' => false])->save();

        $this->runPublish();

        Queue::assertNotPushed(GenerateContentImagesJob::class);
    }

    // ── late images reach the live post ─────────────────────────────────

    public function test_late_images_on_a_published_topic_trigger_a_forced_update(): void
    {
        Queue::fake();
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/img.png', 'seed' => 1, 'resolution' => '1344x768']]], 200),
            'cdn.ideogram.ai/*' => Http::response('PNGBYTES', 200),
        ]);
        $this->topic->forceFill(['status' => ContentTopic::STATUS_PUBLISHED, 'published_at' => now()->subDay()])->save();

        (new GenerateContentImagesJob($this->article->id))->handle(
            app(\App\Services\Content\IdeogramClient::class),
            app(\App\Services\Content\IdeogramSpendMeter::class),
        );

        $this->assertGreaterThan(0, ContentImage::query()->where('article_id', $this->article->id)->count());
        Queue::assertPushed(PublishContentArticleJob::class,
            fn ($job) => $job->topicId === $this->topic->id && $job->forceUpdate === true);
    }

    public function test_images_on_an_unpublished_topic_do_not_republish(): void
    {
        Queue::fake();
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/img.png', 'seed' => 1, 'resolution' => '1344x768']]], 200),
            'cdn.ideogram.ai/*' => Http::response('PNGBYTES', 200),
        ]);
        $this->topic->forceFill(['status' => ContentTopic::STATUS_READY])->save();

        (new GenerateContentImagesJob($this->article->id))->handle(
            app(\App\Services\Content\IdeogramClient::class),
            app(\App\Services\Content\IdeogramSpendMeter::class),
        );

        Queue::assertNotPushed(PublishContentArticleJob::class);
    }

    // ── forceUpdate delivery semantics ──────────────────────────────────

    public function test_force_update_sends_an_update_over_confirmed_rows_and_keeps_the_publish_date(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/a-test-article'], 200)]);
        $integration = $this->connectedWebhook();
        $originalPublishedAt = now()->subDays(3)->startOfSecond();
        $this->topic->forceFill(['status' => ContentTopic::STATUS_PUBLISHED, 'published_at' => $originalPublishedAt])->save();
        ContentPublication::query()->create([
            'article_id' => $this->article->id,
            'integration_id' => $integration->id,
            'status' => ContentPublication::STATUS_CONFIRMED,
            'external_id' => 'a-test-article',
            'external_url' => 'https://client.test/blog/a-test-article',
            'published_at' => $originalPublishedAt,
        ]);

        $this->runPublish(forceUpdate: true);

        $this->topic->refresh();
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $this->topic->status);
        $this->assertTrue($this->topic->published_at->equalTo($originalPublishedAt),
            'a forced update must never move the original publish date');
        $this->assertSame(ContentPublication::STATUS_CONFIRMED, ContentPublication::query()->firstOrFail()->status);
        Http::assertSent(fn (Request $r) => str_contains((string) $r->body(), '"event":"article.updated"'));
    }

    public function test_without_force_update_a_published_topic_is_left_alone(): void
    {
        Queue::fake();
        Http::fake();
        $integration = $this->connectedWebhook();
        $this->topic->forceFill(['status' => ContentTopic::STATUS_PUBLISHED])->save();
        ContentPublication::query()->create([
            'article_id' => $this->article->id, 'integration_id' => $integration->id,
            'status' => ContentPublication::STATUS_CONFIRMED, 'external_id' => 'x',
        ]);

        $this->runPublish();

        Http::assertNothingSent();
    }

    // ── review-page Republish button ────────────────────────────────────

    public function test_republish_now_dispatches_a_forced_update_for_published_topics(): void
    {
        Queue::fake();
        $this->connectedWebhook();
        $this->topic->forceFill(['status' => ContentTopic::STATUS_PUBLISHED])->save();
        session(['current_website_id' => $this->website->id]);

        Livewire::actingAs($this->user)
            ->test(ArticleReview::class, ['topicId' => $this->topic->id])
            ->call('republishNow');

        Queue::assertPushed(PublishContentArticleJob::class,
            fn ($job) => $job->topicId === $this->topic->id && $job->forceUpdate === true);
    }

    public function test_republish_now_ignores_unpublished_topics(): void
    {
        Queue::fake();
        $this->connectedWebhook();
        session(['current_website_id' => $this->website->id]);

        Livewire::actingAs($this->user)
            ->test(ArticleReview::class, ['topicId' => $this->topic->id])
            ->call('republishNow');

        Queue::assertNotPushed(PublishContentArticleJob::class);
    }

    // ── deterministic brand-scrub fallback ──────────────────────────────

    private function guardPlan(): void
    {
        $this->plan->forceFill([
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(),
                'harmful' => true,
                'auto' => [
                    ['brand' => 'holafly', 'domain' => 'holafly.com', 'reason' => 'competitor'],
                    ['brand' => 'saily', 'domain' => 'saily.com', 'reason' => 'competitor'],
                ],
                'manual' => [],
                'removed' => [],
            ],
            'toggles' => [CompetitorMentionGuard::TOGGLE => true],
        ])->save();
    }

    public function test_hard_scrub_cleans_a_stubborn_article_without_an_llm(): void
    {
        // No LLM is configured in tests, so both scrub passes no-op — exactly
        // the situation that used to strand the topic for a human.
        $this->guardPlan();
        $this->article->forceFill([
            'html' => '<p>Buy from Holafly today. See <a href="https://holafly.com/bali">Holafly plans</a> '
                .'and our <a href="https://example.org/guide">own guide</a>.</p>'
                .'<h2 id="s1">Why Holafly, Saily and Holafly win</h2>'
                .'<figure><img src="/x.png" alt="Holafly esim packaging"></figure>',
            'meta_title' => 'Holafly review for Bali',
        ])->save();

        $clean = app(ContentArticleProducer::class)->cleanCurrentArticle($this->topic->refresh());

        $this->assertTrue($clean, 'the deterministic fallback must clean what the LLM scrub could not');
        $current = $this->topic->articles()->where('is_current', true)->firstOrFail();
        $this->assertSame('brand_scrub_hard', $current->generation_meta['stage'] ?? null);
        $this->assertStringNotContainsStringIgnoringCase('holafly', $current->html);
        $this->assertStringNotContainsStringIgnoringCase('saily', $current->html);
        $this->assertStringNotContainsStringIgnoringCase('holafly', (string) $current->meta_title);
        // The competitor link is gone; unrelated links survive untouched.
        $this->assertStringNotContainsString('holafly.com', $current->html);
        $this->assertStringContainsString('https://example.org/guide', $current->html);
        // Enumerations collapse instead of stuttering the replacement.
        $this->assertStringNotContainsString('other brands, other brands', $current->html);
    }

    public function test_hard_scrub_never_edits_urls_of_unrelated_links(): void
    {
        $this->guardPlan();
        $this->article->forceFill([
            'html' => '<p>Saily is popular. Read <a href="https://example.org/saily-comparison-notes">our notes</a>.</p>',
        ])->save();

        app(ContentArticleProducer::class)->cleanCurrentArticle($this->topic->refresh());

        $current = $this->topic->articles()->where('is_current', true)->firstOrFail();
        // Text mention replaced, but the term inside an UNBLOCKED href stays —
        // attributes are never rewritten (broken URLs are worse than a slug word).
        $this->assertStringContainsString('https://example.org/saily-comparison-notes', $current->html);
        $this->assertMatchesRegularExpression('/<p>other brands is popular/i', $current->html);
    }

    public function test_produce_style_hard_scrub_also_cleans_the_slug(): void
    {
        $this->guardPlan();
        $this->article->forceFill([
            'html' => '<p>Holafly appears here.</p>',
            'slug' => 'best-holafly-esim-bali',
        ])->save();
        $this->topic->forceFill(['status' => ContentTopic::STATUS_APPROVED])->save();

        // cleanCurrentArticle never touches slugs (live URL); the produce()
        // path passes editSlug — exercise the helper directly through it.
        $producer = app(ContentArticleProducer::class);
        $ref = new \ReflectionMethod($producer, 'hardScrub');
        $context = ['site_urls' => [], 'toggles' => []];
        $article = $ref->invoke($producer, $this->article->refresh(), $this->topic, $this->plan->refresh(), $context, true);

        $this->assertSame('best-esim-bali', $article->slug);
        $this->assertStringNotContainsStringIgnoringCase('holafly', $article->html);
    }
}
