<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\DiscoverProductPagesJob;
use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Catalog\StrictModeActivator;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** StrictModeActivator: the single entry point for every strict/normal choice. */
class StrictModeActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function plan(): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE,
        ]);
    }

    public function test_choosing_normal_just_sets_columns(): void
    {
        Queue::fake();
        $plan = $this->plan();

        app(StrictModeActivator::class)->choose($plan, 'normal', 'wizard');

        $plan->refresh();
        $this->assertSame('normal', $plan->product_mode);
        $this->assertNotNull($plan->product_mode_decided_at);
        Queue::assertNothingPushed();
    }

    public function test_choosing_strict_clears_only_unwritten_future_topics_and_scrapes(): void
    {
        Queue::fake();
        $plan = $this->plan();
        // Unwritten planned topics (the step-2 early-planning wrinkle) — cleared.
        $unwritten = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $plan->website_id,
            'title' => 'Random topic', 'target_keyword' => 'k', 'status' => ContentTopic::STATUS_APPROVED,
        ]);
        // Written topic — must survive.
        $written = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $plan->website_id,
            'title' => 'Written topic', 'target_keyword' => 'k2', 'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::create([
            'topic_id' => $written->id, 'version' => 1, 'is_current' => true,
            'h1' => 'W', 'html' => '<p>x</p>',
        ]);

        app(StrictModeActivator::class)->choose($plan, 'strict', 'wizard');

        $this->assertDatabaseMissing('content_topics', ['id' => $unwritten->id]);
        $this->assertDatabaseHas('content_topics', ['id' => $written->id]);
        $this->assertSame('strict', $plan->refresh()->product_mode);
        // No catalog yet → scrape starts (planner comes later via finalize).
        Queue::assertPushed(DiscoverProductPagesJob::class);
        Queue::assertNotPushed(PlanContentTopicsJob::class);
    }

    public function test_choosing_strict_with_ready_catalog_replans_immediately(): void
    {
        Queue::fake();
        $plan = $this->plan();
        ContentProduct::factory()->create(['website_id' => $plan->website_id]);

        app(StrictModeActivator::class)->choose($plan, 'strict', 'banner');

        Queue::assertPushed(PlanContentTopicsJob::class, fn ($j) => $j->planId === $plan->id);
        Queue::assertNotPushed(DiscoverProductPagesJob::class);
    }

    public function test_strict_to_normal_keeps_catalog_and_ungates(): void
    {
        Queue::fake();
        $plan = $this->plan();
        $product = ContentProduct::factory()->create(['website_id' => $plan->website_id]);
        app(StrictModeActivator::class)->choose($plan, 'strict', 'settings');

        app(StrictModeActivator::class)->choose($plan, 'normal', 'settings');

        $this->assertSame('normal', $plan->refresh()->product_mode);
        $this->assertDatabaseHas('content_products', ['id' => $product->id]);
    }

    public function test_converter_carries_product_mode_and_reparents_catalog(): void
    {
        $user = User::factory()->create();
        $provisional = Website::factory()->for(User::factory())->create();
        $surviving = Website::factory()->for($user)->create();
        ContentProduct::factory()->create(['website_id' => $provisional->id]);

        \App\Services\Content\ContentOnboardingConverter::reparentCatalog($provisional->id, $surviving->id);

        $this->assertSame(1, ContentProduct::query()->where('website_id', $surviving->id)->count());
        $this->assertSame(0, ContentProduct::query()->where('website_id', $provisional->id)->count());
        // Column list carries the mode (regression pin on the enumerated list).
        $src = file_get_contents(app_path('Services/Content/ContentOnboardingConverter.php'));
        $this->assertStringContainsString("'product_mode', 'product_mode_decided_at'", $src);
    }
}
