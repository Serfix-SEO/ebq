<?php

namespace Tests\Feature\Content;

use App\Jobs\GenerateContentImagesJob;
use App\Jobs\ProduceContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Exactly one version of a topic may be `is_current`, and it must be the one
 * the producer decided to KEEP.
 *
 * Prod 2026-07-29: the final de-AI cleanup pass stores its candidate through
 * storeScoredVersion(), which moves `is_current` onto it as a side effect of
 * saving. When the candidate REGRESSED the SEO score the producer reverted its
 * local $article — but nothing put `is_current` back. The client then read the
 * rejected, lower-scoring draft, and GenerateContentImagesJob (which drops any
 * article that isn't current) silently generated NO images at all: the producer
 * had dispatched it with the kept version's id.
 */
class ContentArticleVersionCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: ContentTopic, 1: ContentArticle, 2: ContentArticle} */
    private function topicWithRejectedCleanup(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_READY,
        ]);

        // v1: the version the producer keeps (higher score).
        $kept = ContentArticle::storeVersion($topic, [
            'h1' => 'Kept version', 'html' => '<p>kept</p>', 'seo_score' => 99, 'word_count' => 900,
        ]);
        // v2: the cleanup candidate — stored (so it grabbed is_current), then rejected.
        $rejected = ContentArticle::storeVersion($topic, [
            'h1' => 'Cleanup version', 'html' => '<p>cleaned</p>', 'seo_score' => 97, 'word_count' => 880,
            'generation_meta' => ['stage' => 'de_ai_cleanup'],
        ]);

        // NOTE: $kept is returned UNREFRESHED on purpose — that is the handle
        // the producer holds, and its in-memory is_current is still true even
        // though storing the candidate flipped the row to false. A makeCurrent()
        // that relies on model dirtiness writes nothing here.
        return [$topic, $kept, $rejected->fresh()];
    }

    public function test_rejecting_the_cleanup_restores_the_kept_version_as_current(): void
    {
        [$topic, $kept, $rejected] = $this->topicWithRejectedCleanup();
        $this->assertTrue($rejected->is_current, 'precondition: storing the candidate moved is_current');

        $producer = app(ContentArticleProducer::class);
        $m = new ReflectionMethod($producer, 'makeCurrent');
        $m->setAccessible(true);
        $m->invoke($producer, $kept);

        $this->assertTrue($kept->fresh()->is_current, 'the kept version is current again');
        $this->assertFalse($rejected->fresh()->is_current, 'the rejected candidate is demoted');
        $this->assertSame(
            1,
            $topic->articles()->where('is_current', true)->count(),
            'exactly one current version per topic',
        );
        $this->assertSame(99, $topic->currentArticle()->first()->seo_score);
        // The exact prod failure: zero current versions → "No current article
        // version to publish", an article that cannot be opened, no publish CTA.
        $this->assertNotSame(
            0,
            $topic->articles()->where('is_current', true)->count(),
            'a topic must never end up with NO current version',
        );
    }

    /**
     * The producer dispatches images for whatever is CURRENT, so a version
     * stored after it captured its handle can no longer cost the client every
     * image (the job silently drops non-current articles).
     */
    public function test_images_are_dispatched_for_the_current_version(): void
    {
        Queue::fake();
        [$topic, $kept, $rejected] = $this->topicWithRejectedCleanup();

        // $rejected is current here — the producer's own handle is stale.
        $job = new ProduceContentArticleJob($topic->id);
        $ref = new ReflectionMethod($job, 'dispatchImages');
        $ref->setAccessible(true);
        $ref->invoke($job, $topic, $kept);

        Queue::assertPushed(GenerateContentImagesJob::class, function (GenerateContentImagesJob $j) use ($rejected) {
            return $j->articleId === $rejected->id; // whatever is current wins
        });
    }
}
