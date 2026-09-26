<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentTopic;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Catalog\CatalogBrandValidator;
use App\Services\Content\Catalog\ProductExtractor;
use App\Services\Content\TopicComposer;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Client-authored topics ("write about this instead").
 *
 * The point of the feature is that a client can steer the calendar; the point
 * of these tests is that steering it is not a way around the rules the planner
 * follows — rival brands, off-catalog ideas, duplicates, the monthly cap.
 */
class TopicComposerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return array{Website, ContentPlan} */
    private function shop(array $planAttrs = []): array
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'Skincare store selling serums and creams.',
            'offerings' => ['sell' => ['vitamin c serums'], 'dont_sell' => []],
            'articles_per_week' => 3,
            'keywords_classified_at' => now(),
        ] + $planAttrs);

        return [$website, $plan];
    }

    /** Composer wired to an LLM that returns exactly these candidates. */
    private function composer(array $topics): TopicComposer
    {
        $llm = new class($topics) implements LlmClient
        {
            public function __construct(private array $topics) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function complete(array $messages, array $options = []): array
            {
                return ['ok' => true, 'content' => '', 'model' => 'stub', 'usage' => ['prompt' => 0, 'completion' => 0, 'total' => 0]];
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return $this->complete($messages, $options);
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                return ['topics' => $this->topics];
            }
        };
        $this->app->instance(LlmClient::class, $llm);

        return $this->app->make(TopicComposer::class);
    }

    private function metric(string $keyword, int $volume, int $difficulty, string $country = 'us'): void
    {
        KeywordMetric::create([
            'keyword' => $keyword,
            'keyword_hash' => KeywordMetric::hashKeyword($keyword),
            'country' => $country,
            'data_source' => 'test',
            'search_volume' => $volume,
            'keyword_difficulty' => $difficulty,
            'fetched_at' => now(),
            'expires_at' => now()->addDays(20),
        ]);
    }

    private function product(string $websiteId, string $name, string $url): ContentProduct
    {
        return ContentProduct::factory()->create([
            'website_id' => $websiteId, 'name' => $name, 'url' => $url,
            'url_hash' => hash('sha256', $url),
            'terms' => ProductExtractor::terms($name, null, null),
        ]);
    }

    public function test_an_idea_comes_back_as_seo_shaped_topics(): void
    {
        [, $plan] = $this->shop();
        $this->metric('vitamin c serum routine', 880, 12);

        $out = $this->composer([[
            'title' => 'How to Build a Vitamin C Serum Routine',
            'target_keyword' => 'vitamin c serum routine',
            'secondary_keywords' => ['when to apply vitamin c', 'vitamin c and spf', 'serum layering order', 'morning skincare order'],
            'intent' => 'informational',
        ]])->suggest($plan, 'something about using vitamin c');

        $this->assertTrue($out['ok']);
        $this->assertCount(1, $out['suggestions']);
        $s = $out['suggestions'][0];
        $this->assertSame('vitamin c serum routine', $s['target_keyword']);
        $this->assertSame('informational', $s['intent']);
        $this->assertSame(880, $s['volume']);
        $this->assertGreaterThanOrEqual(4, count($s['secondary_keywords']));
    }

    public function test_suggestions_rank_by_winnability_not_volume(): void
    {
        [, $plan] = $this->shop();
        // The big term is unwinnable for a no-authority site; the small one is not.
        $this->metric('best serum', 40000, 78);
        $this->metric('vitamin c serum for oily skin', 300, 8);

        $out = $this->composer([
            ['title' => 'The Best Serum, Ranked', 'target_keyword' => 'best serum', 'intent' => 'commercial'],
            ['title' => 'Vitamin C Serum for Oily Skin', 'target_keyword' => 'vitamin c serum for oily skin', 'intent' => 'commercial'],
        ])->suggest($plan, 'serums');

        $this->assertSame('vitamin c serum for oily skin', $out['suggestions'][0]['target_keyword']);
        $this->assertSame(300, $out['suggestions'][0]['volume']);
    }

    public function test_an_unknown_keyword_reports_no_volume_rather_than_a_number(): void
    {
        [, $plan] = $this->shop();

        $out = $this->composer([[
            'title' => 'A Brand New Angle on Serums', 'target_keyword' => 'never looked up phrase',
        ]])->suggest($plan, 'anything');

        $this->assertNull($out['suggestions'][0]['volume']);
    }

    public function test_a_duplicate_keyword_or_near_identical_title_is_refused(): void
    {
        [$website, $plan] = $this->shop();
        ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'How to Build a Vitamin C Serum Routine',
            'target_keyword' => 'vitamin c serum routine',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay()->toDateString(),
        ]);

        $out = $this->composer([
            ['title' => 'A Totally Different Title', 'target_keyword' => 'Vitamin C Serum Routine'],
            ['title' => 'How to Build a Vitamin C Serum Routine', 'target_keyword' => 'another keyword entirely'],
        ])->suggest($plan, 'vitamin c');

        $this->assertFalse($out['ok']);
        $this->assertSame([], $out['suggestions']);
    }

    public function test_strict_mode_drops_an_idea_the_catalog_cannot_support(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        $out = $this->composer([
            ['title' => 'Best Dog Harnesses for Winter', 'target_keyword' => 'dog harness winter'],
        ])->suggest($plan, 'dog stuff');

        $this->assertFalse($out['ok']);
        $this->assertSame('not_in_catalog', $out['reason']);
    }

    public function test_strict_mode_drops_a_rival_brand_idea_even_when_the_client_asks_for_it(): void
    {
        [$website, $plan] = $this->shop([
            'product_mode' => ContentPlan::PRODUCT_MODE_STRICT,
            'competitor_guard' => ['manual' => ['ordinary']],
        ]);
        $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        $out = $this->composer([
            ['title' => 'Our Vitamin C Serum vs Ordinary', 'target_keyword' => 'ordinary vitamin c serum'],
        ])->suggest($plan, 'compare us to the ordinary');

        $this->assertSame([], $out['suggestions']);
    }

    public function test_a_created_strict_topic_is_grounded_in_the_clients_own_products(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        $product = $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        $composer = $this->composer([[
            'title' => 'How to Use a Vitamin C Brightening Serum',
            'target_keyword' => 'vitamin c brightening serum',
            'product_urls' => ['https://shop.test/p/vitamin-c-serum'],
        ]]);
        $out = $composer->suggest($plan, 'our vitamin c serum');
        $topic = $composer->create($plan, $out['suggestions'][0]);

        $this->assertNotNull($topic);
        $this->assertSame(TopicComposer::SOURCE, $topic->source);
        $this->assertSame(ContentTopic::STATUS_APPROVED, $topic->status);
        $this->assertNotNull($topic->scheduled_for);
        $this->assertTrue($topic->products()->where('content_products.id', $product->id)->exists());
    }

    public function test_a_tampered_choice_is_refused_at_creation(): void
    {
        [$website, $plan] = $this->shop([
            'product_mode' => ContentPlan::PRODUCT_MODE_STRICT,
            'competitor_guard' => ['manual' => ['ordinary']],
        ]);
        $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        // Never went through suggest(); posted straight at create().
        $topic = $this->composer([])->create($plan, [
            'title' => 'Ordinary Vitamin C Serum Review',
            'target_keyword' => 'ordinary vitamin c serum',
        ]);

        $this->assertNull($topic);
        $this->assertSame(0, ContentTopic::query()->where('plan_id', $plan->id)->count());
    }

    public function test_replacing_keeps_the_slot_and_skips_the_old_topic(): void
    {
        [$website, $plan] = $this->shop();
        $old = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Something They Did Not Want', 'target_keyword' => 'unwanted topic',
            'status' => ContentTopic::STATUS_APPROVED, 'position' => 2,
            'scheduled_for' => now()->addDays(4)->toDateString(),
        ]);

        $composer = $this->composer([[
            'title' => 'What They Actually Want', 'target_keyword' => 'wanted topic',
        ]]);
        $out = $composer->suggest($plan, 'write this instead');
        $new = $composer->create($plan, $out['suggestions'][0], $old);

        $this->assertNotNull($new);
        $this->assertSame($old->scheduled_for->toDateString(), $new->scheduled_for->toDateString());
        $this->assertSame(2, (int) $new->position);
        $this->assertSame(ContentTopic::STATUS_SKIPPED, $old->fresh()->status);
        $this->assertSame($new->id, $old->fresh()->meta['replaced_by'] ?? null);
    }

    public function test_the_replaced_topic_is_kept_not_deleted(): void
    {
        [$website, $plan] = $this->shop();
        $old = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Old', 'target_keyword' => 'old keyword',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay()->toDateString(),
        ]);

        $composer = $this->composer([['title' => 'New Idea Entirely', 'target_keyword' => 'new keyword']]);
        $composer->create($plan, $composer->suggest($plan, 'write about something new')['suggestions'][0], $old);

        // Hard-deleting a topic would reset the month's article count.
        $this->assertNotNull($old->fresh());
    }

    public function test_adding_is_refused_once_the_month_has_no_free_publish_day(): void
    {
        [$website, $plan] = $this->shop();
        $composer = $this->composer([['title' => 'One More Idea', 'target_keyword' => 'one more idea']]);

        // Occupy every date the planner would offer.
        foreach ($composer->availableDates($plan) as $i => $date) {
            ContentTopic::create([
                'plan_id' => $plan->id, 'website_id' => $website->id,
                'title' => 'Taken '.$i, 'target_keyword' => 'taken keyword '.$i,
                'status' => ContentTopic::STATUS_APPROVED,
                'scheduled_for' => $date,
            ]);
        }

        $this->assertSame([], $composer->availableDates($plan));
        $this->assertNull($composer->create($plan, ['title' => 'One More Idea', 'target_keyword' => 'one more idea']));
    }

    public function test_picking_a_suggestion_does_not_pay_for_the_brand_check_twice(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        $validator = \Mockery::mock(CatalogBrandValidator::class);
        $validator->shouldReceive('offCatalogIndexes')->once()->andReturn([]);
        $this->app->instance(CatalogBrandValidator::class, $validator);

        $composer = $this->composer([[
            'title' => 'How to Use a Vitamin C Brightening Serum',
            'target_keyword' => 'vitamin c brightening serum',
        ]]);
        // Asking the model again could also flip its verdict on a title the
        // client was just offered.
        $composer->create($plan, $composer->suggest($plan, 'our serum')['suggestions'][0]);
    }

    public function test_an_off_catalog_title_is_dropped_by_the_brand_check(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => ContentPlan::PRODUCT_MODE_STRICT]);
        $this->product($website->id, 'Vitamin C Brightening Serum', 'https://shop.test/p/vitamin-c-serum');

        $validator = \Mockery::mock(CatalogBrandValidator::class);
        $validator->shouldReceive('offCatalogIndexes')->andReturn([0]);
        $this->app->instance(CatalogBrandValidator::class, $validator);

        $out = $this->composer([[
            'title' => 'Vitamin C Serum vs A Brand We Do Not Stock',
            'target_keyword' => 'vitamin c serum comparison',
        ]])->suggest($plan, 'compare serums');

        $this->assertSame([], $out['suggestions']);
    }
}
