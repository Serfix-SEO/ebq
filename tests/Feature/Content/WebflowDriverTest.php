<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Publishing\WebflowDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class WebflowDriverTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private function integration(Website $website, array $config = []): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBFLOW,
            'credentials' => ['api_token' => 'wf_secret_token'],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);
    }

    private function connectedConfig(): array
    {
        return [
            'site_id' => 'site-1',
            'site_domain' => 'blog.demo.com',
            'collection_id' => 'coll-1',
            'collection_slug' => 'blog-posts',
            'body_field' => 'post-body',
            'image_field' => 'main-image',
            'summary_field' => 'post-summary',
            'post_status' => 'publish',
        ];
    }

    public function test_two_step_targets_site_then_collection_with_field_automap(): void
    {
        Http::fake([
            'api.webflow.com/v2/sites' => Http::response(['sites' => [
                ['id' => 'site-1', 'displayName' => 'Demo site', 'shortName' => 'demo', 'customDomains' => [['url' => 'https://blog.demo.com']]],
                ['id' => 'site-2', 'displayName' => 'Other site', 'shortName' => 'other', 'customDomains' => []],
            ]]),
            'api.webflow.com/v2/sites/site-1/collections' => Http::response(['collections' => [
                ['id' => 'coll-1', 'displayName' => 'Blog Posts', 'slug' => 'blog-posts'],
                ['id' => 'coll-2', 'displayName' => 'Team', 'slug' => 'team'],
            ]]),
            'api.webflow.com/v2/collections/coll-1' => Http::response(['fields' => [
                ['slug' => 'name', 'type' => 'PlainText'],
                ['slug' => 'slug', 'type' => 'PlainText'],
                ['slug' => 'post-summary', 'type' => 'PlainText'],
                ['slug' => 'post-body', 'type' => 'RichText'],
                ['slug' => 'main-image', 'type' => 'Image'],
            ]]),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);
        $driver = app(WebflowDriver::class);

        $this->assertTrue($driver->verify($integration)->ok);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer wf_secret_token'));

        $steps = $driver->targets($integration->refresh());
        $this->assertSame('site', $steps[0]['key']);
        $this->assertSame(['site-1', 'site-2'], array_column($steps[0]['options'], 'id'));

        $this->assertTrue($driver->selectTarget($integration, 'site', 'site-1')->ok);
        $integration->refresh();
        $this->assertSame('blog.demo.com', $integration->config['site_domain']);

        $steps = $driver->targets($integration);
        $this->assertSame('collection', $steps[0]['key']);
        $this->assertSame(['coll-1', 'coll-2'], array_column($steps[0]['options'], 'id'));

        $this->assertTrue($driver->selectTarget($integration, 'collection', 'coll-1')->ok);
        $integration->refresh();
        $this->assertSame('post-body', $integration->config['body_field']);
        $this->assertSame('main-image', $integration->config['image_field']);
        $this->assertSame('post-summary', $integration->config['summary_field']);
        $this->assertSame('blog-posts', $integration->config['collection_slug']);
        $this->assertSame([], $driver->targets($integration));
    }

    public function test_collection_without_richtext_field_is_a_hard_error(): void
    {
        Http::fake([
            'api.webflow.com/v2/collections/coll-2' => Http::response(['fields' => [
                ['slug' => 'name', 'type' => 'PlainText'],
                ['slug' => 'photo', 'type' => 'Image'],
            ]]),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website, [
            'site_id' => 'site-1', 'site_domain' => 'blog.demo.com',
            'available_collections' => [['id' => 'coll-2', 'label' => 'Team', 'slug' => 'team']],
        ]);

        $result = app(WebflowDriver::class)->selectTarget($integration, 'collection', 'coll-2');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Rich text', (string) $result->error);
    }

    public function test_verify_rejects_a_bad_token_as_a_hard_failure(): void
    {
        Http::fake(['api.webflow.com/*' => Http::response(['message' => 'unauthorized'], 401)]);
        [, $website] = $this->scheduledArticle();

        $result = app(WebflowDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
    }

    public function test_publish_creates_a_live_item_and_builds_the_collection_url(): void
    {
        Http::fake(['api.webflow.com/v2/collections/coll-1/items/live' => Http::response([
            'id' => 'item-9', 'isDraft' => false,
            'fieldData' => ['slug' => 'a-publishable-article'],
        ], 202)]);
        [, $website, , , $article] = $this->scheduledArticle();
        $image = $this->featuredImage($article);
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(WebflowDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('item-9', $result->externalId);
        $this->assertSame('https://blog.demo.com/blog-posts/a-publishable-article', $result->externalUrl);

        Http::assertSent(function (Request $r) use ($image) {
            $fields = $r->data()['fieldData'] ?? [];

            return $r->method() === 'POST'
                && $fields['name'] === 'A Publishable Article'
                && $fields['slug'] === 'a-publishable-article'
                && str_contains($fields['post-body'], '<strong>bold</strong>')
                && $fields['post-summary'] === 'Description.'
                && ($fields['main-image']['url'] ?? null) === $image->url()
                && ($fields['main-image']['alt'] ?? null) === 'Featured alt';
        });
    }

    public function test_draft_mode_creates_a_staged_item_without_a_url(): void
    {
        Http::fake(['api.webflow.com/v2/collections/coll-1/items' => Http::response([
            'id' => 'item-9', 'isDraft' => true, 'fieldData' => ['slug' => 'a-publishable-article'],
        ], 202)]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft'] + $this->connectedConfig());

        $result = app(WebflowDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertNull($result->externalUrl);
        Http::assertSent(fn (Request $r) => str_ends_with((string) $r->url(), '/collections/coll-1/items')
            && $r->data()['isDraft'] === true);
    }

    public function test_update_patches_the_live_item(): void
    {
        Http::fake(['api.webflow.com/v2/collections/coll-1/items/item-9/live' => Http::response([
            'id' => 'item-9', 'isDraft' => false, 'fieldData' => ['slug' => 'a-publishable-article'],
        ])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(WebflowDriver::class)->update($article, $integration, 'item-9');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with((string) $r->url(), '/collections/coll-1/items/item-9/live'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_error_mapping_transient_vs_hard(): void
    {
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());
        $driver = app(WebflowDriver::class);

        Http::fake(['api.webflow.com/*' => Http::sequence()
            ->push('oops', 500)
            ->push('slow down', 429)
            ->push(['message' => 'Validation: slug already in use'], 400)]);

        $this->assertTrue($driver->publish($article, $integration)->transient);
        $this->assertTrue($driver->publish($article, $integration)->transient);

        $hard = $driver->publish($article, $integration);
        $this->assertFalse($hard->ok);
        $this->assertFalse($hard->transient);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $this->assertCredentialEncrypted($integration->id, 'wf_secret_token');
    }
}
