<?php

namespace Serfix\ContentAi\Tests;

use Illuminate\Support\Facades\Event;
use Serfix\ContentAi\Events\ArticlePublished;
use Serfix\ContentAi\Events\ArticleUpdated;
use Serfix\ContentAi\Models\Article;

class WebhookTest extends TestCase
{
    public function test_a_signed_delivery_is_stored_and_answers_with_id_and_url(): void
    {
        $response = $this->deliver($this->articlePayload());

        $response->assertOk()->assertJsonStructure(['ok', 'id', 'url', 'status']);

        $article = Article::query()->first();
        $this->assertNotNull($article);
        $this->assertSame('best-pubg-clan-names', $article->slug);
        $this->assertSame('Best PUBG Clan Names', $article->title);
        $this->assertSame(Article::STATUS_PUBLISHED, $article->status);
        // The publisher stores this url as content_publications.external_url —
        // without it there is no record of where the article actually went.
        $response->assertJsonPath('url', $article->url());
    }

    public function test_an_unsigned_or_wrongly_signed_delivery_is_rejected(): void
    {
        $this->postJson('/'.config('content-ai.webhook.path'), $this->articlePayload())
            ->assertStatus(401);

        $this->deliver($this->articlePayload(), 'the-wrong-secret')
            ->assertStatus(401);

        $this->assertSame(0, Article::query()->count());
    }

    /** A tampered body must fail even though the signature header is well-formed. */
    public function test_body_tampering_invalidates_the_signature(): void
    {
        $payload = $this->articlePayload();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $payload['article']['html'] = '<p>Injected content.</p>';

        $this->call(
            'POST', '/'.config('content-ai.webhook.path'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SERFIX_SIGNATURE' => $signature],
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        )->assertStatus(401);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_a_stale_delivery_is_refused_as_a_replay(): void
    {
        config(['content-ai.webhook.tolerance' => 300]);

        $this->deliver($this->articlePayload(['sent_at' => now()->subHour()->toIso8601String()]))
            ->assertStatus(401);
    }

    /**
     * Serfix retries on any non-2xx. If a lost 200 caused a second delivery to
     * create a second post, every flaky network would duplicate content — the
     * single worst failure mode for an SEO product.
     */
    public function test_redelivery_of_the_same_article_never_duplicates(): void
    {
        $this->deliver($this->articlePayload())->assertOk();
        $this->deliver($this->articlePayload())->assertOk();

        $this->assertSame(1, Article::query()->count());
    }

    public function test_an_update_matches_on_external_id_and_replaces_the_body(): void
    {
        Event::fake([ArticlePublished::class, ArticleUpdated::class]);

        $first = $this->deliver($this->articlePayload())->assertOk();
        $id = $first->json('id');

        $this->deliver($this->articlePayload([
            'event' => 'article.updated',
            'external_id' => $id,
            'article' => ['html' => '<p>Revised body.</p>', 'slug' => 'a-brand-new-slug'],
        ]))->assertOk();

        $this->assertSame(1, Article::query()->count());
        $article = Article::query()->first();
        $this->assertStringContainsString('Revised body.', $article->html);
        $this->assertSame('a-brand-new-slug', $article->slug);

        Event::assertDispatched(ArticlePublished::class);
        Event::assertDispatched(ArticleUpdated::class);
    }

    public function test_verify_event_answers_without_creating_anything(): void
    {
        $this->deliver(['event' => 'verify', 'sent_at' => now()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_unpublish_hides_the_article_without_deleting_it(): void
    {
        $id = $this->deliver($this->articlePayload())->json('id');

        $this->deliver([
            'event' => 'article.unpublished',
            'external_id' => $id,
            'sent_at' => now()->toIso8601String(),
        ])->assertOk();

        $article = Article::query()->first();
        $this->assertSame(Article::STATUS_UNPUBLISHED, $article->status);
        $this->assertFalse($article->isPublished());
    }

    public function test_draft_mode_parks_articles_unpublished(): void
    {
        config(['content-ai.publishing.auto_publish' => false]);

        $this->deliver($this->articlePayload())->assertOk();

        $this->assertSame(Article::STATUS_DRAFT, Article::query()->first()->status);
    }

    /** Serfix must retry a failed import, so a broken payload cannot answer 2xx. */
    public function test_a_malformed_payload_is_rejected_so_the_publisher_retries(): void
    {
        $this->deliver(['event' => 'article.published', 'sent_at' => now()->toIso8601String()])
            ->assertStatus(422);
    }
}
