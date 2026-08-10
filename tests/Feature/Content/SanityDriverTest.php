<?php

namespace Tests\Feature\Content;

use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Publishing\SanityDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SanityDriverTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private const BASE = 'abc123.api.sanity.io/v'.SanityDriver::API_VERSION;

    private function integration(Website $website, array $config = []): ContentIntegration
    {
        return ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_SANITY,
            'credentials' => ['project_id' => 'abc123', 'token' => 'sk_sanity_secret'],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);
    }

    private function connectedConfig(): array
    {
        return ['dataset' => 'production', 'doc_type' => 'post', 'post_status' => 'publish'];
    }

    public function test_verify_lists_datasets_and_surfaces_a_target_step(): void
    {
        Http::fake([self::BASE.'/datasets' => Http::response([
            ['name' => 'production'], ['name' => 'staging'],
        ])]);
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);
        $driver = app(SanityDriver::class);

        $this->assertTrue($driver->verify($integration)->ok);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_sanity_secret'));

        $steps = $driver->targets($integration->refresh());
        $this->assertSame('dataset', $steps[0]['key']);
        $this->assertSame(['production', 'staging'], array_column($steps[0]['options'], 'id'));

        $this->assertTrue($driver->selectTarget($integration, 'dataset', 'production')->ok);
        $this->assertSame('production', $integration->refresh()->config['dataset']);
        $this->assertSame([], $driver->targets($integration));
    }

    public function test_verify_rejects_a_bad_token_as_a_hard_failure(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'unauthorized'], 401)]);
        [, $website] = $this->scheduledArticle();

        $result = app(SanityDriver::class)->verify($this->integration($website));

        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
    }

    public function test_malformed_project_id_never_reaches_the_network(): void
    {
        Http::fake();
        [, $website] = $this->scheduledArticle();
        $integration = ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_SANITY,
            'credentials' => ['project_id' => 'evil.attacker.com/x', 'token' => 't'],
            'status' => ContentIntegration::STATUS_PENDING,
        ]);

        $result = app(SanityDriver::class)->verify($integration);

        $this->assertFalse($result->ok);
        Http::assertNothingSent();
    }

    public function test_publish_mutates_with_a_deterministic_id_and_portable_text_body(): void
    {
        Http::fake([self::BASE.'/data/mutate/production*' => Http::response([
            'transactionId' => 'tx1',
            'results' => [['id' => 'serfix-art1', 'operation' => 'create']],
        ])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(SanityDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertSame('serfix-art1', $result->externalId);
        $this->assertNull($result->externalUrl); // headless, no url_pattern set

        $expectedId = 'serfix-'.strtolower((string) $article->id);
        Http::assertSent(function (Request $r) use ($expectedId) {
            $doc = $r->data()['mutations'][0]['createOrReplace'] ?? null;
            if ($doc === null) {
                return false;
            }
            $texts = array_map(
                fn ($block) => $block['children'][0]['text'] ?? null,
                array_values(array_filter($doc['body'], fn ($b) => $b['_type'] === 'block')),
            );

            return $doc['_id'] === $expectedId
                && $doc['_type'] === 'post'
                && $doc['title'] === 'A Publishable Article'
                && $doc['slug'] === ['_type' => 'slug', 'current' => 'a-publishable-article']
                && $doc['excerpt'] === 'Description.'
                && in_array('Body', $texts, true); // h2 from the article HTML
        });
    }

    public function test_update_reuses_the_stored_id(): void
    {
        Http::fake([self::BASE.'/data/mutate/production*' => Http::response([
            'transactionId' => 'tx2', 'results' => [['id' => 'serfix-old', 'operation' => 'update']],
        ])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(SanityDriver::class)->update($article, $integration, 'serfix-old');

        $this->assertTrue($result->ok);
        $this->assertSame('serfix-old', $result->externalId);
        Http::assertSent(fn (Request $r) => ($r->data()['mutations'][0]['createOrReplace']['_id'] ?? null) === 'serfix-old');
    }

    public function test_draft_mode_uses_the_drafts_prefix(): void
    {
        Http::fake([self::BASE.'/data/mutate/production*' => Http::response(['transactionId' => 'tx3', 'results' => []])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['post_status' => 'draft', 'url_pattern' => 'https://demo.com/blog/{slug}'] + $this->connectedConfig());

        $result = app(SanityDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        $this->assertNull($result->externalUrl); // drafts never get a public URL
        Http::assertSent(fn (Request $r) => str_starts_with(
            (string) ($r->data()['mutations'][0]['createOrReplace']['_id'] ?? ''),
            'drafts.serfix-',
        ));
    }

    public function test_url_pattern_substitution_restores_a_public_url(): void
    {
        Http::fake([self::BASE.'/data/mutate/production*' => Http::response([
            'transactionId' => 'tx4', 'results' => [['id' => 'serfix-x']],
        ])]);
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, ['url_pattern' => 'https://demo.com/blog/{slug}'] + $this->connectedConfig());

        $result = app(SanityDriver::class)->publish($article, $integration);

        $this->assertSame('https://demo.com/blog/a-publishable-article', $result->externalUrl);
    }

    public function test_images_upload_as_assets_and_reference_into_the_document(): void
    {
        Http::fake([
            self::BASE.'/assets/images/production*' => Http::response(['document' => ['_id' => 'image-deadbeef-1024x768-png']]),
            self::BASE.'/data/mutate/production*' => Http::response(['transactionId' => 'tx5', 'results' => [['id' => 'serfix-x']]]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $this->featuredImage($article);
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(SanityDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => str_contains((string) $r->url(), '/assets/images/production')
            && str_contains((string) $r->url(), 'filename=featured.png'));
        Http::assertSent(function (Request $r) {
            $doc = $r->data()['mutations'][0]['createOrReplace'] ?? null;

            return $doc !== null
                && ($doc['mainImage']['asset']['_ref'] ?? null) === 'image-deadbeef-1024x768-png'
                && ($doc['mainImage']['alt'] ?? null) === 'Featured alt';
        });
    }

    public function test_failed_asset_upload_never_blocks_the_publish(): void
    {
        Http::fake([
            self::BASE.'/assets/images/production*' => Http::response('storage down', 500),
            self::BASE.'/data/mutate/production*' => Http::response(['transactionId' => 'tx6', 'results' => [['id' => 'serfix-x']]]),
        ]);
        [, $website, , , $article] = $this->scheduledArticle();
        $this->featuredImage($article);
        $integration = $this->integration($website, $this->connectedConfig());

        $result = app(SanityDriver::class)->publish($article, $integration);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $r) => isset($r->data()['mutations'][0]['createOrReplace'])
            && ! isset($r->data()['mutations'][0]['createOrReplace']['mainImage']));
    }

    public function test_error_mapping_transient_vs_hard(): void
    {
        [, $website, , , $article] = $this->scheduledArticle();
        $integration = $this->integration($website, $this->connectedConfig());
        $driver = app(SanityDriver::class);

        Http::fake([self::BASE.'/*' => Http::sequence()
            ->push('oops', 500)
            ->push('slow down', 429)
            ->push(['error' => ['description' => 'Document type mismatch']], 409)]);

        $this->assertTrue($driver->publish($article, $integration)->transient);
        $this->assertTrue($driver->publish($article, $integration)->transient);

        $hard = $driver->publish($article, $integration);
        $this->assertFalse($hard->ok);
        $this->assertFalse($hard->transient);
        $this->assertStringContainsString('Document type mismatch', (string) $hard->error);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [, $website] = $this->scheduledArticle();
        $integration = $this->integration($website);

        $this->assertCredentialEncrypted($integration->id, 'sk_sanity_secret');
    }
}
