<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\DiscoverProductPagesJob;
use App\Jobs\Content\ExtractProductBatchJob;
use App\Jobs\Content\FinalizeProductCatalogJob;
use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use App\Models\Website;
use App\Services\Content\Catalog\ProductCatalogService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Strict Product Mode: catalog run lifecycle (discover → extract → finalize). */
class ProductCatalogRunTest extends TestCase
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

    /** @return array{Website, ContentPlan} */
    private function site(array $planAttrs = []): array
    {
        $website = Website::factory()->for(\App\Models\User::factory())->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE] + $planAttrs);

        return [$website, $plan];
    }

    public function test_start_run_is_idempotent_while_in_flight(): void
    {
        Queue::fake();
        [, $plan] = $this->site();

        $svc = app(ProductCatalogService::class);
        $first = $svc->startRun($plan, 'admin');
        $second = $svc->startRun($plan, 'admin');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContentProductRun::query()->count());
        Queue::assertPushed(DiscoverProductPagesJob::class, 1);
    }

    public function test_discovery_with_no_pages_fails_run_honestly(): void
    {
        [, $plan] = $this->site();
        $run = ContentProductRun::factory()->create([
            'website_id' => $plan->website_id, 'status' => ContentProductRun::STATUS_PENDING,
        ]);

        (new DiscoverProductPagesJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ContentProductRun::STATUS_FAILED, $run->status);
        $this->assertSame('no_product_pages_found', $run->error);
    }

    public function test_finalize_dedupes_variants_marks_gone_and_flips_ready(): void
    {
        Queue::fake();
        [$website] = $this->site();
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'trigger' => 'admin',
            'status' => ContentProductRun::STATUS_EXTRACTING,
            'started_at' => now()->subMinute(),
        ]);
        // Two variants (same name+image), one distinct, one stale (not seen this run).
        ContentProduct::factory()->create(['website_id' => $website->id, 'name' => 'Twin', 'image_url' => 'https://i/x.png', 'last_seen_at' => now()]);
        ContentProduct::factory()->create(['website_id' => $website->id, 'name' => 'Twin', 'image_url' => 'https://i/x.png', 'last_seen_at' => now()]);
        ContentProduct::factory()->create(['website_id' => $website->id, 'name' => 'Unique', 'last_seen_at' => now()]);
        $stale = ContentProduct::factory()->create(['website_id' => $website->id, 'name' => 'Old', 'last_seen_at' => now()->subDays(3)]);

        (new FinalizeProductCatalogJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ContentProductRun::STATUS_READY, $run->status);
        $this->assertSame(2, $run->products_extracted, 'Twin deduped + Old gone → Twin + Unique remain');
        $this->assertSame(ContentProduct::STATUS_GONE, $stale->refresh()->status);
    }

    public function test_finalize_zero_products_fails_with_no_products_found(): void
    {
        Queue::fake();
        [$website] = $this->site();
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
            'started_at' => now(),
        ]);

        (new FinalizeProductCatalogJob($run->id))->handle();

        $this->assertSame(ContentProductRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertSame('no_products_found', $run->error);
        Queue::assertNotPushed(PlanContentTopicsJob::class);
    }

    public function test_finalize_auto_proceeds_only_for_strict_plans(): void
    {
        Queue::fake();
        [$website, $plan] = $this->site(['product_mode' => 'strict']);
        $other = ContentPlan::factory()->create([
            'website_id' => Website::factory()->for(\App\Models\User::factory())->create()->id, 'product_mode' => null,
        ]);
        ContentProduct::factory()->create(['website_id' => $website->id, 'last_seen_at' => now()]);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
            'started_at' => now()->subMinute(),
        ]);

        (new FinalizeProductCatalogJob($run->id))->handle();

        Queue::assertPushed(PlanContentTopicsJob::class, fn ($j) => $j->planId === $plan->id);
        Queue::assertNotPushed(PlanContentTopicsJob::class, fn ($j) => $j->planId === $other->id);
    }

    public function test_dispatcher_fails_stalled_runs_and_retries_strict(): void
    {
        Queue::fake();
        [, $plan] = $this->site(['product_mode' => 'strict']);
        ContentProductRun::factory()->create([
            'website_id' => $plan->website_id,
            'status' => ContentProductRun::STATUS_EXTRACTING,
            'heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('ebq:content-autopilot');

        $this->assertSame('stalled', ContentProductRun::query()->where('status', 'failed')->value('error'));
        // The auto-retry created a fresh run for the strict plan.
        $this->assertTrue(ContentProductRun::query()->whereIn('status', ContentProductRun::IN_FLIGHT)->exists());
    }

    public function test_extract_batch_stores_products_via_http(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'shop.test/*' => \Illuminate\Support\Facades\Http::response(
                '<html><head><script type="application/ld+json">'
                .json_encode(['@type' => 'Product', 'name' => 'Fetched Serum', 'offers' => ['price' => 10, 'priceCurrency' => 'USD', 'availability' => 'InStock']])
                .'</script></head><body></body></html>', 200),
        ]);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        [$website] = $this->site();
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
        ]);

        (new ExtractProductBatchJob($run->id, ['https://shop.test/products/serum']))->handle(
            app(\App\Services\Crawler\CrawlFetcher::class),
            app(\App\Services\Crawler\FirecrawlClient::class),
            app(\App\Services\Content\Catalog\ProductExtractor::class),
        );

        $product = ContentProduct::query()->where('website_id', $website->id)->firstOrFail();
        $this->assertSame('Fetched Serum', $product->name);
        $this->assertSame(1000, $product->price_cents);
        $this->assertSame(1, $run->refresh()->products_extracted);
    }

    public function test_client_exclusion_survives_re_extraction(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'shop.test/*' => \Illuminate\Support\Facades\Http::response(
                '<html><head><script type="application/ld+json">'
                .json_encode(['@type' => 'Product', 'name' => 'Excluded Thing'])
                .'</script></head><body></body></html>', 200),
        ]);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        [$website] = $this->site();
        $url = 'https://shop.test/products/excluded';
        ContentProduct::factory()->create([
            'website_id' => $website->id, 'url' => $url, 'url_hash' => hash('sha256', $url),
            'name' => 'Excluded Thing', 'is_excluded' => true,
        ]);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
        ]);

        (new ExtractProductBatchJob($run->id, [$url]))->handle(
            app(\App\Services\Crawler\CrawlFetcher::class),
            app(\App\Services\Crawler\FirecrawlClient::class),
            app(\App\Services\Content\Catalog\ProductExtractor::class),
        );

        $this->assertTrue(ContentProduct::query()->where('url_hash', hash('sha256', $url))->firstOrFail()->is_excluded,
            'the client\'s exclusion is durable across re-scrapes');
    }
}
