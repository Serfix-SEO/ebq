<?php

namespace Tests\Feature\Content;

use App\Jobs\PublishContentArticleJob;
use App\Mail\ContentArticlePublishedMail;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Publishing\PublishDriverFactory;
use App\Support\Audit\SafeHttpGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Your new article is live" email — sent once per topic when at least one
 * integration confirms, best-effort (a mail failure never fails the publish).
 */
class ContentPublishNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->app->bind(SafeHttpGuard::class, fn () => new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    /** @return array{0: User, 1: Website, 2: ContentTopic, 3: ContentArticle} */
    private function scheduledArticle(): array
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Owner']);
        $website = Website::factory()->for($user)->create(['domain' => 'client-blog.com']);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'publish_days' => [],
            'timezone' => 'UTC',
        ]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->subDay(),
            'target_keyword' => 'long lasting arabic perfumes',
            'secondary_keywords' => ['oud perfume', 'attar for men'],
            'keyword_volume' => 1900,
        ]);
        $article = ContentArticle::storeVersion($topic, [
            'h1' => 'A Publishable Article',
            'meta_title' => 'A Publishable Article',
            'meta_description' => 'Description.',
            'slug' => 'a-publishable-article',
            'html' => '<h2>One</h2><p>Text.</p><h2>Two</h2><p>More.</p>',
            'word_count' => 1400,
            'seo_score' => 88,
            'seo_issues' => [],
        ]);

        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
            'credentials' => ['site_url' => 'https://client-blog.com', 'username' => 'admin', 'app_password' => 'abcd efgh'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        return [$user, $website, $topic, $article];
    }

    private function fakeWordPress(): void
    {
        Http::fake([
            'client-blog.com/wp-json/wp/v2/posts' => Http::response(['id' => 321, 'link' => 'https://client-blog.com/a-publishable-article/', 'status' => 'publish'], 201),
            'client-blog.com/a-publishable-article*' => Http::response('<html><h1>A Publishable Article</h1></html>', 200),
        ]);
    }

    private function runPublish(ContentTopic $topic): void
    {
        (new PublishContentArticleJob($topic->id))->handle(
            app(PublishDriverFactory::class),
            app(SafeHttpGuard::class),
        );
    }

    public function test_owner_is_emailed_when_an_article_goes_live(): void
    {
        Mail::fake();
        $this->fakeWordPress();
        [, , $topic] = $this->scheduledArticle();

        $this->runPublish($topic);

        Mail::assertQueued(ContentArticlePublishedMail::class, function (ContentArticlePublishedMail $mail) {
            return $mail->hasTo('owner@example.com')
                && $mail->facts['live_url'] === 'https://client-blog.com/a-publishable-article/'
                && $mail->facts['seo_score'] === 88
                && $mail->facts['target_keyword'] === 'long lasting arabic perfumes'
                && $mail->facts['section_count'] === 2
                && $mail->facts['platforms'] === ['WordPress'];
        });

        $topic->refresh();
        $this->assertNotEmpty($topic->meta['published_notified_at'] ?? null);
    }

    public function test_notification_is_sent_only_once_per_topic(): void
    {
        Mail::fake();
        $this->fakeWordPress();
        [, , $topic] = $this->scheduledArticle();

        $this->runPublish($topic);
        // Re-open the topic (as a regenerated version would) and publish again.
        $topic->forceFill(['status' => ContentTopic::STATUS_SCHEDULED])->save();
        $this->runPublish($topic->fresh());

        Mail::assertQueuedCount(1);
    }

    public function test_publish_still_succeeds_when_the_mailer_throws(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->fakeWordPress();
        [, , $topic] = $this->scheduledArticle();

        $this->runPublish($topic);

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->status);
        $this->assertNull($topic->meta['published_notified_at'] ?? null);
    }

    public function test_email_renders_with_the_publish_details(): void
    {
        $this->fakeWordPress();
        [$user, $website, $topic, $article] = $this->scheduledArticle();
        $topic->forceFill(['published_at' => now()])->save();

        $html = (new ContentArticlePublishedMail(
            user: $user,
            website: $website,
            topic: $topic,
            article: $article,
            liveUrl: 'https://client-blog.com/a-publishable-article/',
            platforms: ['WordPress'],
        ))->render();

        $this->assertStringContainsString('A Publishable Article', $html);
        $this->assertStringContainsString('client-blog.com', $html);
        $this->assertStringContainsString('long lasting arabic perfumes', $html);
        $this->assertStringContainsString('oud perfume', $html);
        $this->assertStringContainsString('1,900', $html);   // monthly searches
        $this->assertStringContainsString('88', $html);      // seo score
        $this->assertStringContainsString('https://client-blog.com/a-publishable-article/', $html);
    }
}
