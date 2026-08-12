<?php

namespace Tests\Feature\Content;

use App\Jobs\PublishContentArticleJob;
use App\Livewire\Content\ContentCalendar;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Calendar "Republish" — re-send an already-published article to the destinations. */
class ContentRepublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Website $website;

    private ContentTopic $topic;

    private ContentArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });

        $this->user = User::factory()->create();
        $this->website = Website::factory()->for($this->user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $this->website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'publish_days' => [],
            'timezone' => 'UTC',
        ]);
        $this->topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $this->website->id,
            'status' => ContentTopic::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
        $this->article = ContentArticle::storeVersion($this->topic, [
            'h1' => 'A Published Article', 'meta_title' => 'A Published Article',
            'meta_description' => 'Description.', 'slug' => 'a-published-article',
            'html' => '<p>Body.</p>', 'word_count' => 500, 'seo_score' => 90, 'seo_issues' => [],
        ]);
        session(['current_website_id' => $this->website->id]);
    }

    private function webhookIntegration(): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $this->website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'credentials' => ['endpoint_url' => 'https://client.test/receive', 'secret' => str_repeat('s', 40)],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
    }

    public function test_republish_rearms_claims_and_dispatches_the_publish_job(): void
    {
        Queue::fake();
        $integration = $this->webhookIntegration();
        ContentPublication::query()->create([
            'article_id' => $this->article->id,
            'integration_id' => $integration->id,
            'status' => ContentPublication::STATUS_CONFIRMED,
            'external_id' => 'a-published-article',
            'external_url' => 'https://client.test/blog/a-published-article',
            'published_at' => now()->subDay(),
        ]);

        Livewire::actingAs($this->user)
            ->test(ContentCalendar::class)
            ->call('republish', $this->topic->id);

        $this->assertSame(ContentTopic::STATUS_PUBLISHING, $this->topic->refresh()->status);
        // Claim re-armed but the external id survives → destination gets an update.
        $publication = ContentPublication::query()->firstOrFail();
        $this->assertSame(ContentPublication::STATUS_QUEUED, $publication->status);
        $this->assertSame('a-published-article', $publication->external_id);
        Queue::assertPushed(PublishContentArticleJob::class, 1);
    }

    public function test_republished_delivery_goes_out_as_an_update_not_a_duplicate(): void
    {
        Http::fake(['client.test/*' => Http::response(['url' => 'https://client.test/blog/a-published-article'], 200)]);
        $integration = $this->webhookIntegration();
        ContentPublication::query()->create([
            'article_id' => $this->article->id,
            'integration_id' => $integration->id,
            'status' => ContentPublication::STATUS_QUEUED, // as republish() leaves it
            'external_id' => 'a-published-article',
        ]);
        $this->topic->forceFill(['status' => ContentTopic::STATUS_PUBLISHING])->save();

        (new PublishContentArticleJob($this->topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $this->topic->refresh()->status);
        Http::assertSent(fn (Request $r) => str_contains((string) $r->body(), '"event":"article.updated"'));
    }

    public function test_republish_requires_a_connected_destination(): void
    {
        Queue::fake();

        Livewire::actingAs($this->user)
            ->test(ContentCalendar::class)
            ->call('republish', $this->topic->id);

        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $this->topic->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_republish_ignores_non_published_topics(): void
    {
        Queue::fake();
        $this->webhookIntegration();
        $this->topic->forceFill(['status' => ContentTopic::STATUS_READY])->save();

        Livewire::actingAs($this->user)
            ->test(ContentCalendar::class)
            ->call('republish', $this->topic->id);

        $this->assertSame(ContentTopic::STATUS_READY, $this->topic->refresh()->status);
        Queue::assertNothingPushed();
    }
}
