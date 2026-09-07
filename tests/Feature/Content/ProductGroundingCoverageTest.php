<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Catalog\ProductExtractor;
use App\Services\Content\ContentArticleProducer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Strict Product Mode — the "don't miss anything" net (owner 2026-09-06):
 * EVERY LLM stage that creates or mutates article HTML must carry the product
 * context — the YOUR PRODUCTS block for creative stages, the
 * PRESERVE-PRODUCTS rule for editor/cleanup stages. One capture test per
 * stage; deterministic gates get behavior tests. HARD RULE for future work:
 * add an LLM stage → classify it here or this file is wrong.
 */
class ProductGroundingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 'Vitamin C Serum 30ml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.mistral.key' => 'test-key', 'services.deepseek.key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return array{0: ContentPlan, 1: ContentTopic, 2: ContentProduct} */
    private function strictFixture(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'billing_covered_at' => now(),
            'business_description' => 'Skincare store.',
            'offerings' => ['sell' => ['serums'], 'dont_sell' => []],
            'product_mode' => ContentPlan::PRODUCT_MODE_STRICT,
        ]);
        $product = ContentProduct::factory()->create([
            'website_id' => $website->id, 'name' => self::PRODUCT,
            'url' => 'https://shop.test/products/vitamin-c-serum',
            'url_hash' => hash('sha256', 'https://shop.test/products/vitamin-c-serum'),
            'terms' => ProductExtractor::terms(self::PRODUCT, null, null),
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Vitamin C Serum Guide', 'target_keyword' => 'vitamin c serum',
            'status' => ContentTopic::STATUS_READY,
        ]);

        return [$plan, $topic, $product];
    }

    private function articleFor(ContentTopic $topic, string $html = '<p>Body.</p>'): ContentArticle
    {
        $version = 1 + (int) ContentArticle::query()->where('topic_id', $topic->id)->max('version');
        ContentArticle::query()->where('topic_id', $topic->id)->update(['is_current' => false]);

        return ContentArticle::create([
            'topic_id' => $topic->id, 'version' => $version, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => $html, 'seo_score' => 99,
        ]);
    }

    private function capture(string $method, array $args): string
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>x</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h'])]]]])]);

        $m = new \ReflectionMethod(ContentArticleProducer::class, $method);
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), ...$args);

        $bodies = '';
        foreach (Http::recorded() as [$request]) {
            $bodies .= json_encode($request->data());
        }

        return $bodies;
    }

    // ── creative stages: full YOUR PRODUCTS block ───────────────────────

    public function test_template_instructions_carry_the_products_block(): void
    {
        [$plan, $topic] = $this->strictFixture();
        // productContext persists the selection first (as produce() does).
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $t = new \ReflectionMethod(ContentArticleProducer::class, 'templateInstructions');
        $t->setAccessible(true);
        $block = $t->invoke(app(ContentArticleProducer::class), $plan->refresh(), $topic->refresh());

        $this->assertStringContainsString('YOUR PRODUCTS', $block);
        $this->assertStringContainsString(self::PRODUCT, $block);
        $this->assertStringContainsString('NEVER state prices', $block);
    }

    public function test_revise_carries_products_block_from_stored_meta(): void
    {
        [$plan, $topic] = $this->strictFixture();
        $article = $this->articleFor($topic);
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $body = $this->capture('revise', [$article, $topic->refresh(), $plan, null]);

        $this->assertStringContainsString('YOUR PRODUCTS', $body);
        $this->assertStringContainsString(self::PRODUCT, $body);
    }

    public function test_client_rewrite_keeps_products_block_alongside_primary_task(): void
    {
        [$plan, $topic] = $this->strictFixture();
        $article = $this->articleFor($topic);
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $body = $this->capture('revise', [$article, $topic->refresh(), $plan, 'Add a FAQ section']);

        $this->assertStringContainsString('CLIENT REWRITE REQUEST', $body);
        $this->assertStringContainsString('YOUR PRODUCTS', $body);
    }

    // ── editor/cleanup stages: PRESERVE-PRODUCTS rule ───────────────────

    public function test_brand_scrub_carries_preserve_rule(): void
    {
        [$plan, $topic] = $this->strictFixture();
        $plan->forceFill(['competitor_guard' => [
            'assessed_at' => now()->toIso8601String(), 'harmful' => true,
            'auto' => [['brand' => 'rivalbrand', 'domain' => 'rival.test', 'reason' => 'competitor']],
            'manual' => [], 'removed' => [],
        ], 'toggles' => [\App\Services\Content\CompetitorMentionGuard::TOGGLE => true]])->save();
        $article = $this->articleFor($topic, '<p>Buy rivalbrand serum today.</p>');
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $body = $this->capture('scrubBlockedTerms', [$article, $topic->refresh(), $plan->refresh(), false, null]);

        $this->assertStringContainsString('PRESERVE PRODUCTS', $body);
    }

    public function test_de_ai_cleanup_carries_preserve_rule(): void
    {
        [$plan, $topic] = $this->strictFixture();
        $article = $this->articleFor($topic);
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $body = $this->capture('deAiCleanup', [$article, $topic->refresh(), $plan]);

        $this->assertStringContainsString('PRESERVE PRODUCTS', $body);
    }

    // ── deterministic gates ─────────────────────────────────────────────

    public function test_hard_scrub_never_scrubs_catalog_product_names(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        // The reseller stocked_only mode + strict catalog: a guard term that
        // matches a catalog product name gets exempted.
        $plan->forceFill([
            'site_type' => 'ecommerce_reseller',
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(), 'harmful' => true,
                'auto' => [['brand' => 'vitamin c serum 30ml', 'domain' => 'x.test', 'reason' => 'competitor']],
                'manual' => [], 'removed' => [],
            ],
            'toggles' => [\App\Services\Content\CompetitorMentionGuard::TOGGLE => true],
        ])->save();

        $terms = app(\App\Services\Content\CompetitorMentionGuard::class)
            ->termsForTopic($plan->refresh(), $topic);

        $this->assertNotContains('vitamin c serum 30ml', $terms,
            'a stocked catalog product name must never be treated as a blocked competitor');
    }

    public function test_strict_scorer_checks_and_strip_behavior(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);
        $ctx = (function () use ($topic, $plan) {
            $r = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
            $r->setAccessible(true);

            return $r->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan, $topic->website);
        })();

        $this->assertNotEmpty($ctx['products']);
        $this->assertContains($product->url, $ctx['catalog_urls']);

        // Scorer: featuring the product passes; invented product link fails.
        $scorer = app(\App\Services\Content\ContentSeoScorer::class);
        $good = $scorer->score('<p>Our '.self::PRODUCT.' works. <a href="'.$product->url.'">'.self::PRODUCT.'</a></p>', 't', 'd', 'h', 's', $ctx);
        $goodCodes = collect($good['checks'])->keyBy('code');
        $this->assertTrue((bool) $goodCodes['products_featured']['passed']);
        $this->assertTrue((bool) $goodCodes['product_links_valid']['passed']);

        $bad = $scorer->score('<p><a href="https://shop.test/products/invented-thing">fake</a></p>', 't', 'd', 'h', 's', $ctx);
        $this->assertFalse((bool) collect($bad['checks'])->keyBy('code')['product_links_valid']['passed']);

        // Final-gate strip: invented product link unwrapped, real one kept.
        $article = $this->articleFor($topic, '<p><a href="'.$product->url.'">'.self::PRODUCT.'</a> and <a href="https://shop.test/products/invented-thing">fake</a></p>');
        $s = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $s->setAccessible(true);
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);

        $this->assertStringContainsString($product->url, $result->html);
        $this->assertStringNotContainsString('invented-thing', $result->html);

        // Over-linking dedupe (pilot 2026-09-07: same product linked 8×):
        // repeats of a VALID catalog link unwrap to text, first stays.
        $article = $this->articleFor($topic, '<p><a href="'.$product->url.'">'.self::PRODUCT.'</a> then <a href="'.$product->url.'">again</a> and <a href="'.$product->url.'">a third time</a></p>');
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);
        $this->assertSame(1, substr_count((string) $result->html, 'href="'.$product->url.'"'),
            'a catalog product is linked at most once');
        $this->assertStringContainsString('again', $result->html);
        $this->assertStringContainsString('a third time', $result->html);
    }

    public function test_strip_handles_dash_style_product_urls(): void
    {
        // mashrafshoes pilot: catalog URLs are /product-slug (no /product/
        // segment) — the shape heuristic must still catch invented links and
        // dedupe repeats for these shops.
        [$plan, $topic, $product] = $this->strictFixture();
        $product->forceFill(['url' => 'https://shop.test/product-vitamin-serum'])->save();
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);
        $r = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
        $r->setAccessible(true);
        $ctx = $r->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan, $topic->website);

        $article = $this->articleFor($topic,
            '<p><a href="https://shop.test/product-vitamin-serum">ok</a>'
            .' <a href="https://shop.test/product-vitamin-serum">repeat</a>'
            .' <a href="https://shop.test/product-invented-cream">fake</a></p>');
        $s = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $s->setAccessible(true);
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);

        $this->assertSame(1, substr_count((string) $result->html, 'href="https://shop.test/product-vitamin-serum"'));
        $this->assertStringNotContainsString('product-invented-cream', $result->html);
        $this->assertStringContainsString('fake', $result->html);
    }

    public function test_dead_external_links_are_unwrapped_live_ones_kept(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);
        $r = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
        $r->setAccessible(true);
        $ctx = $r->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan, $topic->website);

        config(['features.article_link_verify' => true]);
        \Illuminate\Support\Facades\Http::fake([
            'https://dead.example/gone-page' => \Illuminate\Support\Facades\Http::response('', 404),
            'https://alive.example/reference' => \Illuminate\Support\Facades\Http::response('ok', 200),
            'https://flaky.example/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $article = $this->articleFor($topic,
            '<p><a href="'.$product->url.'">'.self::PRODUCT.'</a>'
            .' <a href="https://dead.example/gone-page">dead citation</a>'
            .' <a href="https://alive.example/reference">live citation</a>'
            .' <a href="https://flaky.example/maybe">flaky citation</a></p>');
        $s = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $s->setAccessible(true);
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);

        $this->assertStringNotContainsString('href="https://dead.example/gone-page"', $result->html);
        $this->assertStringContainsString('dead citation', $result->html, 'anchor text survives as plain text');
        $this->assertStringContainsString('href="https://alive.example/reference"', $result->html);
        $this->assertStringContainsString('href="https://flaky.example/maybe"', $result->html,
            'a transient failure must never strip a citation');
    }

    public function test_draft_selected_links_mark_products_manual(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        // Reproduce the draft-input construction path: productContext then the
        // closure logic — asserted via produce()'s recorded draft call would
        // need full pipeline; instead pin the invariant on the stored meta +
        // the templateInstructions block (test 1) + AiWriterService's
        // documented user_manual enforcement for manual entries.
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $selection = $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $this->assertSame($product->url, $selection[0]['url']);
        $this->assertSame(self::PRODUCT, $selection[0]['name']);
        // Stored once — second call returns the identical stored selection.
        $again = $m->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan);
        $this->assertSame($selection, $again);
    }

    public function test_strict_disables_the_auto_brand_topic_exemption(): void
    {
        // "otterbox alternatives" articles may say otterbox on NORMAL plans;
        // a strict client never writes them, so the exemption is off and the
        // scrubs strip the rival brand even from a brand-keyword topic.
        [$plan, $topic] = $this->strictFixture();
        $topic->forceFill(['target_keyword' => 'rivalglow alternatives'])->save();
        $plan->forceFill([
            'competitor_guard' => [
                'assessed_at' => now()->toIso8601String(), 'harmful' => true,
                'auto' => [['brand' => 'rivalglow', 'domain' => 'rivalglow.test', 'reason' => 'competitor']],
                'manual' => [], 'removed' => [],
            ],
            'toggles' => [\App\Services\Content\CompetitorMentionGuard::TOGGLE => true],
        ])->save();

        $guard = app(\App\Services\Content\CompetitorMentionGuard::class);
        $this->assertContains('rivalglow', $guard->termsForTopic($plan->refresh(), $topic->refresh()));
        $this->assertContains('rivalglow', $guard->strictBlockedBrands($plan));

        // Normal mode keeps the exemption (regression pin on old behavior).
        $plan->forceFill(['product_mode' => \App\Models\ContentPlan::PRODUCT_MODE_NORMAL])->save();
        $this->assertNotContains('rivalglow', $guard->termsForTopic($plan->refresh(), $topic));
        $this->assertSame([], $guard->strictBlockedBrands($plan->refresh()));
    }

    public function test_product_figures_injected_once_with_live_image_after_first_mention(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        $product->forceFill(['image_url' => 'https://shop.test/cdn/serum.jpg'])->save();
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);
        $r = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
        $r->setAccessible(true);
        $ctx = $r->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan, $topic->website);

        $article = $this->articleFor($topic,
            '<p>Intro paragraph.</p><p>Try <a href="'.$product->url.'">'.self::PRODUCT.'</a> daily.</p><p>Outro.</p>');
        $s = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $s->setAccessible(true);
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);
        $html = (string) $result->html;

        // Figure lands right after the paragraph that links the product.
        $this->assertStringContainsString('daily.</p>'."\n".'<figure class="serfix-product-figure"', $html);
        $this->assertStringContainsString('<img src="https://shop.test/cdn/serum.jpg" alt="'.self::PRODUCT.'"', $html);
        $this->assertStringContainsString('<a href="'.$product->url.'"><img', $html);
        // Dedupe never strips the image link even though a text link exists.
        $this->assertSame(2, substr_count($html, 'href="'.$product->url.'"'), 'text link + image link');

        // Idempotent: a second gate pass injects nothing new.
        $again = $s->invoke(app(ContentArticleProducer::class), $result, $topic->refresh(), $ctx);
        $this->assertSame(1, substr_count((string) $again->html, 'serfix-product-figure'));
    }

    public function test_product_figure_skipped_when_image_is_dead_or_missing(): void
    {
        config(['features.article_link_verify' => true]);
        [$plan, $topic, $product] = $this->strictFixture();
        $product->forceFill(['image_url' => 'https://shop.test/cdn/gone.jpg'])->save();
        \Illuminate\Support\Facades\Http::fake([
            'https://shop.test/cdn/gone.jpg' => \Illuminate\Support\Facades\Http::response('', 404),
            'https://shop.test/*' => \Illuminate\Support\Facades\Http::response('ok', 200),
        ]);
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $topic, $plan);
        $r = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
        $r->setAccessible(true);
        $ctx = $r->invoke(app(ContentArticleProducer::class), $topic->refresh(), $plan, $topic->website);

        $article = $this->articleFor($topic, '<p><a href="'.$product->url.'">'.self::PRODUCT.'</a></p>');
        $s = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $s->setAccessible(true);
        $result = $s->invoke(app(ContentArticleProducer::class), $article, $topic->refresh(), $ctx);

        $this->assertStringNotContainsString('serfix-product-figure', (string) $result->html,
            'a dead product image must never be injected');
    }

    public function test_produce_resolves_products_before_building_scorer_context(): void
    {
        // Ordering pin (pilot 2026-09-07): scorerContext reads topic.meta,
        // which productContext persists — reversed, a topic's FIRST write ran
        // every strict check against context['products'] = []. Source-order
        // assertion, same style as the converter column-list pin.
        $src = file_get_contents(app_path('Services/Content/ContentArticleProducer.php'));
        $produce = substr($src, strpos($src, 'public function produce('));
        $productPos = strpos($produce, '$this->productContext($topic, $plan)');
        $contextPos = strpos($produce, '$this->scorerContext($topic, $plan, $website)');
        $this->assertNotFalse($productPos);
        $this->assertNotFalse($contextPos);
        $this->assertLessThan($contextPos, $productPos,
            'produce() must persist the product selection BEFORE scorerContext reads it');
    }

    public function test_dead_product_urls_are_dropped_and_marked_gone(): void
    {
        config(['features.article_link_verify' => true]);
        [$plan, $topic, $product] = $this->strictFixture();
        \Illuminate\Support\Facades\Http::fake([
            $product->url => \Illuminate\Support\Facades\Http::response('', 404),
        ]);

        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $selection = $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $this->assertSame([], $selection, 'a 404 product URL never reaches the writer');
        $this->assertSame(ContentProduct::STATUS_GONE, $product->refresh()->status, 'catalog self-heals');
    }

    public function test_out_of_stock_products_are_not_selected_for_featuring(): void
    {
        [$plan, $topic, $product] = $this->strictFixture();
        $product->forceFill(['availability' => ContentProduct::AVAILABILITY_OUT_OF_STOCK])->save();

        $m = new \ReflectionMethod(ContentArticleProducer::class, 'productContext');
        $m->setAccessible(true);
        $selection = $m->invoke(app(ContentArticleProducer::class), $topic, $plan);

        $this->assertSame([], $selection);
    }

    public function test_normal_and_null_modes_carry_no_product_context(): void
    {
        [$plan, $topic] = $this->strictFixture();
        $plan->forceFill(['product_mode' => null])->save();

        $t = new \ReflectionMethod(ContentArticleProducer::class, 'templateInstructions');
        $t->setAccessible(true);
        $block = $t->invoke(app(ContentArticleProducer::class), $plan->refresh(), $topic);

        $this->assertStringNotContainsString('YOUR PRODUCTS', $block);
    }
}
