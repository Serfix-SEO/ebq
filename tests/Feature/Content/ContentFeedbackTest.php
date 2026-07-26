<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentArticleFeedback;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: ContentTopic} */
    private function reviewable(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'seo dubai',
            'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::storeVersion($topic, ['h1' => 'X', 'html' => '<p>Body.</p>', 'word_count' => 2, 'seo_score' => 80, 'seo_issues' => []]);

        return [$user, $topic];
    }

    public function test_client_can_rate_article(): void
    {
        [$user, $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSee(__('Do you like this article?'))
            ->call('rateArticle', ContentArticleFeedback::RATING_LOVE)
            ->assertSet('feedbackRating', ContentArticleFeedback::RATING_LOVE);

        $this->assertDatabaseHas('content_article_feedback', [
            'topic_id' => $topic->id,
            'user_id' => $user->id,
            'rating' => 'love',
        ]);
    }

    public function test_rating_updates_in_place_not_duplicated(): void
    {
        [$user, $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('rateArticle', ContentArticleFeedback::RATING_LOVE)
            ->call('rateArticle', ContentArticleFeedback::RATING_WRONG);

        $this->assertSame(1, ContentArticleFeedback::query()->where('topic_id', $topic->id)->count());
        $this->assertSame('wrong', ContentArticleFeedback::query()->where('topic_id', $topic->id)->value('rating'));
    }

    public function test_rejects_unknown_rating(): void
    {
        [$user, $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('rateArticle', 'garbage')
            ->assertSet('feedbackRating', '');

        $this->assertSame(0, ContentArticleFeedback::query()->count());
    }

    public function test_client_can_attach_a_comment(): void
    {
        [$user, $topic] = $this->reviewable();
        $this->actingAs($user);

        Livewire::test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('rateArticle', ContentArticleFeedback::RATING_REWRITES)
            ->set('feedbackComment', 'Tone is too formal.')
            ->call('saveFeedbackComment');

        $this->assertDatabaseHas('content_article_feedback', [
            'topic_id' => $topic->id,
            'rating' => 'rewrites',
            'comment' => 'Tone is too formal.',
        ]);
    }

    public function test_admin_page_lists_feedback(): void
    {
        [$user, $topic] = $this->reviewable();
        ContentArticleFeedback::create([
            'topic_id' => $topic->id,
            'website_id' => $topic->website_id,
            'user_id' => $user->id,
            'rating' => ContentArticleFeedback::RATING_WRONG,
            'comment' => 'Wrong facts in section 2.',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get(route('admin.content-feedback.index'))
            ->assertOk()
            ->assertSee('Content feedback')
            ->assertSee(ContentArticleFeedback::label('wrong'))
            ->assertSee('Wrong facts in section 2.');
    }

    public function test_admin_page_is_admin_only(): void
    {
        [$user] = $this->reviewable();
        $this->actingAs($user);
        $this->get(route('admin.content-feedback.index'))->assertForbidden();
    }
}
