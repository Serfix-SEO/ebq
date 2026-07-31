<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The review page polls every 3s while an article is being built, and a poll
 * re-renders WITHOUT re-running mount(). The editable state (body, meta, live
 * score) is therefore hydrated once, when the page opens — so anything the
 * pipeline stores afterwards used to be invisible to it.
 *
 * Prod 2026-07-30: open a freshly written article straight from the progress
 * modal and click Edit, and the SEO score jumped. Refreshing first made it
 * agree. Same root cause, worse consequence: Save writes the held body back as
 * a new version, so editing from that state could overwrite the finished
 * article with the earlier draft the page loaded with.
 */
class ArticleReviewStalenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();      // never run pipeline work from a UI test
    }

    /** @return array{0: User, 1: ContentTopic} */
    private function topicWithDraft(string $html): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
            'target_keyword' => 'seo audit',
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'How to Do an SEO Audit',
            'html' => $html,
            'seo_score' => 71,
            'word_count' => 40,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return [$user, $topic];
    }

    public function test_a_version_stored_after_the_page_opened_is_picked_up_on_the_next_render(): void
    {
        [, $topic] = $this->topicWithDraft('<p>The first draft, mid-build.</p>');

        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id]);
        $this->assertStringContainsString('first draft', $component->get('bodyHtml'));
        $firstScore = $component->get('liveScore');

        // The pipeline finishes underneath the open page (this is what the
        // image pass does: it stores a FURTHER version after the article is
        // already readable).
        $finished = ContentArticle::storeVersion($topic, [
            'h1' => 'How to Do an SEO Audit',
            'html' => '<h2>An seo audit, step by step</h2><p>The finished article, with images and a full body.</p>',
            'seo_score' => 94,
            'word_count' => 980,
        ]);

        // A poll — no mount, just a re-render.
        $component->call('$refresh');

        $this->assertSame($finished->id, $component->get('hydratedArticleId'));
        $this->assertStringContainsString('finished article', $component->get('bodyHtml'));
        $this->assertNotSame($firstScore, $component->get('liveScore'), 'the live score must follow the new version');
    }

    /** Opening the editor must never open it on a superseded body. */
    public function test_clicking_edit_opens_the_current_version_not_the_one_the_page_loaded_with(): void
    {
        [, $topic] = $this->topicWithDraft('<p>The first draft, mid-build.</p>');

        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id]);

        $finished = ContentArticle::storeVersion($topic, [
            'h1' => 'How to Do an SEO Audit',
            'html' => '<h2>An seo audit, step by step</h2><p>The finished article body.</p>',
            'seo_score' => 94,
            'word_count' => 980,
        ]);

        $component->call('startEditing');

        $this->assertTrue($component->get('editing'));
        $this->assertSame($finished->id, $component->get('hydratedArticleId'));
        $this->assertStringContainsString('finished article body', $component->get('bodyHtml'));
    }

    /** An edit in progress is sacred: a re-render must not throw it away. */
    public function test_an_in_progress_edit_is_never_clobbered_by_the_sync(): void
    {
        [, $topic] = $this->topicWithDraft('<p>The first draft.</p>');

        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('startEditing')
            ->call('rescore', '<p>Words the client typed and has not saved.</p>');

        ContentArticle::storeVersion($topic, [
            'h1' => 'How to Do an SEO Audit',
            'html' => '<p>A version stored by something else.</p>',
            'seo_score' => 90,
            'word_count' => 500,
        ]);

        $component->call('$refresh');

        $this->assertStringContainsString('client typed', $component->get('bodyHtml'));
    }
}
