<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentPlanKeyword;
use App\Models\ContentProduct;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Catalog\ProductExtractor;
use App\Services\Content\Catalog\TopicProductMatcher;
use App\Services\Content\ContentTopicPlanner;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Strict Product Mode: catalog-grounded topic planning. */
class ProductAwarePlanningTest extends TestCase
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
    private function shop(array $planAttrs = []): array
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'Skincare store selling serums and creams.',
            'offerings' => ['sell' => ['vitamin c serums'], 'dont_sell' => []],
            // classified → filterRelevant() skipped; matcher gate must still fire
            'keywords_classified_at' => now(),
        ] + $planAttrs);

        return [$website, $plan];
    }

    private function product(string $websiteId, string $name, string $url): ContentProduct
    {
        return ContentProduct::factory()->create([
            'website_id' => $websiteId, 'name' => $name,
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'terms' => ProductExtractor::terms($name, null, null),
        ]);
    }

    /** LLM stub returning fixed ideation candidates + capturing the prompt. */
    private function stubLlm(array $topics, ?string &$capturedPrompt = null): LlmClient
    {
        return new class($topics, $capturedPrompt) implements LlmClient
        {
            public function __construct(private array $topics, private ?string &$captured)
            {
            }

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
                $this->captured = implode("\n", array_column($messages, 'content'));

                return ['topics' => $this->topics];
            }
        };
    }

    public function test_strict_ideation_gets_catalog_block_and_gates_offcatalog_topics(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => 'strict']);
        $vc = $this->product($website->id, 'Vitamin C Serum 30ml', 'https://s.test/products/vc');
        $this->product($website->id, 'Retinol Night Cream', 'https://s.test/products/retinol');

        $captured = null;
        $llm = $this->stubLlm([
            ['title' => 'Best Vitamin C Serum Routine', 'target_keyword' => 'vitamin c serum routine',
                'secondary_keywords' => [], 'intent' => 'informational', 'source' => 'llm',
                'product_urls' => ['https://s.test/products/vc', 'https://s.test/products/NOT-REAL']],
            ['title' => 'Guide To Mountain Bikes', 'target_keyword' => 'mountain bikes',
                'secondary_keywords' => [], 'intent' => 'informational', 'source' => 'llm'],
        ], $captured);

        $created = (new ContentTopicPlanner($llm))->plan($plan, 5);

        // Catalog block reached the prompt.
        $this->assertStringContainsString('THEIR PRODUCT CATALOG', (string) $captured);
        $this->assertStringContainsString('Vitamin C Serum 30ml', (string) $captured);
        // Off-catalog topic (mountain bikes) dropped; on-catalog kept.
        $titles = collect($created)->pluck('title');
        $this->assertTrue($titles->contains('Best Vitamin C Serum Routine'));
        $this->assertFalse($titles->contains('Guide To Mountain Bikes'));
        // Pivot: cited-and-real URL featured; fake URL ignored; matcher adds mentions.
        $topic = ContentTopic::query()->where('title', 'Best Vitamin C Serum Routine')->firstOrFail();
        $roles = $topic->products()->get()->mapWithKeys(fn ($p) => [$p->id => $p->pivot->role]);
        $this->assertSame('featured', $roles[$vc->id] ?? null);
        $this->assertCount(1, $roles->filter(fn ($r) => $r === 'featured'));
    }

    public function test_null_mode_planning_is_untouched(): void
    {
        [, $plan] = $this->shop(['product_mode' => null]);

        $captured = null;
        $llm = $this->stubLlm([
            ['title' => 'Guide To Mountain Bikes', 'target_keyword' => 'mountain bikes',
                'secondary_keywords' => [], 'intent' => 'informational', 'source' => 'llm'],
        ], $captured);

        $created = (new ContentTopicPlanner($llm))->plan($plan, 5);

        $this->assertStringNotContainsString('THEIR PRODUCT CATALOG', (string) $captured);
        $this->assertCount(1, $created, 'no matcher gate outside strict mode');
    }

    public function test_confirmed_terms_attach_products_but_never_drop(): void
    {
        [$website, $plan] = $this->shop(['product_mode' => 'strict']);
        $this->product($website->id, 'Vitamin C Serum 30ml', 'https://s.test/products/vc');
        ContentProduct::factory()->create(['website_id' => $website->id]); // noise
        // Human-confirmed keyword with zero catalog overlap — must still materialize.
        ContentPlanKeyword::query()->create([
            'plan_id' => $plan->id, 'keyword' => 'unrelated pottery classes',
            'keyword_hash' => hash('sha256', 'unrelated pottery classes'),
            'type' => ContentPlanKeyword::TYPE_CONFIRMED, 'search_volume' => 100,
        ]);
        ContentPlanKeyword::query()->create([
            'plan_id' => $plan->id, 'keyword' => 'vitamin c serum benefits',
            'keyword_hash' => hash('sha256', 'vitamin c serum benefits'),
            'type' => ContentPlanKeyword::TYPE_CONFIRMED, 'search_volume' => 900,
        ]);

        $created = (new ContentTopicPlanner($this->stubLlm([])))->plan($plan, 5);

        $byKeyword = collect($created)->keyBy('target_keyword');
        $this->assertArrayHasKey('unrelated pottery classes', $byKeyword->all(), 'human choice never dropped');
        $this->assertTrue($byKeyword['vitamin c serum benefits']->products()->exists());
        $this->assertFalse($byKeyword['unrelated pottery classes']->products()->exists());
    }

    public function test_matcher_is_arabic_safe(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        ContentProduct::factory()->create([
            'website_id' => $website->id, 'name' => 'سيروم فيتامين سي',
            'terms' => ProductExtractor::terms('سيروم فيتامين سي', null, null),
        ]);

        // hamza-variant query still matches the folded terms
        $this->assertTrue(app(TopicProductMatcher::class)->matches($website->id, 'أفضل سيروم فيتامين سي'));
        $this->assertFalse(app(TopicProductMatcher::class)->matches($website->id, 'قطع غيار سيارات'));
    }
}
