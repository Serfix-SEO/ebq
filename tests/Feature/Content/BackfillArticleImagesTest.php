<?php

namespace Tests\Feature\Content;

use App\Jobs\GenerateContentImagesJob;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Repair tool for the 2026-08-17 blackout: the monthly image cap tripped and
 * `GenerateContentImagesJob` returned early, so 91 articles across 12 clients
 * were written with zero images and nothing to retry from.
 *
 * It spends real money, so the guards matter more than the happy path.
 */
class BackfillArticleImagesTest extends TestCase
{
    use RefreshDatabase;

    private function article(bool $imagesEnabled = true, bool $withImage = false, string $created = '2026-08-17 10:00:00'): ContentArticle
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.org']);
        $plan = ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => 'active',
            'images_enabled' => $imagesEnabled,
        ]);
        $topic = ContentTopic::query()->create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'k', 'status' => 'ready',
        ]);
        $article = ContentArticle::query()->create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'T', 'html' => '<p>body</p>', 'created_at' => $created, 'updated_at' => $created,
        ]);
        if ($withImage) {
            ContentImage::query()->create([
                'article_id' => $article->id, 'role' => 'featured',
                'prompt' => 'x', 'disk_path' => 'content/images/x.png', 'status' => 'generated',
            ]);
        }

        return $article;
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Queue::fake();
        $this->article();

        $this->artisan('ebq:backfill-article-images')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_force_dispatches_one_job_per_article(): void
    {
        Queue::fake();
        $this->article();
        $this->article();

        $this->artisan('ebq:backfill-article-images --force')->assertSuccessful();

        Queue::assertPushed(GenerateContentImagesJob::class, 2);
    }

    public function test_articles_that_already_have_images_are_left_alone(): void
    {
        Queue::fake();
        $this->article(withImage: true);

        $this->artisan('ebq:backfill-article-images --force')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_client_who_turned_images_off_is_not_overridden(): void
    {
        // Their setting, not the outage — "fixing" it would be worse than the bug.
        Queue::fake();
        $this->article(imagesEnabled: false);

        $this->artisan('ebq:backfill-article-images --force')
            ->expectsOutputToContain('images off by client choice')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_articles_from_before_the_blackout_are_out_of_scope(): void
    {
        Queue::fake();
        $this->article(created: '2026-08-01 09:00:00');

        $this->artisan('ebq:backfill-article-images --force')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_it_refuses_to_run_while_the_meter_is_exhausted(): void
    {
        // Every job would return early and the run would look successful while
        // producing nothing — exactly how the outage stayed invisible.
        Queue::fake();
        $this->article();
        config(['services.ideogram.monthly_cap_usd' => 1]);
        app(\App\Services\Content\IdeogramSpendMeter::class)->add(5.0);

        $this->artisan('ebq:backfill-article-images --force')
            ->expectsOutputToContain('EXHAUSTED')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
