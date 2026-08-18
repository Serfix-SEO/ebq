<?php

namespace Tests\Feature;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ArticleExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Copy/download an article for a site we cannot publish into automatically
 * (Hostinger Horizon and other builders with no content API).
 */
class ArticleExportTest extends TestCase
{
    use RefreshDatabase;

    private function article(User $user, array $overrides = []): ContentTopic
    {
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.org']);
        $plan = ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => 'active',
        ]);
        $topic = ContentTopic::query()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'title' => 'How to clean a rug',
            'target_keyword' => 'clean a rug',
            'status' => 'ready',
        ]);
        ContentArticle::query()->create(array_merge([
            'topic_id' => $topic->id,
            'version' => 1,
            'is_current' => true,
            'h1' => 'How to clean a rug',
            'html' => '<h2>Start here</h2><p>Use <strong>warm</strong> water and a <a href="https://example.org/soap">mild soap</a>.</p><ul><li>Blot, do not rub</li></ul>',
            'markdown' => 'STALE — copied from an older version',
        ], $overrides));

        return $topic;
    }

    public function test_html_export_carries_the_h1_and_the_body(): void
    {
        $user = User::factory()->create();
        $topic = $this->article($user);

        $res = $this->actingAs($user)->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'html']));

        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringContainsString('<h1>How to clean a rug</h1>', $body);
        $this->assertStringContainsString('<strong>warm</strong>', $body);
        // No page chrome: a full document pasted into a CMS editor is escaped.
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('<!DOCTYPE', $body);
    }

    public function test_markdown_is_rendered_from_the_live_html_not_the_stale_column(): void
    {
        // The trap: ArticleReview::save() copies `markdown` verbatim from the
        // previous version, so the column still holds pre-edit text.
        $user = User::factory()->create();
        $topic = $this->article($user);

        $res = $this->actingAs($user)->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'md']));

        $res->assertOk();
        $body = $res->getContent();

        $this->assertStringNotContainsString('STALE', $body);
        $this->assertStringContainsString('# How to clean a rug', $body);
        $this->assertStringContainsString('## Start here', $body);
        $this->assertStringContainsString('**warm**', $body);
        $this->assertStringContainsString('[mild soap](https://example.org/soap)', $body);
        $this->assertStringContainsString('- Blot, do not rub', $body);
    }

    public function test_download_flag_switches_to_an_attachment(): void
    {
        $user = User::factory()->create();
        $topic = $this->article($user, ['slug' => 'clean-a-rug']);

        $inline = $this->actingAs($user)->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'md']));
        $inline->assertHeader('Content-Disposition', 'inline; filename="clean-a-rug.md"');

        $download = $this->actingAs($user)->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'md']).'?download=1');
        $download->assertHeader('Content-Disposition', 'attachment; filename="clean-a-rug.md"');
    }

    public function test_another_users_article_is_not_reachable(): void
    {
        $owner = User::factory()->create();
        $topic = $this->article($owner);

        // The stranger gets their OWN website + plan, so they clear the
        // `content.access` middleware and actually reach the controller —
        // otherwise this asserts the middleware redirect, not tenant isolation.
        $stranger = User::factory()->create();
        $this->article($stranger);

        $this->actingAs($stranger)
            ->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'html']))
            ->assertNotFound();
    }

    public function test_guests_cannot_export(): void
    {
        $owner = User::factory()->create();
        $topic = $this->article($owner);

        $this->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'html']))
            ->assertRedirect(route('login'));
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $user = User::factory()->create();
        $topic = $this->article($user);

        $this->actingAs($user)
            ->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'pdf']))
            ->assertNotFound();
    }

    public function test_export_follows_the_current_version(): void
    {
        $user = User::factory()->create();
        $topic = $this->article($user);

        // A newer edit supersedes v1 (storeVersion's is_current dance).
        ContentArticle::query()->where('topic_id', $topic->id)->update(['is_current' => false]);
        ContentArticle::query()->create([
            'topic_id' => $topic->id,
            'version' => 2,
            'is_current' => true,
            'h1' => 'How to clean a rug',
            'html' => '<p>The client rewrote this bit.</p>',
        ]);

        $body = $this->actingAs($user)
            ->get(route('content.article.export', ['topic' => $topic->id, 'format' => 'html']))
            ->getContent();

        $this->assertStringContainsString('The client rewrote this bit.', $body);
        $this->assertStringNotContainsString('mild soap', $body);
    }

    public function test_exporter_falls_back_to_a_slug_when_none_is_set(): void
    {
        $user = User::factory()->create();
        $topic = $this->article($user, ['slug' => null]);
        $article = ContentArticle::query()->where('topic_id', $topic->id)->first();

        $out = app(ArticleExporter::class)->export($article, ArticleExporter::FORMAT_HTML);

        $this->assertSame('how-to-clean-a-rug.html', $out['filename']);
    }

    public function test_the_export_bar_sits_above_the_article_not_buried_below_it(): void
    {
        // Owner feedback 2026-08-16: at the bottom of the sidebar it was missed.
        // Compare positions in the RENDERED markup with <script> and the
        // Livewire snapshot stripped — both embed the article JSON and would
        // make a naive strpos comparison lie (it did, while building this).
        $user = User::factory()->create();
        $topic = $this->article($user);

        $html = $this->actingAs($user)->get(route('content.review', ['topic' => $topic->id]))->getContent();
        $clean = (string) preg_replace('#<script.*?</script>#s', '', $html);
        $clean = (string) preg_replace('#wire:snapshot="[^"]*"#s', '', $clean);

        $header = strpos($clean, 'truncate text-xl font-bold');
        $export = strpos($clean, 'Take it elsewhere');
        $body = strpos($clean, 'Blot, do not rub');   // the article content itself

        $this->assertNotFalse($export, 'the export bar must render on the review page');
        $this->assertNotFalse($body, 'the article body must render');
        $this->assertLessThan($export, $header, 'export sits under the page header');
        $this->assertLessThan($body, $export, 'export sits ABOVE the article body');
    }
}
