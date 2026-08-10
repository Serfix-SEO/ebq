<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Publishing\HubSpotDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class HubSpotDriverTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private function integration(Website $website, array $config = []): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_HUBSPOT,
            'credentials' => ['token' => 'pat-na1-secret-token'],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);
    }

    private function connectedConfig(): array
    {
        return [
            'content_group_id' => '777',
            'blog_url' => 'https://blog.demo.com',
            'blog_author_id' => '42',
            'post_status' => 'publish',
        ];
    }

    public function test_verify_caches_blogs_and_reuses_the_existing_author(): void
    {
        Http::fake([
            'api.hubapi.com/cms/v3/blog-settings/settings*' => Http::response(['results' => [
                ['id' => 777, 'name' => 'Main blog', 'url' => 'https://blog.demo.com/'],
                ['id' => 888, 'name' => 'Second blog', 'url' => 'https://blog2.demo.com'],
            ]]),
            'api.hubapi.com/cms/v3/blogs/authors*' => Http::response(['results' => [['id' => 42, 'displayName' => 'Jo']]]),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $driver = app(HubSpotDriver::class);
        $result = $driver->verify($integration);

        $this->assertTrue($result->ok);
        $integration->refresh();
        $this->assertCount(2, $integration->config['available_blogs']);
        $this->assertSame('42', $integration->config['blog_author_id']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer pat-na1-secret-token'));

        $steps = $driver->targets($integration);
        $this->assertSame('blog', $steps[0]['key']);
        $this->assertSame(['777', '888'], array_column($steps[0]['options'], 'id'));

        $this->assertTrue($driver->selectTarget($integration, 'blog', '777')->ok);
        $integration->refresh();
        $this->assertSame('777', $integration->config['content_group_id']);
        $this->assertSame('https://blog.demo.com', $integration->config['blog_url']);
        $this->assertSame([], $driver->targets($integration));
    }

    public function test_verify_creates_an_author_when_the_portal_has_none(): void
    {
        Http::fake([
            'api.hubapi.com/cms/v3/blog-settings/settings*' => Http::response(['results' => [
                ['id' => 777, 'name' => 'Main blog', 'url' => 'https://blog.demo.com'],
            ]]),
            'api.hubapi.com/cms/v3/blogs/authors*' => Http::sequence()
                ->push(['results' => []])
                ->push(['id' => 99, 'displayName' => 'demo.com']),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $this->assertTrue(app(HubSpotDriver::class)->verify($integration)->ok);
        $this->assertSame('99', $integration->refresh()->config['blog_author_id']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains((string) $r->url(), '/cms/v3/blogs/authors')
            && ($r->data()['displayName'] ?? '') !== '');
    }

    public function test_verify_rejects_a_bad_token_as_a_hard_failure(): void
    {
        Http::fake(['api.hubapi.com/*' => Http::response(['message' => 'nope'], 401)]);
        [, $website] = $this->scheduledArticle();

        $result = app(HubSpotDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
    }

    public function test_publish_posts_raw_html_and_publishes_in_one_call(): void
    {
        Http::fake(['api.hubapi.com/cms/v3/blogs/posts' => Http::response([
            'id' => '3001', 'url' => 'https://blog.demo.com/a-publishable-article', 'state' => 'PUBLISHED',
        ], 201)]);
        [, $website, , , $article] = $this->scheduledArticle(['canonical_url' => 'https://demo.com/canonical']);
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(HubSpotDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('3001', $result->externalId);
        $this->assertSame('https://blog.demo.com/a-publishable-article', $result->externalUrl);

        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->method() === 'POST'
                && $d['name'] === 'A Publishable Article'
                && $d['slug'] === 'a-publishable-article'
                && str_contains($d['postBody'], '<strong>bold</strong>')
                && $d['metaDescription'] === 'Description.'
                && $d['contentGroupId'] === '777'
                && $d['blogAuthorId'] === '42'
                && $d['state'] === 'PUBLISHED'
                && $d['linkRelCanonicalUrl'] === 'https://demo.com/canonical';
        });
    }

    public function test_draft_mode_creates_a_draft(): void
    {
        Http::fake(['api.hubapi.com/cms/v3/blogs/posts' => Http::response(['id' => '3001', 'url' => null, 'state' => 'DRAFT'], 201)]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft'] + $this->connectedConfig());

        $result = app(HubSpotDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertNull($result->externalUrl);
        Http::assertSent(fn (Request $r) => $r->data()['state'] === 'DRAFT');
    }

    public function test_update_patches_the_existing_post(): void
    {
        Http::fake(['api.hubapi.com/cms/v3/blogs/posts/3001' => Http::response([
            'id' => '3001', 'url' => 'https://blog.demo.com/a-publishable-article', 'state' => 'PUBLISHED',
        ])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(HubSpotDriver::class)->update($article, $integration, '3001');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with((string) $r->url(), '/cms/v3/blogs/posts/3001'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_featured_image_is_passed_by_url(): void
    {
        Http::fake(['api.hubapi.com/cms/v3/blogs/posts' => Http::response(['id' => '1', 'url' => 'https://b/x'], 201)]);
        [, $website, , , $article] = $this->scheduledArticle();
        $image = $this->featuredImage($article);
        $integration = $this->integration($website, $this->connectedConfig());

        app(HubSpotDriver::class)->publish($article, $integration);

        Http::assertSent(fn (Request $r) => $r->data()['featuredImage'] === $image->url()
            && $r->data()['useFeaturedImage'] === true
            && $r->data()['featuredImageAltText'] === 'Featured alt');
    }

    public function test_error_mapping_transient_vs_hard(): void
    {
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());
        $driver = app(HubSpotDriver::class);

        Http::fake(['api.hubapi.com/*' => Http::sequence()
            ->push('oops', 500)
            ->push('slow down', 429)
            ->push(['message' => 'PARENT_BLOG_DOES_NOT_EXIST'], 400)]);

        $server = $driver->publish($article, $integration);
        $this->assertTrue($server->transient);

        $limited = $driver->publish($article, $integration);
        $this->assertTrue($limited->transient);

        $hard = $driver->publish($article, $integration);
        $this->assertFalse($hard->ok);
        $this->assertFalse($hard->transient);
        $this->assertStringContainsString('PARENT_BLOG_DOES_NOT_EXIST', (string) $hard->error);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $this->assertCredentialEncrypted($integration->id, 'pat-na1-secret-token');
    }
}
