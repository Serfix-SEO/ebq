<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Publishing\WixDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class WixDriverTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private const SITE_ID = '12345678-abcd-4ef0-9876-1234567890ab';

    private function integration(Website $website, array $config = []): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WIX,
            'credentials' => ['api_key' => 'wix_api_key_secret', 'site_id' => self::SITE_ID],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);
    }

    public function test_verify_proves_blog_access_and_offers_an_author_picker(): void
    {
        Http::fake([
            'www.wixapis.com/blog/v3/posts*' => Http::response(['posts' => []]),
            'www.wixapis.com/members/v1/members*' => Http::response(['members' => [
                ['id' => 'm-1', 'profile' => ['nickname' => 'Jo Writer']],
                ['id' => 'm-2', 'profile' => ['name' => ['first' => 'Sam', 'last' => 'Editor']]],
            ]]),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);
        $driver = app(WixDriver::class);

        $this->assertTrue($driver->verify($integration)->ok);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'wix_api_key_secret')
            && $r->hasHeader('wix-site-id', self::SITE_ID));

        $steps = $driver->targets($integration->refresh());
        $this->assertSame('author', $steps[0]['key']);
        $this->assertSame(['Jo Writer', 'Sam Editor'], array_column($steps[0]['options'], 'label'));

        $this->assertTrue($driver->selectTarget($integration, 'author', 'm-1')->ok);
        $this->assertSame('m-1', $integration->refresh()->config['member_id']);
        $this->assertSame([], $driver->targets($integration));
    }

    public function test_verify_without_members_access_skips_the_author_step(): void
    {
        Http::fake([
            'www.wixapis.com/blog/v3/posts*' => Http::response(['posts' => []]),
            'www.wixapis.com/members/v1/members*' => Http::response('forbidden', 403),
        ]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);
        $driver = app(WixDriver::class);

        $this->assertTrue($driver->verify($integration)->ok);
        $this->assertSame([], $driver->targets($integration->refresh()));
    }

    public function test_verify_flags_a_missing_blog_app(): void
    {
        Http::fake(['www.wixapis.com/blog/v3/posts*' => Http::response('not found', 404)]);
        [, $website] = $this->scheduledArticle();

        $result = app(WixDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Blog app', (string) $result->error);
    }

    public function test_verify_rejects_a_bad_key_as_a_hard_failure(): void
    {
        Http::fake(['www.wixapis.com/*' => Http::response('nope', 401)]);
        [, $website] = $this->scheduledArticle();

        $result = app(WixDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
    }

    public function test_malformed_site_id_never_reaches_the_network(): void
    {
        Http::fake();
        [, $website] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WIX,
            'credentials' => ['api_key' => 'k', 'site_id' => 'not-a-guid'],
            'status' => ContentIntegration::STATUS_PENDING,
        ]);

        $this->assertFalse(app(WixDriver::class)->verify($integration)->ok);
        Http::assertNothingSent();
    }

    public function test_publish_creates_a_draft_with_ricos_content_then_publishes_and_reads_the_url(): void
    {
        Http::fake([
            'www.wixapis.com/blog/v3/draft-posts/draft-1/publish' => Http::response(['postId' => 'draft-1']),
            'www.wixapis.com/blog/v3/draft-posts' => Http::response(['draftPost' => ['id' => 'draft-1']]),
            'www.wixapis.com/blog/v3/posts/draft-1*' => Http::response(['post' => ['id' => 'draft-1', 'url' => ['base' => 'https://demo.wixsite.com/blog', 'path' => '/post/a-publishable-article']]]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['member_id' => 'm-1', 'post_status' => 'publish']);

        $result = app(WixDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('draft-1', $result->externalId);
        $this->assertSame('https://demo.wixsite.com/blog/post/a-publishable-article', $result->externalUrl);

        Http::assertSent(function (Request $r) {
            if (! str_ends_with((string) $r->url(), '/blog/v3/draft-posts') || $r->method() !== 'POST') {
                return false;
            }
            $draft = $r->data()['draftPost'];
            $nodes = $draft['richContent']['nodes'];
            $types = array_column($nodes, 'type');

            return $draft['title'] === 'A Publishable Article'
                && $draft['excerpt'] === 'Description.'
                && $draft['memberId'] === 'm-1'
                && $types === ['HEADING', 'PARAGRAPH']
                && $draft['richContent']['metadata'] === ['version' => 1]
                // the <strong> run arrives as a BOLD decoration, not HTML
                && $nodes[1]['nodes'][1]['textData']['decorations'][0]['type'] === 'BOLD';
        });
    }

    public function test_update_patches_the_draft_and_republishes(): void
    {
        Http::fake([
            'www.wixapis.com/blog/v3/draft-posts/draft-1/publish' => Http::response(['postId' => 'draft-1']),
            'www.wixapis.com/blog/v3/draft-posts/draft-1' => Http::response(['draftPost' => ['id' => 'draft-1']]),
            'www.wixapis.com/blog/v3/posts/draft-1*' => Http::response(['post' => ['url' => ['base' => 'https://demo.wixsite.com/blog', 'path' => '/post/x']]]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'publish']);

        $result = app(WixDriver::class)->update($article, $integration, 'draft-1');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with((string) $r->url(), '/blog/v3/draft-posts/draft-1'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with((string) $r->url(), '/draft-posts/draft-1/publish'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with((string) $r->url(), '/blog/v3/draft-posts'));
    }

    public function test_draft_mode_skips_the_publish_call(): void
    {
        Http::fake(['www.wixapis.com/blog/v3/draft-posts' => Http::response(['draftPost' => ['id' => 'draft-1']])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft']);

        $result = app(WixDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertNull($result->externalUrl);
        Http::assertNotSent(fn (Request $r) => str_contains((string) $r->url(), '/publish'));
    }

    public function test_images_import_into_media_manager_and_land_in_ricos_and_hero(): void
    {
        Http::fake([
            'www.wixapis.com/site-media/v1/files/import' => Http::response(['file' => ['id' => 'media-abc']]),
            'www.wixapis.com/blog/v3/draft-posts' => Http::response(['draftPost' => ['id' => 'draft-1']]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $image = $this->featuredImage($article);
        // Put the featured image inline too so the Ricos resolver path shows.
        $article->forceFill(['html' => '<p>Intro.</p><img src="'.$image->url().'" alt="Featured alt">'])->save();
        $integration = $this->integration($website, ['post_status' => 'draft']);

        $result = app(WixDriver::class)->publish($article->refresh(), $integration);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => str_ends_with((string) $r->url(), '/site-media/v1/files/import')
            && $r->data()['url'] === $image->url());
        Http::assertSent(function (Request $r) {
            if (! str_ends_with((string) $r->url(), '/blog/v3/draft-posts')) {
                return false;
            }
            $draft = $r->data()['draftPost'];
            $imageNodes = array_values(array_filter($draft['richContent']['nodes'], fn ($n) => $n['type'] === 'IMAGE'));

            return ($draft['media']['wixMedia']['image']['id'] ?? null) === 'media-abc'
                && count($imageNodes) === 1
                && $imageNodes[0]['imageData']['image']['src']['id'] === 'media-abc';
        });
    }

    public function test_failed_media_import_degrades_to_alt_text_and_never_blocks(): void
    {
        Http::fake([
            'www.wixapis.com/site-media/v1/files/import' => Http::response('storage down', 500),
            'www.wixapis.com/blog/v3/draft-posts' => Http::response(['draftPost' => ['id' => 'draft-1']]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $image = $this->featuredImage($article);
        $article->forceFill(['html' => '<p>Intro.</p><img src="'.$image->url().'" alt="Featured alt">'])->save();
        $integration = $this->integration($website, ['post_status' => 'draft']);

        $result = app(WixDriver::class)->publish($article->refresh(), $integration);

        $this->assertTrue($result->ok);
        Http::assertSent(function (Request $r) {
            if (! str_ends_with((string) $r->url(), '/blog/v3/draft-posts')) {
                return false;
            }
            $draft = $r->data()['draftPost'];
            $types = array_column($draft['richContent']['nodes'], 'type');

            // No IMAGE node, no hero — the alt text survives as a paragraph.
            return ! in_array('IMAGE', $types, true)
                && ! isset($draft['media'])
                && str_contains(json_encode($draft['richContent']), 'Featured alt');
        });
    }

    public function test_member_id_validation_error_becomes_an_actionable_hard_failure(): void
    {
        Http::fake(['www.wixapis.com/blog/v3/draft-posts' => Http::response(['message' => 'draftPost.memberId is required'], 400)]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'publish']);

        $result = app(WixDriver::class)->publish($article, $integration);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
        $this->assertStringContainsString('author', (string) $result->error);
    }

    public function test_error_mapping_transient_vs_hard(): void
    {
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft']);
        $driver = app(WixDriver::class);

        Http::fake(['www.wixapis.com/*' => Http::sequence()
            ->push('oops', 500)
            ->push('slow down', 429)
            ->push(['message' => 'richContent too large'], 400)]);

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

        $this->assertCredentialEncrypted($integration->id, 'wix_api_key_secret');
    }
}
