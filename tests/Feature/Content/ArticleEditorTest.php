<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
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

    public function test_editing_mode_shows_live_checks(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->assertSet('editing', true)
            ->assertSee(__('Live SEO checks'))
            ->assertSee(__('Keyphrase in SEO title'));
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
        \App\Models\Setting::set('global_feature_flags', ['ai_writer' => true]);
        $this->actingAs($user);

        $component = new ArticleReview;
        $component->topicId = $topic->id;
        $out = $component->aiEdit('rewrite-content', 'A clunky sentence that needs work.');

        $this->assertSame('A tightened rewrite of the sentence.', $out);
    }

    public function test_ai_edit_rejects_unknown_tool(): void
    {
        [$user, , , $topic] = $this->reviewable();
        $this->actingAs($user);

        $component = new ArticleReview;
        $component->topicId = $topic->id;

        $this->assertNull($component->aiEdit('delete-everything', 'text'));
    }
}
