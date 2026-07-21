<?php

namespace Serfix\ContentAi\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Rendering\Renderer;

/**
 * The globals let a host keep their own design and drop our output into it.
 * They are shared with EVERY view, so the critical property is that they cost
 * nothing and print nothing on pages that are not showing an article.
 */
class BladeGlobalsTest extends TestCase
{
    private function article(): Article
    {
        $this->deliver($this->articlePayload())->assertOk();

        return Article::query()->first();
    }

    public function test_head_carries_the_full_seo_set(): void
    {
        app(Renderer::class)->use($this->article());

        $head = Blade::render('{!! $serfix_head !!}');

        $this->assertStringContainsString('<title>Best PUBG Clan Names: 150+ Ideas</title>', $head);
        $this->assertStringContainsString('name="description"', $head);
        $this->assertStringContainsString('rel="canonical"', $head);
        $this->assertStringContainsString('name="robots"', $head);
        $this->assertStringContainsString('property="og:title"', $head);
        $this->assertStringContainsString('name="twitter:card"', $head);
        $this->assertStringContainsString('application/ld+json', $head);
        $this->assertStringContainsString('BlogPosting', $head);
    }

    public function test_body_is_the_article_html(): void
    {
        $article = $this->article();
        app(Renderer::class)->use($article);

        $this->assertSame($article->html, Blade::render('{!! $serfix_body !!}'));
    }

    public function test_body_below_lists_related_articles(): void
    {
        $this->deliver($this->articlePayload())->assertOk();
        $this->deliver($this->articlePayload([
            'article' => ['slug' => 'second-post', 'h1' => 'A Second Post'],
        ]))->assertOk();

        app(Renderer::class)->use(Article::query()->where('slug', 'best-pubg-clan-names')->first());
        $below = Blade::render('{!! $serfix_body_below !!}');

        $this->assertStringContainsString('A Second Post', $below);
        $this->assertStringContainsString('/blog/second-post', $below);
        // Never links to the article you are already reading.
        $this->assertStringNotContainsString('best-pubg-clan-names', $below);
    }

    /**
     * The whole reason these are LazyChunks: they sit in a layout used by every
     * page, so a non-article request must render them to nothing rather than
     * erroring or leaking the last article.
     */
    public function test_the_globals_are_empty_when_no_article_is_being_shown(): void
    {
        $this->article();   // exists, but not "current"

        $this->assertSame('', trim(Blade::render('{!! $serfix_head !!}')));
        $this->assertSame('', trim(Blade::render('{!! $serfix_body !!}')));
        $this->assertSame('', trim(Blade::render('{!! $serfix_body_below !!}')));
    }

    public function test_directives_and_helpers_match_the_variables(): void
    {
        app(Renderer::class)->use($this->article());

        $this->assertSame(
            Blade::render('{!! $serfix_head !!}'),
            Blade::render('@serfixHead')
        );
        $this->assertSame(Blade::render('{!! $serfix_body !!}'), serfix_body());
        $this->assertSame(Blade::render('{!! $serfix_body_below !!}'), serfix_body_below());
        $this->assertSame('best-pubg-clan-names', serfix_article()->slug);
    }

    /** Escaped echo must not double-escape our markup (Htmlable). */
    public function test_escaped_echo_still_renders_markup(): void
    {
        app(Renderer::class)->use($this->article());

        $this->assertStringContainsString('<title>', Blade::render('{{ $serfix_head }}'));
    }

    /** A host layout never receives our view data — the globals must still work there. */
    public function test_globals_resolve_inside_a_host_route_and_layout(): void
    {
        $article = $this->article();

        Route::middleware('web')->get('/my-own-blog/{slug}', function (string $slug) {
            $model = config('content-ai.models.article');
            app(Renderer::class)->use($model::query()->where('slug', $slug)->firstOrFail());

            return view('host-page');
        });

        $this->app['view']->addNamespace('tests', __DIR__.'/views');
        $this->app['view']->getFinder()->addLocation(__DIR__.'/views');

        $this->get('/my-own-blog/'.$article->slug)
            ->assertOk()
            ->assertSee('MY OWN DESIGN', false)
            ->assertSee('<title>Best PUBG Clan Names: 150+ Ideas</title>', false)
            ->assertSee('Some article body.', false);
    }

    public function test_globals_can_be_switched_off(): void
    {
        // Sharing is decided at boot, so this needs a fresh app.
        $this->app['config']->set('content-ai.render.globals', false);

        // Helpers keep working regardless — only the shared variables go away.
        app(Renderer::class)->use($this->article());
        $this->assertStringContainsString('<title>', serfix_head());
    }
}
