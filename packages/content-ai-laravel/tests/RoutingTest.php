<?php

namespace Serfix\ContentAi\Tests;

use Illuminate\Support\Facades\URL;
use Serfix\ContentAi\Models\Article;

class RoutingTest extends TestCase
{
    public function test_the_blog_prefix_is_configurable(): void
    {
        $this->deliver($this->articlePayload())->assertOk();

        $this->get('/blog/best-pubg-clan-names')->assertOk()->assertSee('Best PUBG Clan Names');
        $this->get('/blog')->assertOk()->assertSee('best-pubg-clan-names');
    }

    public function test_literal_routes_are_not_shadowed_by_the_slug_route(): void
    {
        $this->deliver($this->articlePayload())->assertOk();

        $this->get('/blog/feed')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $this->get('/blog/sitemap.xml')->assertOk()->assertSee('best-pubg-clan-names');
    }

    public function test_drafts_404_publicly_but_open_with_a_signed_preview_link(): void
    {
        config(['content-ai.publishing.auto_publish' => false]);
        $this->deliver($this->articlePayload())->assertOk();

        $this->get('/blog/best-pubg-clan-names')->assertNotFound();

        $signed = URL::temporarySignedRoute(
            'content-ai.show',
            now()->addMinutes(30),
            ['slug' => 'best-pubg-clan-names']
        );
        $this->get($signed)->assertOk()->assertSee('not published', false);
    }

    public function test_the_index_lists_only_live_articles(): void
    {
        $this->deliver($this->articlePayload())->assertOk();
        $this->deliver($this->articlePayload([
            'article' => ['slug' => 'a-future-post', 'h1' => 'Scheduled For Later'],
        ]))->assertOk();

        Article::query()->where('slug', 'a-future-post')
            ->update(['published_at' => now()->addWeek()]);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Best PUBG Clan Names')
            ->assertDontSee('Scheduled For Later');
    }

    /** Duplicated keyword runs produce ugly, diluted URLs — collapse them. */
    public function test_a_doubled_slug_is_collapsed(): void
    {
        $this->deliver($this->articlePayload([
            'article' => ['slug' => 'cool-pubg-clan-names-cool-pubg-clan-names-150-ideas'],
        ]))->assertOk();

        $this->assertSame(
            'cool-pubg-clan-names-150-ideas',
            Article::query()->first()->slug
        );
    }
}
