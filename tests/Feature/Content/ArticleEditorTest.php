<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentLlmSpendMeter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function reviewable(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'pubg name generator',
            'secondary_keywords' => ['stylish names'],
            'status' => ContentTopic::STATUS_READY,
        ]);
        $article = ContentArticle::storeVersion($topic, [
            'h1' => 'PUBG Name Generator Guide',
            'meta_title' => 'PUBG Name Generator: The Ultimate Guide to Great Names',
            'meta_description' => str_repeat('Learn about pubg name generator tools. ', 4),
            'slug' => 'pubg-name-generator-guide',
            'html' => '<p>Use a pubg name generator to start.</p><h2 id="a">How the pubg name generator works</h2><p>'.str_repeat('The pubg name generator helps players choose stylish names quickly. ', 30).'</p>',
            'word_count' => 400,
            'seo_score' => 80,
            'seo_issues' => [],
        ]);

        return [$user, $website, $plan, $topic, $article];
    }

    /**
     * The primary edit button lives in the PAGE HEADER, beside the title.
     * It used to sit only in the side column, which renders after the whole
     * article on a phone; moving it just above the article body was still
     * missed on desktop, where two "Connect…" banners, the export card and
     * the feedback row push it down among louder orange buttons. Clients
     * asked support where it was twice (owner 2026-09-26).
     */
    public function test_edit_is_offered_above_the_article(): void
    {
        [$user, , , $topic] = $this->reviewable();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])->html();

        $editAt = strpos($html, 'wire:click="startEditing"');
        $titleAt = strpos($html, (string) $topic->title);
        $exportAt = strpos($html, 'Take it elsewhere');
        $articleAt = strpos($html, 'class="ca-preview');

        $this->assertNotFalse($editAt);
        $this->assertNotFalse($articleAt);
        $this->assertLessThan($articleAt, $editAt, 'edit must come before the article body');
        // ...and before everything that used to bury it: the export card, the
        // feedback row and the SEO kit all sit between the header and the
        // article on desktop.
        $this->assertLessThan($exportAt, $editAt, 'edit must sit in the header, above the export card');
        $this->assertGreaterThan($titleAt, $editAt, 'edit belongs beside the title, not above it');
        $this->assertStringContainsString('Edit article', $html);
    }

    // ── Listen (browser text-to-speech) ────────────────────────────────

    public function test_listen_is_offered_in_the_header_for_a_finished_article(): void
    {
        [$user, , , $topic] = $this->reviewable();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])->html();

        $this->assertStringContainsString('x-data="articleSpeech"', $html);
        $this->assertStringContainsString('Listen', $html);
        // Before the article, like Edit — the header is what people see first.
        $this->assertLessThan(strpos($html, 'class="ca-preview'), strpos($html, 'articleSpeech'));
    }

    /**
     * The controls you need WHILE it reads must stay reachable. Reading
     * auto-scrolls the page to follow along, which carried the header
     * controls off screen and left no way to pause (owner 2026-09-26).
     */
    public function test_the_playing_controls_are_pinned_to_the_viewport(): void
    {
        [$user, , , $topic] = $this->reviewable();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])->html();

        $barAt = strpos($html, 'fixed inset-x-0 bottom-0');
        $this->assertNotFalse($barAt, 'the playing controls need a viewport-pinned bar');

        // Pause / Stop / speed live in that bar, not back up in the header.
        foreach (['Pause', 'Resume', 'Stop reading', 'Reading speed'] as $control) {
            $this->assertGreaterThan($barAt, strpos($html, $control), "{$control} must sit inside the pinned bar");
        }
        // ...and the header keeps only the idle button.
        $this->assertLessThan($barAt, strpos($html, '>'.__('Listen')));
    }

    /** The voice follows the plan's language, not the browser's. */
    public function test_listen_carries_the_plans_language(): void
    {
        [$user, , $plan, $topic] = $this->reviewable();
        $plan->forceFill(['language' => 'Arabic'])->save();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])->html();

        $this->assertStringContainsString('data-lang="ar"', $html);
    }

    /**
     * The language column holds a code from the wizard but a full name from
     * the settings select, so both have to normalise (the same split
     * ContentKeywordInsights handles before calling the keyword server).
     */
    public function test_the_speech_language_normalises_codes_and_names(): void
    {
        foreach ([
            'en' => 'en', 'English' => 'en', 'ARABIC' => 'ar', 'ar' => 'ar',
            'fr-CA' => 'fr-ca', 'Klingon' => 'en', '' => 'en', null => 'en',
        ] as $stored => $expected) {
            $this->assertSame($expected, ArticleReview::speechLanguage((string) $stored), "stored: {$stored}");
        }
    }

    public function test_listen_is_absent_while_editing(): void
    {
        [$user, , , $topic] = $this->reviewable();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')->html();

        $this->assertStringNotContainsString('x-data="articleSpeech"', $html);
    }

    /** Nothing to read aloud while the article is still being written. */
    public function test_listen_is_absent_while_the_article_is_generating(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $topic->forceFill(['status' => \App\Models\ContentTopic::STATUS_WRITING])->save();

        $html = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])->html();

        $this->assertStringNotContainsString('x-data="articleSpeech"', $html);
    }

    /** In edit mode the header button is gone — the editor has its own toolbar. */
    public function test_the_header_edit_button_is_hidden_while_already_editing(): void
    {
        [$user, , , $topic] = $this->reviewable();

        $component = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        // Capture BEFORE the call — the Testable is mutated in place.
        $beforeHtml = $component->html();
        $editing = $component->call('startEditing')->html();

        $this->assertGreaterThan(
            substr_count($editing, 'wire:click="startEditing"'),
            substr_count($beforeHtml, 'wire:click="startEditing"'),
            'entering the editor must drop an edit button, not keep offering it',
        );
    }

    public function test_editing_mode_shows_live_checks(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->assertSet('editing', true)
            ->assertSee(__('Live SEO checks'))
            ->assertSee(__('Keyphrase in SEO title'))
            // Plugin-style SEO panel + previews render.
            ->assertSee(__('SEO settings for this article'))
            ->assertSee(__('Google preview'))
            ->assertSee(__('Focus keyphrase'))
            ->assertSee(__('Social preview (Facebook / X)'))
            ->assertSee(__('Advanced (search engine directives)'));
    }

    public function test_rescore_updates_live_score_when_body_changes(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing');
        $initial = $component->get('liveScore');
        $this->assertGreaterThan(0, $initial);

        // Gut the article — score must drop.
        $component->call('rescore', '<p>Tiny text without the phrase.</p>');
        $this->assertLessThan($initial, $component->get('liveScore'));
    }

    public function test_save_edits_creates_a_new_current_version(): void
    {
        [$user, , , $topic, $article] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('editMetaTitle', 'PUBG Name Generator: The Complete 2026 Naming Guide')
            ->call('saveEdits', '<p>Edited body with the pubg name generator phrase kept.</p><h2 id="a">Section</h2><p>More.</p>')
            ->assertSet('editing', false);

        $this->assertSame(2, ContentArticle::query()->where('topic_id', $topic->id)->count());
        $current = $topic->fresh()->currentArticle;
        $this->assertSame(2, (int) $current->version);
        $this->assertStringContainsString('Edited body', $current->html);
        $this->assertSame('PUBG Name Generator: The Complete 2026 Naming Guide', $current->meta_title);
        $this->assertSame('client', $current->generation_meta['edited_by'] ?? null);
        $this->assertFalse((bool) $article->fresh()->is_current);
        $this->assertNotNull($current->seo_score); // re-scored on save
    }

    public function test_save_persists_per_article_seo_overrides(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('editSlug', 'custom-slug')
            ->set('editCanonical', 'https://example.com/canonical')
            ->set('editNoindex', true)
            ->set('editNofollow', true)
            ->set('editOgTitle', 'Social Title')
            ->set('editOgDescription', 'Social description')
            ->set('editOgImage', 'https://example.com/og.jpg')
            ->set('editTwitterTitle', 'X Title')
            ->set('editTwitterCard', 'summary')
            ->call('saveEdits', '<p>Body with the pubg name generator phrase.</p><h2 id="a">S</h2><p>More.</p>');

        $current = $topic->fresh()->currentArticle;
        $this->assertSame('custom-slug', $current->slug);
        $this->assertSame('https://example.com/canonical', $current->canonical_url);
        $this->assertTrue((bool) $current->robots_noindex);
        $this->assertTrue((bool) $current->robots_nofollow);
        $this->assertSame('Social Title', $current->og_title);
        $this->assertSame('Social description', $current->og_description);
        $this->assertSame('https://example.com/og.jpg', $current->og_image);
        $this->assertSame('X Title', $current->twitter_title);
        $this->assertSame('summary', $current->twitter_card);
    }

    public function test_focus_keyword_defaults_to_topic_but_override_persists_only_when_changed(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        // Defaults to the topic keyword; leaving it unchanged stores no override.
        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->assertSet('editFocusKeyword', 'pubg name generator')
            ->call('saveEdits', '<p>Body with the pubg name generator phrase.</p><h2 id="a">S</h2><p>x.</p>');
        $this->assertNull($topic->fresh()->currentArticle->focus_keyword, 'unchanged focus keyword stays null (flows from topic)');

        // A genuine override is stored.
        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('editFocusKeyword', 'stylish pubg names')
            ->call('saveEdits', '<p>Body about stylish pubg names here.</p><h2 id="a">S</h2><p>x.</p>');
        $this->assertSame('stylish pubg names', $topic->fresh()->currentArticle->focus_keyword);
    }

    public function test_failing_checks_carry_a_fix_hint(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        // Force a keyphrase the title/body don't contain → placement checks fail
        // and must surface an actionable hint, not just the label.
        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('editFocusKeyword', 'entirely absent phrase');

        $checks = collect($component->get('liveChecks'));
        $failing = $checks->firstWhere('passed', false);
        $this->assertNotNull($failing);
        $this->assertNotEmpty($failing['hint'] ?? '', 'a failing check must include a fix hint');
        $component->assertSee(__('Add your focus keyphrase to the SEO title.'));
    }

    public function test_focus_keyword_override_drives_the_live_audit(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        // A keyphrase absent from the title/body → its placement checks fail,
        // proving the audit re-scores against the override, not the topic.
        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing');
        $baseline = $component->get('liveScore');

        $component->set('editFocusKeyword', 'wholly unrelated phrase');
        $this->assertLessThan($baseline, $component->get('liveScore'));
    }

    public function test_save_edits_strips_scripts(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->call('saveEdits', '<p>Safe.</p><script>alert(1)</script><p onclick="evil()">x</p>');

        $html = $topic->fresh()->currentArticle->html;
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_ai_edit_runs_the_shared_tool_and_returns_text(): void
    {
        config(['services.mistral.key' => 'fake', 'services.ai.provider' => 'mistral']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'A tightened rewrite of the sentence.']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]], 200),
        ]);

        [$user, $website, , $topic] = $this->reviewable();
        // ai_writer is tier-gated; the seeded trial plan includes it but the
        // global kill-switch map defaults it FALSE on a fresh DB — flip on
        // like prod's settings row does.
        Setting::set('global_feature_flags', ['ai_writer' => true]);
        $this->actingAs($user);

        $component = new ArticleReview;
        $component->topicId = $topic->id;
        $out = $component->aiEdit('rewrite-content', 'A clunky sentence that needs work.');

        $this->assertSame('A tightened rewrite of the sentence.', $out);
    }

    public function test_ai_edit_works_without_ai_writer_flag(): void
    {
        // Content-only customers don't have the SEO `ai_writer` Pro flag. The
        // content editor's inline AI must STILL run (content-product independence)
        // — NOTE this test deliberately does NOT flip ai_writer on.
        config(['services.mistral.key' => 'fake', 'services.ai.provider' => 'mistral']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'A polished rewrite.']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]], 200),
        ]);

        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $component = new ArticleReview;
        $component->topicId = $topic->id;
        $out = $component->aiEdit('rewrite-content', 'A clunky sentence that needs work.');

        $this->assertSame('A polished rewrite.', $out, 'inline AI must not be gated behind ai_writer for content customers');
    }

    public function test_ai_edit_bills_the_content_ai_meter(): void
    {
        // Inline edits meter the Content Autopilot AI meter (ContentLlmSpendMeter),
        // NOT the reviewer's dashboard token pool.
        config([
            'services.mistral.key' => 'fake', 'services.ai.provider' => 'mistral',
            'services.content_autopilot.llm_monthly_cap_usd' => 100,
        ]);
        Redis::del('content:llm:spend:'.now()->utc()->format('Y-m'));
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'A polished rewrite.']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]], 200)]);

        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);
        $meter = app(ContentLlmSpendMeter::class);
        $before = $meter->spent();

        $component = new ArticleReview;
        $component->topicId = $topic->id;
        $out = $component->aiEdit('rewrite-content', 'A clunky sentence that needs work.');

        $this->assertSame('A polished rewrite.', $out);
        $this->assertGreaterThan($before, app(ContentLlmSpendMeter::class)->spent(), 'content edit must bill the content AI meter');
    }

    public function test_ai_edit_refused_when_content_meter_exhausted(): void
    {
        config(['services.content_autopilot.llm_monthly_cap_usd' => 0.01]);
        Redis::del('content:llm:spend:'.now()->utc()->format('Y-m'));
        app(ContentLlmSpendMeter::class)->add(1.0); // over the 0.01 cap

        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);
        $component = new ArticleReview;
        $component->topicId = $topic->id;

        // Refused BEFORE any LLM call — no Http::fake needed.
        $this->assertNull($component->aiEdit('rewrite-content', 'Some text to rewrite.'));

        Redis::del('content:llm:spend:'.now()->utc()->format('Y-m'));
    }

    public function test_ai_edit_rejects_unknown_tool(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $component = new ArticleReview;
        $component->topicId = $topic->id;

        $this->assertNull($component->aiEdit('delete-everything', 'text'));
    }

    // ── WYSIWYG editor: rich content preservation + in-editor images ──────

    public function test_save_edits_preserves_table_and_figure(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $body = '<p>Intro with the pubg name generator phrase.</p>'
            .'<figure class="content-image"><img src="https://cdn.example.com/x.png" alt="A name" loading="lazy" /></figure>'
            .'<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Cool</td></tr></tbody></table>'
            .'<h2 id="a">Section</h2><p>More text about the pubg name generator here.</p>';

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->call('saveEdits', $body);

        $html = $topic->fresh()->currentArticle->html;
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>Cool</td>', $html);
        $this->assertStringContainsString('figure class="content-image"', $html);
    }

    public function test_save_edits_preserves_code_hr_underline(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $body = '<p>Body about the pubg name generator.</p>'
            .'<pre><code>echo 1;</code></pre><hr>'
            .'<p><u>underlined</u> more pubg name generator text.</p>'
            .'<h2 id="a">S</h2><p>x</p>';

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->call('saveEdits', $body);

        $html = $topic->fresh()->currentArticle->html;
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<code>', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('<u>', $html);
    }

    public function test_upload_inline_image_stores_and_creates_row(): void
    {
        Storage::fake(ContentImage::disk());
        [$user, , , $topic, $article] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('inlineImage', UploadedFile::fake()->image('photo.png', 800, 600))
            ->call('uploadInlineImage');

        $image = ContentImage::query()->where('article_id', $article->id)->first();
        $this->assertNotNull($image);
        $this->assertSame(ContentImage::ROLE_INLINE, $image->role);
        $this->assertSame(ContentImage::STATUS_GENERATED, $image->status);
        $this->assertNotEmpty($image->disk_path);
        Storage::disk(ContentImage::disk())->assertExists($image->disk_path);
        $this->assertNotEmpty($image->url());
    }

    public function test_upload_inline_image_rejects_non_image(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->set('inlineImage', UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))
            ->call('uploadInlineImage')
            ->assertHasErrors('inlineImage');

        $this->assertSame(0, ContentImage::query()->count());
    }

    public function test_request_inline_image_returns_null_when_unavailable(): void
    {
        // No Ideogram key configured → isConfigured() false → unavailable path.
        config(['services.ideogram.key' => null]);
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->call('requestInlineImage', 'a friendly robot')
            ->assertDispatched('ai-edit-failed');

        // No pending row is left dangling when generation is unavailable.
        $this->assertSame(0, ContentImage::query()->where('status', ContentImage::STATUS_PENDING)->count());
    }

    public function test_poll_inline_image_rejects_foreign_image(): void
    {
        Storage::fake(ContentImage::disk());
        [$user, , , $topic, $article] = $this->reviewable();
        // A second, unrelated topic/article/image (different owner).
        [, , , , $otherArticle] = $this->reviewable();
        $foreign = ContentImage::query()->create([
            'article_id' => $otherArticle->id,
            'role' => ContentImage::ROLE_INLINE,
            'status' => ContentImage::STATUS_GENERATED,
            'disk_path' => 'content/images/foreign.png',
        ]);
        $mine = ContentImage::query()->create([
            'article_id' => $article->id,
            'role' => ContentImage::ROLE_INLINE,
            'status' => ContentImage::STATUS_GENERATED,
            'disk_path' => 'content/images/mine.png',
        ]);

        $this->actingAs($user);
        $component = new ArticleReview;
        $component->topicId = $topic->id;

        $this->assertSame(['failed' => true], $component->pollInlineImage($foreign->id));
        $this->assertArrayHasKey('url', $component->pollInlineImage($mine->id));
    }
}
