<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\DiscoverProductPagesJob;
use App\Jobs\Content\ExtractProductBatchJob;
use App\Jobs\Content\FinalizeProductCatalogJob;
use App\Jobs\Content\RefreshProductPagesJob;
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
        // Discovery now fetches robots/sitemap live — fake it (the factory
        // domain can be a REAL registrable name; tests must never hit the
        // network, and an unlucky real sitemap made this flake in-suite).
        \Illuminate\Support\Facades\Http::fake();
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

    // ── Phase 5: refresh + freshness ────────────────────────────────────

    public function test_refresh_job_creates_a_partial_refresh_run(): void
    {
        Bus::fake();
        [$website] = $this->site();

        (new RefreshProductPagesJob($website->id, ['https://shop.example/products/a', 'https://shop.example/products/a']))->handle();

        $run = ContentProductRun::query()->where('website_id', $website->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('refresh', $run->trigger);
        $this->assertSame(ContentProductRun::STATUS_EXTRACTING, $run->status);
        $this->assertSame(1, $run->pages_found, 'urls dedupe');
        Bus::assertBatchCount(1);
    }

    public function test_refresh_job_never_races_an_in_flight_run(): void
    {
        Bus::fake();
        [$website] = $this->site();
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_EXTRACTING,
        ]);

        (new RefreshProductPagesJob($website->id, ['https://shop.example/products/a']))->handle();

        $this->assertSame(1, ContentProductRun::query()->where('website_id', $website->id)->count());
        Bus::assertBatchCount(0);
    }

    public function test_refresh_finalize_never_marks_products_gone(): void
    {
        Queue::fake();
        [$website] = $this->site();
        $untouched = ContentProduct::factory()->create([
            'website_id' => $website->id, 'last_seen_at' => now()->subDays(20),
        ]);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'trigger' => 'refresh',
            'status' => ContentProductRun::STATUS_EXTRACTING, 'started_at' => now()->subMinute(),
        ]);

        (new FinalizeProductCatalogJob($run->id))->handle();

        $this->assertSame(ContentProduct::STATUS_ACTIVE, $untouched->refresh()->status);
        $this->assertSame(ContentProductRun::STATUS_READY, $run->refresh()->status);
    }

    public function test_dispatcher_starts_a_monthly_full_rerun_for_stale_strict_catalogs(): void
    {
        Queue::fake();
        [$website, ] = $this->site(['product_mode' => 'strict']);
        ContentProduct::factory()->create(['website_id' => $website->id]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'trigger' => 'onboarding',
            'status' => ContentProductRun::STATUS_READY,
            'started_at' => now()->subDays(40), 'finished_at' => now()->subDays(40),
        ]);

        $this->artisan('ebq:content-autopilot');

        $this->assertTrue(
            ContentProductRun::query()->where('website_id', $website->id)->where('trigger', 'monthly')->exists()
        );
        Queue::assertPushed(DiscoverProductPagesJob::class);
    }

    public function test_dispatcher_refreshes_catalog_pages_the_crawler_saw_change(): void
    {
        Queue::fake();
        [$website, ] = $this->site(['product_mode' => 'strict']);
        $url = 'https://'.$website->normalized_domain.'/products/changed-item';
        ContentProduct::factory()->create([
            'website_id' => $website->id, 'url' => $url, 'updated_at' => now()->subDays(5),
        ]);
        ContentProductRun::factory()->create([
            'website_id' => $website->id, 'trigger' => 'onboarding',
            'status' => ContentProductRun::STATUS_READY,
            'started_at' => now()->subDays(6), 'finished_at' => now()->subDays(6),
        ]);
        \App\Models\WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => $url,
            'url_hash' => \App\Models\WebsitePage::hashUrl($url),
            'http_status' => 200, 'last_crawled_at' => now(),
            'last_changed_at' => now()->subDay(),
        ]);

        $this->artisan('ebq:content-autopilot');

        Queue::assertPushed(RefreshProductPagesJob::class, fn ($j) => $j->urls === [$url]);
    }

    public function test_dispatcher_catalog_refresh_is_daily_guarded(): void
    {
        Queue::fake();
        [$website, ] = $this->site(['product_mode' => 'strict']);
        ContentProduct::factory()->create(['website_id' => $website->id]);
        // No full run ever → first tick starts a monthly; second tick must not
        // stack another (daily cache guard).
        $this->artisan('ebq:content-autopilot');
        ContentProductRun::query()->update(['status' => ContentProductRun::STATUS_READY, 'finished_at' => now()]);
        $this->artisan('ebq:content-autopilot');

        $this->assertSame(1, ContentProductRun::query()->where('website_id', $website->id)->count());
    }

    public function test_discovery_pulls_product_urls_straight_from_the_sitemap(): void
    {
        // bellavest 2026-09-07: the content-only crawl caps at ~200 pages, so
        // catalog discovery limited to crawl inventory missed a third of the
        // shop. The sitemap is fetched directly now.
        Bus::fake();
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        [$website, ] = [Website::factory()->for(\App\Models\User::factory())->create(['domain' => 'shop.example', 'normalized_domain' => 'shop.example']), null];
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_PENDING,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.example/robots.txt' => \Illuminate\Support\Facades\Http::response("User-agent: *\nSitemap: https://shop.example/sitemap.xml", 200),
            'https://shop.example/sitemap.xml' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://shop.example/products/alpha</loc></url>'
                .'<url><loc>https://shop.example/products/beta</loc></url>'
                .'<url><loc>https://shop.example/about-us</loc></url>'
                .'<url><loc>https://other-site.example/products/foreign</loc></url>'
                .'</urlset>', 200),
        ]);

        (new DiscoverProductPagesJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ContentProductRun::STATUS_EXTRACTING, $run->status);
        $this->assertSame(2, $run->pages_found, 'both own-site product URLs, nothing else');
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    public function test_index_walk_reaches_the_products_child_past_noise_siblings(): void
    {
        // carmenperfumes 2026-09-08: a Shopify "agentic discovery" child index
        // sat before the products sitemap and starved the walk. Product-named
        // children must be visited first, entities decoded (&amp; in child
        // querystring URLs).
        Bus::fake();
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        $website = Website::factory()->for(\App\Models\User::factory())->create(['domain' => 'shop.example', 'normalized_domain' => 'shop.example']);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_PENDING,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.example/robots.txt' => \Illuminate\Support\Facades\Http::response("Sitemap: https://shop.example/sitemap.xml", 200),
            'https://shop.example/sitemap.xml' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://shop.example/sitemap_agentic.xml</loc></sitemap>'
                .'<sitemap><loc>https://shop.example/sitemap_products_1.xml?from=1&amp;to=99</loc></sitemap>'
                .'</sitemapindex>', 200),
            'https://shop.example/sitemap_agentic.xml' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://shop.example/s/agent-junk</loc></url></urlset>', 200),
            'https://shop.example/sitemap_products_1.xml?from=1&to=99' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://shop.example/products/gamma</loc></url>'
                .'<url><loc>https://shop.example/products/delta</loc></url></urlset>', 200),
        ]);

        (new DiscoverProductPagesJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ContentProductRun::STATUS_EXTRACTING, $run->status);
        $this->assertSame(2, $run->pages_found, 'both products from the entity-encoded child sitemap');
    }

    public function test_extractor_ignores_namespaced_image_locs(): void
    {
        // Shopify product sitemaps embed <image:image><image:loc> inside each
        // <url>; localName matching let the CDN image URL overwrite the page
        // URL (carmenperfumes 2026-09-08: 87 products, 4 survived).
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.example/sitemap_products.xml' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
                .'<url><loc>https://shop.example/products/alpha</loc><lastmod>2026-09-01</lastmod>'
                .'<image:image><image:loc>https://cdn.example/img/alpha.jpg</image:loc></image:image></url>'
                .'<url><loc>https://shop.example/products/beta</loc>'
                .'<image:image><image:loc>https://cdn.example/img/beta.jpg</image:loc></image:image></url>'
                .'</urlset>', 200),
        ]);

        $entries = app(\App\Support\Crawler\SitemapUrlExtractor::class)
            ->extract(['https://shop.example/sitemap_products.xml']);

        $locs = array_column($entries, 'loc');
        $this->assertSame(['https://shop.example/products/alpha', 'https://shop.example/products/beta'], $locs,
            'page locs survive; namespaced image locs never overwrite them');
        $this->assertSame('2026-09-01', $entries[0]['lastmod']);
    }

    public function test_locale_alternate_product_urls_collapse_to_the_primary(): void
    {
        // /ar/products/x beside /products/x doubled carmen's catalog — the
        // Arabic name dodges the variant dedupe. Locale-only stores keep
        // their URLs (no unprefixed twin exists).
        Bus::fake();
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        $website = Website::factory()->for(\App\Models\User::factory())->create(['domain' => 'shop.example', 'normalized_domain' => 'shop.example']);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'status' => ContentProductRun::STATUS_PENDING,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.example/robots.txt' => \Illuminate\Support\Facades\Http::response("Sitemap: https://shop.example/sitemap.xml", 200),
            'https://shop.example/sitemap.xml' => \Illuminate\Support\Facades\Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://shop.example/products/alpha</loc></url>'
                .'<url><loc>https://shop.example/ar/products/alpha</loc></url>'
                .'<url><loc>https://shop.example/ar/products/arabic-only</loc></url>'
                .'</urlset>', 200),
        ]);

        (new DiscoverProductPagesJob($run->id))->handle();

        $this->assertSame(2, $run->refresh()->pages_found,
            'localized twin dropped; locale-only product kept');
    }

    public function test_pending_llm_pages_are_not_gone_marked_by_finalize(): void
    {
        // Full-scan race (carmen 2026-09-08): pages with no JSON-LD go to the
        // async LLM extractor; finalize ran first and gone-marked their live
        // rows until the LLM landed. Queueing for LLM must count as "seen".
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.test/products/schemaless' => \Illuminate\Support\Facades\Http::response('<html><body>No structured data here</body></html>', 200),
        ]);
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
        Queue::fake();
        [$website] = $this->site();
        $existing = ContentProduct::factory()->create([
            'website_id' => $website->id, 'name' => 'Schemaless Mist',
            'url' => 'https://shop.test/products/schemaless',
            'url_hash' => hash('sha256', 'https://shop.test/products/schemaless'),
            'last_seen_at' => now()->subDays(10),
        ]);
        $run = ContentProductRun::factory()->create([
            'website_id' => $website->id, 'trigger' => 'admin',
            'status' => ContentProductRun::STATUS_EXTRACTING, 'started_at' => now()->subMinute(),
        ]);

        (new \App\Jobs\Content\ExtractProductBatchJob($run->id, ['https://shop.test/products/schemaless']))->handle(
            app(\App\Services\Crawler\CrawlFetcher::class),
            app(\App\Services\Crawler\FirecrawlClient::class),
            app(\App\Services\Content\Catalog\ProductExtractor::class),
        );
        (new FinalizeProductCatalogJob($run->id))->handle();

        $this->assertSame(ContentProduct::STATUS_ACTIVE, $existing->refresh()->status,
            'a page queued for LLM extraction was seen — never gone-marked');
    }

    public function test_product_path_heuristic(): void
    {
        $svc = ProductCatalogService::class;
        $this->assertTrue($svc::isProductPath('https://x.com/products/blue-shoe'));
        $this->assertTrue($svc::isProductPath('https://x.com/p/123'));
        $this->assertFalse($svc::isProductPath('https://x.com/products/'), 'bare collection index');
        $this->assertFalse($svc::isProductPath('https://x.com/blog/why-products-matter/extra'));
    }
}
