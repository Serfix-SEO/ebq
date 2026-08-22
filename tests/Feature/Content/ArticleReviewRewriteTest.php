<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\RewriteArticleJob;
use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentArticleFeedback;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentRewriteCreditEvent as Event;
use App\Models\ContentRewriteRequest;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\RewriteCredits;
use App\Services\Content\RewritePromptGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleReviewRewriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->allowGuard(); // default: guard approves everything
    }

    private function allowGuard(): void
    {
        $this->app->instance(RewritePromptGuard::class, new class extends RewritePromptGuard
        {
            public function check(string $body): array
            {
                return ['ok' => true];
            }
        });
    }

    private function rejectGuard(string $reason, string $suggestion): void
    {
        $this->app->instance(RewritePromptGuard::class, new class($reason, $suggestion) extends RewritePromptGuard
        {
            public function __construct(private string $r, private string $s)
            {
                parent::__construct();
            }

            public function check(string $body): array
            {
                return ['ok' => false, 'reason' => $this->r, 'suggestion' => $this->s];
            }
        });
    }

    /** @return array{0: User, 1: ContentTopic} */
    private function fixture(int $credits = 1, string $status = ContentTopic::STATUS_READY): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'billing_covered_at' => now()]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => $status,
        ]);
        ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>Body.</p>', 'seo_score' => 90,
        ]);
        if ($credits > 0) {
            app(RewriteCredits::class)->grantAdmin($user, $credits);
        }

        return [$user, $topic];
    }

    public function test_rewrite_records_feedback_spends_credit_and_dispatches(): void
    {
        Queue::fake();
        [$user, $topic] = $this->fixture();

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->set('feedbackRating', ContentArticleFeedback::RATING_REWRITES)
            ->set('feedbackComment', 'Make it friendlier')
            ->call('requestRewrite');

        $this->assertDatabaseHas('content_article_feedback', [
            'topic_id' => $topic->id, 'rating' => 'rewrites', 'comment' => 'Make it friendlier',
        ]);
        $request = ContentRewriteRequest::query()->where('topic_id', $topic->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('Make it friendlier', $request->prompt);
        $this->assertSame(1, Event::query()->where('kind', Event::KIND_SPEND)->count());
        Queue::assertPushed(RewriteArticleJob::class, fn ($j) => $j->requestId === $request->id && $j->topicId === $topic->id);
    }

    public function test_wrong_verdict_also_gets_the_rewrite(): void
    {
        Queue::fake();
        [$user, $topic] = $this->fixture();

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->set('feedbackRating', ContentArticleFeedback::RATING_WRONG)
            ->set('feedbackComment', 'Facts are off')
            ->call('requestRewrite');

        $this->assertDatabaseHas('content_article_feedback', ['topic_id' => $topic->id, 'rating' => 'wrong']);
        Queue::assertPushed(RewriteArticleJob::class);
    }

    public function test_guard_rejection_keeps_feedback_spends_nothing_and_suggests(): void
    {
        Queue::fake();
        $this->rejectGuard('Not usable.', 'Try asking for a friendlier tone.');
        [$user, $topic] = $this->fixture();

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->set('feedbackRating', ContentArticleFeedback::RATING_REWRITES)
            ->set('feedbackComment', 'sneaky stuff')
            ->call('requestRewrite')
            ->assertSet('rewriteError', 'Not usable.')
            ->assertSet('rewriteSuggestion', 'Try asking for a friendlier tone.')
            ->call('applyRewriteSuggestion')
            ->assertSet('feedbackComment', 'Try asking for a friendlier tone.');

        $this->assertDatabaseHas('content_article_feedback', ['topic_id' => $topic->id, 'comment' => 'sneaky stuff']);
        $this->assertSame(0, Event::query()->where('kind', Event::KIND_SPEND)->count());
        Queue::assertNothingPushed();
    }

    public function test_no_credits_opens_packs_modal_without_spending(): void
    {
        Queue::fake();
        [$user, $topic] = $this->fixture(credits: 0);

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->set('feedbackRating', ContentArticleFeedback::RATING_REWRITES)
            ->set('feedbackComment', 'Make it friendlier')
            ->call('requestRewrite')
            ->assertSet('showPacksModal', true);

        $this->assertSame(0, Event::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_second_click_noops_while_a_rewrite_is_active(): void
    {
        Queue::fake();
        [$user, $topic] = $this->fixture(credits: 2);

        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->set('feedbackRating', ContentArticleFeedback::RATING_REWRITES)
            ->set('feedbackComment', 'x1234')
            ->call('requestRewrite')
            ->call('requestRewrite');

        $this->assertSame(1, ContentRewriteRequest::query()->count());
        $this->assertSame(1, Event::query()->where('kind', Event::KIND_SPEND)->count());
    }

    public function test_use_version_recrowns_and_repoints_images_with_tenancy(): void
    {
        [$user, $topic] = $this->fixture();
        $v1 = $topic->articles()->first();
        $v2 = ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 2, 'is_current' => false,
            'h1' => 'H2', 'meta_title' => 'H2', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>V2.</p>', 'seo_score' => 91,
        ]);
        // storeVersion isn't used here, so crown manually: v2 current.
        $v1->update(['is_current' => false]);
        $v2->update(['is_current' => true]);
        $img = ContentImage::create([
            'article_id' => $v2->id, 'role' => ContentImage::ROLE_FEATURED,
            'status' => ContentImage::STATUS_GENERATED, 'prompt' => 'p',
        ]);

        // Foreign article id → rejected (no crown change).
        $foreignTopic = ContentTopic::create([
            'plan_id' => $topic->plan_id, 'website_id' => $topic->website_id,
            'title' => 'F', 'target_keyword' => 'f', 'status' => ContentTopic::STATUS_READY,
        ]);
        $foreign = ContentArticle::create([
            'topic_id' => $foreignTopic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'F', 'meta_title' => 'F', 'meta_description' => 'D',
            'slug' => 'f', 'html' => '<p>F.</p>',
        ]);

        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        $c->call('useVersion', $foreign->id);
        $this->assertTrue($v2->fresh()->is_current, 'foreign article must not change the crown');

        $c->call('previewVersion', $v1->id)
            ->assertSet('previewVersionId', $v1->id)
            ->call('useVersion', $v1->id);

        $this->assertTrue($v1->fresh()->is_current);
        $this->assertFalse($v2->fresh()->is_current);
        $this->assertSame($v1->id, $img->fresh()->article_id, 'images follow the crown');
    }
}
