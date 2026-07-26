<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleIndexingService;
use Database\Seeders\PlanSeeder;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ContentIndexingTest extends TestCase
{
    use RefreshDatabase;

    /** A real Google\Client seeded with a live token (no refresh needed) —
     *  satisfies GoogleClientFactory::make()'s return type. */
    private function bindFakeGoogleClient(): void
    {
        $client = new Client;
        $client->setAccessToken(['access_token' => 'tok', 'expires_in' => 3600, 'created' => time()]);
        $this->mock(GoogleClientFactory::class, fn ($m) => $m->shouldReceive('make')->andReturn($client));
    }

    public function test_submit_returns_not_connected_without_gsc(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $this->assertFalse($website->hasGsc());

        $result = app(GoogleIndexingService::class)->submitUrl($website, 'https://example.com/post');

        $this->assertFalse($result['ok']);
        $this->assertSame('not_connected', $result['status']);
    }

    public function test_submit_posts_to_indexing_api_when_gsc_connected(): void
    {
        Http::fake(['indexing.googleapis.com/*' => Http::response(['urlNotificationMetadata' => []], 200)]);
        $user = User::factory()->create();
        $website = Website::factory()->withGscOnly()->create(['user_id' => $user->id]);
        $this->assertTrue($website->fresh()->hasGsc());
        $this->bindFakeGoogleClient();

        $result = app(GoogleIndexingService::class)->submitUrl($website->fresh(), 'https://example.com/new-post');

        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertSame('submitted', $result['status']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'urlNotifications:publish')
            && $req['url'] === 'https://example.com/new-post'
            && $req['type'] === 'URL_UPDATED');
    }

    public function test_submit_flags_reconnect_on_insufficient_scope(): void
    {
        Http::fake(['indexing.googleapis.com/*' => Http::response(['error' => ['message' => 'Request had insufficient authentication scopes.']], 403)]);
        $user = User::factory()->create();
        $website = Website::factory()->withGscOnly()->create(['user_id' => $user->id]);
        $this->bindFakeGoogleClient();

        $result = app(GoogleIndexingService::class)->submitUrl($website->fresh(), 'https://example.com/post');

        $this->assertFalse($result['ok']);
        $this->assertSame('reconnect', $result['status']);
    }

    public function test_review_page_shows_connect_gsc_notice_when_not_connected(): void
    {
        $this->seed(PlanSeeder::class);
        $user = User::factory()->create();
        $website = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, ['h1' => 'X', 'html' => '<p>Body.</p>', 'word_count' => 2, 'seo_score' => 50, 'seo_issues' => []]);
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSee(__('Connect Google Search Console'));
    }

    public function test_review_page_shows_seo_targets(): void
    {
        $this->seed(PlanSeeder::class);
        $user = User::factory()->create();
        $website = Website::factory()->withNoSources()->create(['user_id' => $user->id]);
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'luxury seo dubai',
            'secondary_keywords' => ['prestige branding', 'high-end marketing'],
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, ['h1' => 'X', 'html' => '<p>Body.</p>', 'word_count' => 2, 'seo_score' => 50, 'seo_issues' => []]);
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSee(__('SEO targets'))
            ->assertSee('luxury seo dubai')
            ->assertSee('prestige branding')
            ->assertSee('high-end marketing');
    }
}
