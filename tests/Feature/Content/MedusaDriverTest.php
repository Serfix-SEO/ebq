<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Services\Content\Publishing\MedusaDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Medusa destination (2026-09-03): a guided receiver, not a vendor API —
 * Medusa v2 has no blog, so our paste-in kit (resources/snippets/medusa/)
 * installs the storage + a signed intake route at /serfix/articles, and this
 * driver speaks the exact WebhookDriver contract to it.
 */
class MedusaDriverTest extends PublishDriverTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function integration(array $credentials = []): ContentIntegration
    {
        [, $website] = $this->scheduledArticle();

        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_MEDUSA,
            'credentials' => $credentials ?: [
                'base_url' => 'https://api.client-store.com',
                'secret' => str_repeat('s', 40),
            ],
            'config' => ['post_status' => 'publish'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);
    }

    public function test_verify_posts_a_signed_test_to_the_kit_route(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response(['ok' => true], 200)]);
        $integration = $this->integration();

        $result = app(MedusaDriver::class)->verify($integration);

        $this->assertTrue($result->ok);
        Http::assertSent(function (Request $r) {
            $expected = 'sha256='.hash_hmac('sha256', $r->body(), str_repeat('s', 40));

            return $r->url() === 'https://api.client-store.com/serfix/articles'
                && $r->header('X-Serfix-Signature')[0] === $expected
                && str_contains($r->body(), '"event":"verify"');
        });
    }

    public function test_verify_404_explains_the_kit_is_not_installed(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response('not found', 404)]);

        $result = app(MedusaDriver::class)->verify($this->integration());

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient, 'missing receiver is not retryable');
        $this->assertStringContainsString('receiver', (string) $result->error);
    }

    public function test_verify_401_explains_the_secret_mismatch(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response('nope', 401)]);

        $result = app(MedusaDriver::class)->verify($this->integration());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('SERFIX_SECRET', (string) $result->error);
    }

    public function test_publish_sends_the_full_signed_article_and_stores_the_returned_url(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response([
            'ok' => true, 'id' => 'topic-1', 'url' => 'https://client-store.com/blog/how-to-test',
        ], 200)]);
        [, , , $topic, $article] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $topic->website_id,
            'platform' => ContentIntegration::PLATFORM_MEDUSA,
            'credentials' => ['base_url' => 'https://api.client-store.com/', 'secret' => str_repeat('k', 40)],
            'config' => ['post_status' => 'publish'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        $result = app(MedusaDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('https://client-store.com/blog/how-to-test', $result->externalUrl);
        Http::assertSent(function (Request $r) use ($article) {
            $data = $r->data();
            $expected = 'sha256='.hash_hmac('sha256', $r->body(), str_repeat('k', 40));

            // trailing slash on base_url must not double up
            return $r->url() === 'https://api.client-store.com/serfix/articles'
                && $r->header('X-Serfix-Signature')[0] === $expected
                && $data['event'] === 'article.published'
                && $data['status'] === 'published'
                && $data['article']['html'] === (string) $article->html
                && $data['article']['slug'] === (string) $article->slug;
        });
    }

    public function test_update_sends_article_updated_with_the_same_external_id(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response(['ok' => true], 200)]);
        [, , , $topic, $article] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $topic->website_id,
            'platform' => ContentIntegration::PLATFORM_MEDUSA,
            'credentials' => ['base_url' => 'https://api.client-store.com', 'secret' => str_repeat('k', 40)],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        $result = app(MedusaDriver::class)->update($article, $integration, 'ext-123');

        $this->assertTrue($result->ok);
        $this->assertSame('ext-123', $result->externalId);
        Http::assertSent(fn (Request $r) => $r->data()['event'] === 'article.updated'
            && $r->data()['external_id'] === 'ext-123');
    }

    public function test_draft_mode_sends_status_draft(): void
    {
        Http::fake(['api.client-store.com/*' => Http::response(['ok' => true], 200)]);
        [, , , $topic, $article] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $topic->website_id,
            'platform' => ContentIntegration::PLATFORM_MEDUSA,
            'credentials' => ['base_url' => 'https://api.client-store.com', 'secret' => str_repeat('k', 40)],
            'config' => ['post_status' => 'draft'],
            'status' => ContentIntegration::STATUS_CONNECTED,
        ]);

        app(MedusaDriver::class)->publish($article, $integration);

        Http::assertSent(fn (Request $r) => $r->data()['status'] === 'draft');
    }

    public function test_error_transience_classification(): void
    {
        Http::fake(['api.client-store.com/*' => Http::sequence()
            ->push('boom', 500)->push('slow down', 429)->push('bad', 400)]);
        [, , , , $article] = $this->scheduledArticle();
        $integration = $this->integration();

        $driver = app(MedusaDriver::class);
        $this->assertTrue($driver->publish($article, $integration)->transient, '5xx retries');
        $this->assertTrue($driver->publish($article, $integration)->transient, '429 retries');
        $this->assertFalse($driver->publish($article, $integration)->transient, '4xx is hard');
    }

    public function test_plain_http_base_url_is_refused(): void
    {
        Http::fake();
        $integration = $this->integration(['base_url' => 'http://api.client-store.com', 'secret' => str_repeat('s', 40)]);

        $result = app(MedusaDriver::class)->verify($integration);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('https://', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $integration = $this->integration();

        $this->assertCredentialEncrypted($integration->id, str_repeat('s', 40));
    }

    public function test_snippet_kit_files_exist_and_carry_the_contract(): void
    {
        $files = [
            'module-post.ts', 'module-service.ts', 'module-index.ts', 'middlewares.ts',
            'route-articles.ts', 'route-store-list.ts', 'route-store-single.ts',
            'storefront-list.tsx', 'storefront-single.tsx',
        ];
        foreach ($files as $file) {
            $path = resource_path('snippets/medusa/'.$file);
            $this->assertFileExists($path);
            $this->assertGreaterThan(100, strlen((string) file_get_contents($path)), $file.' looks empty');
        }
        $route = (string) file_get_contents(resource_path('snippets/medusa/route-articles.ts'));
        foreach (['x-serfix-signature', 'SERFIX_SECRET', 'timingSafeEqual', 'external_id', 'SERFIX_STOREFRONT_URL'] as $needle) {
            $this->assertStringContainsString($needle, $route);
        }
    }
}
