<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Publishing\ShopifyDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ShopifyDriverTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private const GQL = 'https://demo-store.myshopify.com/admin/api/'.ShopifyDriver::API_VERSION.'/graphql.json';

    private function integration(Website $website, array $config = []): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_SHOPIFY,
            'credentials' => ['store_domain' => 'demo-store.myshopify.com', 'access_token' => 'shpat_secret_token'],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);
    }

    private function connectedConfig(): array
    {
        return [
            'shop_url' => 'https://demo.example.com',
            'blog_id' => 'gid://shopify/Blog/11',
            'blog_handle' => 'news',
            'post_status' => 'publish',
        ];
    }

    private function verifyResponse(): array
    {
        return ['data' => [
            'shop' => ['name' => 'Demo', 'primaryDomain' => ['url' => 'https://demo.example.com/']],
            'blogs' => ['nodes' => [
                ['id' => 'gid://shopify/Blog/11', 'title' => 'News', 'handle' => 'news'],
                ['id' => 'gid://shopify/Blog/22', 'title' => 'Guides', 'handle' => 'guides'],
            ]],
        ]];
    }

    public function test_verify_caches_blog_options_and_surfaces_a_target_step(): void
    {
        Http::fake([self::GQL => Http::response($this->verifyResponse())]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $driver = app(ShopifyDriver::class);
        $result = $driver->verify($integration);

        $this->assertTrue($result->ok);
        $integration->refresh();
        $this->assertSame('https://demo.example.com', $integration->config['shop_url']);
        $this->assertCount(2, $integration->config['available_blogs']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_secret_token')
            && str_contains((string) $r->url(), '/admin/api/'.ShopifyDriver::API_VERSION.'/graphql.json'));

        $steps = $driver->targets($integration);
        $this->assertCount(1, $steps);
        $this->assertSame('blog', $steps[0]['key']);
        $this->assertSame(['gid://shopify/Blog/11', 'gid://shopify/Blog/22'], array_column($steps[0]['options'], 'id'));

        $this->assertTrue($driver->selectTarget($integration, 'blog', 'gid://shopify/Blog/22')->ok);
        $integration->refresh();
        $this->assertSame('gid://shopify/Blog/22', $integration->config['blog_id']);
        $this->assertSame('guides', $integration->config['blog_handle']);
        $this->assertSame([], $driver->targets($integration));
    }

    public function test_verify_rejects_a_bad_token_as_a_hard_failure(): void
    {
        Http::fake([self::GQL => Http::response(['errors' => 'unauthorized'], 401)]);
        [, $website] = $this->scheduledArticle();

        $result = app(ShopifyDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
    }

    public function test_publish_sends_article_create_and_builds_the_online_store_url(): void
    {
        Http::fake([self::GQL => Http::response(['data' => ['articleCreate' => [
            'article' => ['id' => 'gid://shopify/Article/900', 'handle' => 'a-publishable-article', 'blog' => ['handle' => 'news']],
            'userErrors' => [],
        ]]])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(ShopifyDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('gid://shopify/Article/900', $result->externalId);
        $this->assertSame('https://demo.example.com/blogs/news/a-publishable-article', $result->externalUrl);

        Http::assertSent(function (Request $r) {
            $body = json_decode($r->body(), true);
            $input = $body['variables']['article'] ?? [];

            return str_contains($body['query'] ?? '', 'articleCreate')
                && $input['blogId'] === 'gid://shopify/Blog/11'
                && $input['title'] === 'A Publishable Article'
                && $input['handle'] === 'a-publishable-article'
                && str_contains($input['body'], '<strong>bold</strong>')
                && $input['isPublished'] === true
                && $input['tags'] === ['alpha keyword', 'beta keyword'];
        });
    }

    public function test_update_routes_to_article_update_with_the_existing_id(): void
    {
        Http::fake([self::GQL => Http::response(['data' => ['articleUpdate' => [
            'article' => ['id' => 'gid://shopify/Article/900', 'handle' => 'a-publishable-article', 'blog' => ['handle' => 'news']],
            'userErrors' => [],
        ]]])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(ShopifyDriver::class)->update($article, $integration, 'gid://shopify/Article/900');

        $this->assertTrue($result->ok);
        Http::assertSent(function (Request $r) {
            $body = json_decode($r->body(), true);

            return str_contains($body['query'] ?? '', 'articleUpdate')
                && ($body['variables']['id'] ?? null) === 'gid://shopify/Article/900'
                && ! isset($body['variables']['article']['blogId']);
        });
        Http::assertNotSent(fn (Request $r) => str_contains(json_decode($r->body(), true)['query'] ?? '', 'articleCreate'));
    }

    public function test_draft_mode_unpublishes_and_returns_no_url(): void
    {
        Http::fake([self::GQL => Http::response(['data' => ['articleCreate' => [
            'article' => ['id' => 'gid://shopify/Article/900', 'handle' => 'a-publishable-article', 'blog' => ['handle' => 'news']],
            'userErrors' => [],
        ]]])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft'] + $this->connectedConfig());

        $result = app(ShopifyDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertNull($result->externalUrl);
        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['variables']['article']['isPublished'] === false);
    }

    public function test_featured_image_is_passed_as_a_url_shopify_fetches(): void
    {
        Http::fake([self::GQL => Http::response(['data' => ['articleCreate' => [
            'article' => ['id' => 'gid://shopify/Article/1', 'handle' => 'a-publishable-article', 'blog' => ['handle' => 'news']],
            'userErrors' => [],
        ]]])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $image = $this->featuredImage($article);
        $integration = $this->integration($website, $this->connectedConfig());

        app(ShopifyDriver::class)->publish($article, $integration);

        Http::assertSent(function (Request $r) use ($image) {
            $input = json_decode($r->body(), true)['variables']['article'] ?? [];

            return ($input['image']['url'] ?? null) === $image->url()
                && ($input['image']['altText'] ?? null) === 'Featured alt';
        });
    }

    public function test_user_errors_are_a_hard_failure(): void
    {
        Http::fake([self::GQL => Http::response(['data' => ['articleCreate' => [
            'article' => null,
            'userErrors' => [['field' => 'handle', 'message' => 'Handle is invalid']],
        ]]])]);
        [, $website, , , $article] = $this->scheduledArticle();

        $result = app(ShopifyDriver::class)->publish($article, $this->integration($website, $this->connectedConfig()));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
        $this->assertStringContainsString('Handle is invalid', (string) $result->error);
    }

    public function test_throttle_and_server_errors_are_transient(): void
    {
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());
        $driver = app(ShopifyDriver::class);

        Http::fake([self::GQL => Http::sequence()
            ->push(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])
            ->push('oops', 500)
            ->push('slow down', 429)]);

        $throttled = $driver->publish($article, $integration);
        $this->assertFalse($throttled->ok);
        $this->assertTrue($throttled->transient);

        $server = $driver->publish($article, $integration);
        $this->assertFalse($server->ok);
        $this->assertTrue($server->transient);

        $limited = $driver->publish($article, $integration);
        $this->assertFalse($limited->ok);
        $this->assertTrue($limited->transient);
    }

    public function test_rejects_a_non_myshopify_domain_without_calling_out(): void
    {
        Http::fake();
        [, $website] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_SHOPIFY,
            'credentials' => ['store_domain' => 'evil.example.com', 'access_token' => 'shpat_x'],
            'status' => ContentIntegration::STATUS_PENDING,
        ]);

        $result = app(ShopifyDriver::class)->verify($integration);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('.myshopify.com', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $this->assertCredentialEncrypted($integration->id, 'shpat_secret_token');
    }

    public function test_publish_job_routes_through_the_factory_and_carries_the_prior_external_id(): void
    {
        Http::fake([
            self::GQL => Http::response(['data' => ['articleUpdate' => [
                'article' => ['id' => 'gid://shopify/Article/900', 'handle' => 'a-publishable-article', 'blog' => ['handle' => 'news']],
                'userErrors' => [],
            ]]]),
            'demo.example.com/*' => Http::response('<h1>A Publishable Article</h1>', 200),
        ]);
        [, $website, , $topic, $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        // A prior article version already published to Shopify — a regenerated
        // article must update that post, not create a duplicate.
        $priorArticle = \App\Models\ContentArticle::storeVersion($topic, [
            'h1' => 'Old Version', 'meta_title' => 'Old', 'meta_description' => 'Old.',
            'slug' => 'a-publishable-article', 'html' => '<p>old</p>', 'word_count' => 100,
            'seo_score' => 50, 'seo_issues' => [],
        ]);
        $article->refresh()->forceFill(['is_current' => true])->save();
        $priorArticle->forceFill(['is_current' => false])->save();
        \App\Models\ContentPublication::query()->create([
            'article_id' => $priorArticle->id,
            'integration_id' => $integration->id,
            'status' => \App\Models\ContentPublication::STATUS_CONFIRMED,
            'external_id' => 'gid://shopify/Article/900',
            'published_at' => now()->subDay(),
        ]);

        (new \App\Jobs\PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        $topic->refresh();
        $this->assertSame(\App\Models\ContentTopic::STATUS_PUBLISHED, $topic->status);

        Http::assertSent(fn (Request $r) => str_contains(json_decode($r->body(), true)['query'] ?? '', 'articleUpdate')
            && (json_decode($r->body(), true)['variables']['id'] ?? null) === 'gid://shopify/Article/900');
        Http::assertNotSent(fn (Request $r) => str_contains(json_decode($r->body(), true)['query'] ?? '', 'articleCreate'));

        $publication = \App\Models\ContentPublication::query()->where('article_id', $article->id)->first();
        $this->assertSame(\App\Models\ContentPublication::STATUS_CONFIRMED, $publication->status);
        $this->assertSame('gid://shopify/Article/900', $publication->external_id);
    }
}
