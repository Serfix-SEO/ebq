<?php

namespace Tests\Feature\Content;

use App\Jobs\PublishContentArticleJob;
use App\Models\ContentIntegration;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\Publishing\PublishDriverFactory;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Where the answer-engine @graph actually lands, destination by destination.
 *
 * The per-driver question phase 2 left open: platforms differ in whether the
 * body they accept is raw HTML at all, so "append a <script>" is right for some
 * and produces visible garbage on others. These tests pin the answer for each,
 * so a future driver change cannot quietly regress it.
 *
 * Deliberately NOT asserted here (it cannot be, from a fake): whether each
 * platform's own sanitiser keeps a <script> inside post content. That needs a
 * real account per platform and is tracked in infra/content-autopilot/README.md.
 */
class AeoSchemaDeliveryTest extends PublishDriverTestCase
{
    use RefreshDatabase;

    private const ARTICLE_HTML = '<h2>What is it</h2><p>A direct answer in the opening line.</p>';

    /** Everything answers 200 with an empty body: we assert on what was SENT. */
    private function publish(Website $website, string $platform, array $credentials, array $config = []): void
    {
        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => $platform,
            'credentials' => $credentials,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => $config,
        ]);

        $topic = ContentTopic::query()->where('website_id', $website->id)->firstOrFail();

        (new PublishContentArticleJob($topic->id))->handle(
            app(PublishDriverFactory::class),
            app(SafeHttpGuard::class),
        );
    }

    /** The one request whose body we care about, decoded. */
    private function sentBodies(): array
    {
        $bodies = [];
        foreach (Http::recorded() as [$request]) {
            $bodies[] = (string) $request->body();
        }

        return $bodies;
    }

    private function assertSomeBodyContains(string $needle): void
    {
        $this->assertTrue(
            collect($this->sentBodies())->contains(fn (string $b): bool => str_contains($b, $needle)),
            'No outgoing request body contained: '.$needle
        );
    }

    private function assertNoBodyContains(string $needle): void
    {
        $this->assertFalse(
            collect($this->sentBodies())->contains(fn (string $b): bool => str_contains($b, $needle)),
            'An outgoing request body contained what it must not: '.$needle
        );
    }

    public function test_the_article_carries_a_stored_graph_before_any_driver_runs(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [, $website, , , $article] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_WEBHOOK, ['endpoint_url' => 'https://example.com/hook', 'secret' => 's']);

        $graph = $article->refresh()->schema_json;
        $this->assertNotEmpty($graph, 'publish must persist the audited graph on the article');
        $this->assertSame('https://schema.org', $graph['@context'] ?? null);
        $types = array_column($graph['@graph'] ?? [], '@type');
        $this->assertContains('BlogPosting', $types);
        $this->assertContains('Organization', $types);
    }

    /* ─── Raw-HTML destinations: the graph rides inside the body ─────── */

    public function test_shopify_gets_the_graph_inline_in_the_article_body(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_SHOPIFY, [
            'store_domain' => 'demo-store.myshopify.com', 'access_token' => 'shpat_t',
        ], ['blog_id' => 'gid://shopify/Blog/11', 'blog_handle' => 'news', 'shop_url' => 'https://demo.example.com', 'post_status' => 'publish']);

        $this->assertSomeBodyContains('ld+json');
        $this->assertSomeBodyContains('BlogPosting');
    }

    public function test_webflow_gets_the_graph_inline_in_its_body_field(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_WEBFLOW, ['api_token' => 'wf_t'], [
            'site_id' => 'site-1', 'site_domain' => 'blog.demo.com', 'collection_id' => 'coll-1',
            'collection_slug' => 'blog-posts', 'body_field' => 'post-body', 'post_status' => 'publish',
        ]);

        $this->assertSomeBodyContains('ld+json');
    }

    public function test_hubspot_gets_the_graph_inline_in_its_post_body(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_HUBSPOT, ['token' => 'pat-t'], [
            'content_group_id' => '777', 'blog_url' => 'https://blog.demo.com', 'post_status' => 'publish',
        ]);

        $this->assertSomeBodyContains('ld+json');
    }

    /* ─── Block-model destinations: never inline, never as text ──────── */

    public function test_wix_never_receives_the_graph_as_article_text(): void
    {
        // Wix takes Ricos nodes, not HTML. Appending a <script> there does not
        // produce markup — HtmlBlockParser would have turned it into a
        // paragraph of raw JSON at the foot of the post.
        Http::fake(['*' => Http::response(['draftPost' => ['id' => 'draft-1']], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_WIX, [
            'api_key' => 'k', 'site_id' => '12345678-abcd-4ef0-9876-1234567890ab',
        ], ['member_id' => 'm-1', 'post_status' => 'publish']);

        $this->assertNoBodyContains('ld+json');
        $this->assertNoBodyContains('schema.org');
        $this->assertNoBodyContains('BlogPosting');
    }

    public function test_sanity_gets_the_graph_as_a_document_field_not_as_body_text(): void
    {
        Http::fake(['*' => Http::response(['results' => [['id' => 'serfix-1']]], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_SANITY, [
            'project_id' => 'abc123', 'token' => 'sk_t',
        ], ['dataset' => 'production', 'doc_type' => 'post', 'post_status' => 'publish']);

        $this->assertNoBodyContains('ld+json');
        $this->assertSomeBodyContains('serfixSchema');

        Http::assertSent(function (Request $r): bool {
            $doc = $r->data()['mutations'][0]['createOrReplace'] ?? null;
            if ($doc === null) {
                return false;
            }

            // The graph is a field on the document, and the Portable Text body
            // is free of it.
            return str_contains((string) ($doc['serfixSchema'] ?? ''), 'BlogPosting')
                && ! str_contains(json_encode($doc['body'] ?? []), 'schema.org');
        });
    }

    /* ─── Receivers that already get the graph as its own field ──────── */

    public function test_webhook_receives_schema_json_as_a_field_and_not_inline(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_WEBHOOK, ['endpoint_url' => 'https://example.com/hook', 'secret' => 's']);

        Http::assertSent(function (Request $r): bool {
            $payload = $r->data();
            $article = $payload['article'] ?? [];

            return ! empty($article['schema_json'])
                && ! str_contains((string) ($article['html'] ?? ''), 'ld+json');
        });
    }

    public function test_medusa_inherits_the_webhook_shape_without_a_second_copy(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_MEDUSA, [
            'base_url' => 'https://store.example.com', 'secret' => 's',
        ]);

        Http::assertSent(function (Request $r): bool {
            $article = $r->data()['article'] ?? [];

            return ! empty($article['schema_json'])
                && ! str_contains((string) ($article['html'] ?? ''), 'ld+json');
        });
    }

    public function test_wordpress_is_left_to_the_plugins_own_graph(): void
    {
        // The Serfix plugin builds a full @graph on the page from _ebq_* meta
        // (class-ebq-schema-output.php). A second Article node from us would be
        // a duplicate, so the html must stay clean here too.
        Http::fake(['*' => Http::response(['id' => 321, 'link' => 'https://client-blog.com/a/', 'status' => 'publish'], 201)]);
        [, $website] = $this->scheduledArticle(['html' => self::ARTICLE_HTML]);

        $this->publish($website, ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD, [
            'site_url' => 'https://client-blog.com', 'username' => 'admin', 'app_password' => 'abcd efgh',
        ]);

        $this->assertNoBodyContains('ld+json');
    }
}
