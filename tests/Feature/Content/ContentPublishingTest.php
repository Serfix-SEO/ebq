<?php

namespace Tests\Feature\Content;

use App\Jobs\PublishContentArticleJob;
use App\Livewire\Content\PublishingSettings;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        // SafeHttpGuard does live DNS resolution; test hostnames must not
        // depend on the internet. All HTTP is Http::fake()d anyway.
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    /** @return array{0: User, 1: Website, 2: ContentPlan, 3: ContentTopic, 4: ContentArticle} */
    private function scheduledArticle(array $planAttrs = []): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'publish_days' => [],
            'timezone' => 'UTC',
        ], $planAttrs));
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->subDay(),
        ]);
        $article = ContentArticle::storeVersion($topic, [
            'h1' => 'A Publishable Article',
            'meta_title' => 'A Publishable Article',
            'meta_description' => 'Description.',
            'slug' => 'a-publishable-article',
            'html' => '<h2>Body</h2><p>Text.</p>',
            'word_count' => 500,
            'seo_score' => 90,
            'seo_issues' => [],
        ]);

        return [$user, $website, $plan, $topic, $article];
    }

    private function wordpressIntegration(Website $website): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
            'credentials' => ['site_url' => 'https://client-blog.com', 'username' => 'admin', 'app_password' => 'abcd efgh'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
    }

    public function test_publish_job_publishes_to_wordpress_and_marks_topic_published(): void
    {
        Http::fake([
            'client-blog.com/wp-json/wp/v2/posts' => Http::response(['id' => 321, 'link' => 'https://client-blog.com/a-publishable-article/', 'status' => 'publish'], 201),
            'client-blog.com/a-publishable-article*' => Http::response('<html><h1>A Publishable Article</h1></html>', 200),
        ]);

        [, $website, , $topic, $article] = $this->scheduledArticle();
        $this->wordpressIntegration($website);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->status);
        $this->assertNotNull($topic->published_at);

        $publication = ContentPublication::query()->where('article_id', $article->id)->first();
        $this->assertSame(ContentPublication::STATUS_CONFIRMED, $publication->status);
        $this->assertSame('321', $publication->external_id);
        $this->assertSame('https://client-blog.com/a-publishable-article/', $publication->external_url);
        $this->assertNotNull($publication->verified_at, 'live-URL verification should set verified_at');
    }

    public function test_publish_fills_serfix_plugin_seo_meta_when_plugin_present(): void
    {
        Http::fake([
            'client-blog.com/wp-json/' => Http::response(['namespaces' => ['wp/v2', 'ebq/v1']], 200),
            'client-blog.com/wp-json/wp/v2/posts' => Http::response(['id' => 55, 'link' => 'https://client-blog.com/p/', 'status' => 'publish'], 201),
            'client-blog.com/p*' => Http::response('<h1>x</h1>', 200),
        ]);

        [, $website, , $topic, $article] = $this->scheduledArticle();
        $topic->update(['target_keyword' => 'change pubg name', 'secondary_keywords' => ['pubg rename', 'bgmi name']]);
        $this->wordpressIntegration($website);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/wp/v2/posts')) {
                return false;
            }
            $meta = $req->data()['meta'] ?? [];

            return ($meta['_ebq_focus_keyword'] ?? null) === 'change pubg name'
                && ($meta['_ebq_title'] ?? null) === 'A Publishable Article'
                && str_contains($meta['_ebq_additional_keywords'] ?? '', 'pubg rename');
        });
    }

    public function test_publish_sends_faq_schema_meta_when_plugin_present(): void
    {
        Http::fake([
            'client-blog.com/wp-json/' => Http::response(['namespaces' => ['wp/v2', 'ebq/v1']], 200),
            'client-blog.com/wp-json/wp/v2/posts' => Http::response(['id' => 77, 'link' => 'https://client-blog.com/f/', 'status' => 'publish'], 201),
            'client-blog.com/f*' => Http::response('<h1>x</h1>', 200),
        ]);

        [, $website, , $topic, $article] = $this->scheduledArticle();
        $article->update(['html' => '<h2 id="intro">Intro</h2><p>Hi.</p>'
            .'<h2 id="faq-frequently-asked">Frequently Asked Questions</h2>'
            .'<h3>Can I use spaces in my name?</h3><p>Yes, spaces are allowed.</p>'
            .'<h3>How often can I change it?</h3><p>Once every two weeks.</p>']);
        $this->wordpressIntegration($website);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/wp/v2/posts')) {
                return false;
            }
            $raw = $req->data()['meta']['_ebq_schemas'] ?? null;
            if (! is_string($raw) || $raw === '') {
                return false;
            }
            $decoded = json_decode($raw, true);
            $entry = $decoded[0] ?? [];

            return ($entry['type'] ?? null) === 'FAQPage'
                && ($entry['template'] ?? null) === 'faq'
                && count($entry['data']['questions'] ?? []) === 2
                && ($entry['data']['questions'][0]['question'] ?? null) === 'Can I use spaces in my name?';
        });
    }

    public function test_publish_omits_plugin_meta_when_plugin_absent(): void
    {
        Http::fake([
            'client-blog.com/wp-json/' => Http::response(['namespaces' => ['wp/v2', 'oembed/1.0']], 200),
            'client-blog.com/wp-json/wp/v2/posts' => Http::response(['id' => 56, 'link' => 'https://client-blog.com/q/', 'status' => 'publish'], 201),
            'client-blog.com/q*' => Http::response('<h1>x</h1>', 200),
        ]);

        [, $website, , $topic] = $this->scheduledArticle();
        $this->wordpressIntegration($website);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/wp/v2/posts')
            && ! array_key_exists('meta', $req->data()));
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->fresh()->status);
    }

    public function test_publish_is_idempotent_and_routes_retries_through_update(): void
    {
        Http::fake([
            'client-blog.com/wp-json/wp/v2/posts/321' => Http::response(['id' => 321, 'link' => 'https://client-blog.com/a/', 'status' => 'publish'], 200),
        ]);

        [, $website, , $topic, $article] = $this->scheduledArticle();
        $integration = $this->wordpressIntegration($website);

        // A prior attempt already created the post but the job died before confirm.
        ContentPublication::query()->create([
            'article_id' => $article->id,
            'integration_id' => $integration->id,
            'status' => ContentPublication::STATUS_FAILED,
            'external_id' => '321',
            'attempts' => 1,
        ]);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        // Exactly one publication row, updated via POST /posts/321 (not a new create).
        $this->assertSame(1, ContentPublication::query()->where('article_id', $article->id)->count());
        Http::assertSent(fn ($req) => str_contains($req->url(), '/wp/v2/posts/321'));
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->fresh()->status);
    }

    public function test_hard_failure_on_every_platform_fails_the_topic(): void
    {
        Http::fake([
            'client-blog.com/*' => Http::response(['message' => 'nope'], 403),
        ]);

        [, $website, , $topic] = $this->scheduledArticle();
        $this->wordpressIntegration($website);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $this->assertSame(ContentTopic::STATUS_FAILED, $topic->fresh()->status);
        $this->assertSame(
            ContentPublication::STATUS_FAILED,
            ContentPublication::query()->first()->status
        );
    }

    public function test_no_connected_integration_leaves_topic_scheduled(): void
    {
        Http::fake();
        [, , , $topic] = $this->scheduledArticle();

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $this->assertSame(ContentTopic::STATUS_SCHEDULED, $topic->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_webhook_driver_signs_payload_and_reads_returned_url(): void
    {
        Http::fake([
            'receiver.example.com/*' => Http::response(['url' => 'https://client.com/live-post'], 200),
        ]);

        [, $website, , $topic, $article] = $this->scheduledArticle();
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://receiver.example.com/hook', 'secret' => 'shared-signing-secret'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        (new PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'receiver.example.com')) {
                return false;
            }
            $expected = 'sha256='.hash_hmac('sha256', $req->body(), 'shared-signing-secret');

            return $req->hasHeader('X-Serfix-Signature')
                && $req->header('X-Serfix-Signature')[0] === $expected;
        });

        $publication = ContentPublication::query()->where('article_id', $article->id)->first();
        $this->assertSame(ContentPublication::STATUS_CONFIRMED, $publication->status);
        $this->assertSame('https://client.com/live-post', $publication->external_url);
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->fresh()->status);
    }

    public function test_dispatcher_promotes_auto_publish_ready_topics_and_dispatches_due_scheduled(): void
    {
        Queue::fake();
        [, $website, $plan] = array_slice($this->scheduledArticle([
            'auto_publish' => true, 'review_hours' => 24,
            'publish_hour_start' => 0, 'publish_hour_end' => 23,
        ]), 0, 3);
        $this->wordpressIntegration($website);

        // READY topic past its 24h veto window → should be promoted + eligible.
        $ready = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
            'stage_started_at' => now()->subHours(30),
            'scheduled_for' => now()->subDay(),
        ]);
        // READY topic still inside the window → untouched.
        $fresh = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
            'stage_started_at' => now()->subHours(2),
            'scheduled_for' => now()->subDay(),
        ]);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        $this->assertSame(ContentTopic::STATUS_SCHEDULED, $ready->fresh()->status);
        $this->assertSame(ContentTopic::STATUS_READY, $fresh->fresh()->status);
        Queue::assertPushed(PublishContentArticleJob::class);
    }

    public function test_dispatcher_respects_publish_window(): void
    {
        Queue::fake();
        // Window that can never match: only publish on ISO day that isn't today.
        $notToday = now()->isoWeekday() === 1 ? 2 : 1;
        [, $website] = array_slice($this->scheduledArticle(['publish_days' => [$notToday]]), 0, 2);
        $this->wordpressIntegration($website);

        $this->artisan('ebq:content-autopilot')->assertSuccessful();

        Queue::assertNotPushed(PublishContentArticleJob::class);
    }

    public function test_integrations_page_renders(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get('/content/integrations')
            ->assertOk()
            ->assertSee(__('Where your articles publish'));
    }

    public function test_connect_wordpress_verifies_and_stores_encrypted_credentials(): void
    {
        Http::fake([
            'my-blog.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Admin', 'capabilities' => ['edit_posts' => true]], 200),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(PublishingSettings::class)
            ->set('platform', ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD)
            ->set('wpSiteUrl', 'https://my-blog.com')
            ->set('wpUsername', 'admin')
            ->set('wpAppPassword', 'aaaa bbbb cccc dddd')
            ->call('connect')
            ->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $website->id)->first();
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertNotNull($integration->last_verified_at);
        $this->assertSame('admin', $integration->credentials['username']);
        // Raw DB value must be ciphertext, never plaintext.
        $raw = \Illuminate\Support\Facades\DB::table('content_integrations')->where('id', $integration->id)->value('credentials');
        $this->assertStringNotContainsString('aaaa bbbb', (string) $raw);
    }

    public function test_connect_rejects_bad_wordpress_credentials(): void
    {
        Http::fake([
            'my-blog.com/wp-json/wp/v2/users/me*' => Http::response(['code' => 'invalid'], 401),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(PublishingSettings::class)
            ->set('wpSiteUrl', 'https://my-blog.com')
            ->set('wpUsername', 'admin')
            ->set('wpAppPassword', 'wrong')
            ->call('connect')
            ->assertHasErrors('connect');

        $this->assertSame(
            ContentIntegration::STATUS_ERROR,
            ContentIntegration::query()->first()->status
        );
    }
}
