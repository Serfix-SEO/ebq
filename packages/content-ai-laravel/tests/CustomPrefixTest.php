<?php

namespace Serfix\ContentAi\Tests;

use Serfix\ContentAi\Models\Article;

/**
 * The headline config knob: /{blogs}/your-article-link, where the host picks
 * {blogs}. Routes register at boot, so the prefix is applied in
 * defineEnvironment via the parent's $routePrefix rather than mid-test.
 */
class CustomPrefixTest extends TestCase
{
    protected string $routePrefix = 'insights';

    public function test_articles_are_served_from_the_configured_prefix(): void
    {
        $this->deliver($this->articlePayload())->assertOk();

        $this->get('/insights/best-pubg-clan-names')->assertOk()->assertSee('Best PUBG Clan Names');
        $this->get('/blog/best-pubg-clan-names')->assertNotFound();
    }

    public function test_generated_urls_use_the_configured_prefix(): void
    {
        $response = $this->deliver($this->articlePayload())->assertOk();

        $this->assertStringContainsString('/insights/best-pubg-clan-names', $response->json('url'));
        $this->assertStringContainsString('/insights/', Article::query()->first()->url());
    }

    /** Cross-article links inside the HTML follow the prefix too. */
    public function test_internal_links_are_rewritten_to_the_prefix(): void
    {
        $this->deliver($this->articlePayload([
            'article' => ['html' => '<p>See <a href="/blog/another-article">this</a>.</p>'],
        ]))->assertOk();

        $this->assertStringContainsString('href="/insights/another-article"', Article::query()->first()->html);
    }
}
