<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublicOnboarding;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Catalog\CatalogBrandValidator;
use App\Services\Llm\LlmClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two ways a strict-mode promise was broken for real clients on 2026-09-25.
 *
 *  1. The step-7 card — the ONLY place an e-commerce client is asked
 *     strict-vs-normal — is gated on `plan.site_type`, which is written when
 *     the plan row is first saved at step 2. Detection is async, so a client
 *     who moved faster than it left the row typeless and was never asked:
 *     five of nine active e-commerce clients (blisfragrance.com among them).
 *  2. Strict topics could still NAME outside brands, because the rival list
 *     only knows competitor shops.
 */
class StrictProductChoiceReachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    // ── 1. the client always gets asked ────────────────────────────────

    public function test_a_site_type_detected_late_still_reaches_the_step_seven_card(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        // The plan was saved BEFORE detection finished: no type on the row.
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'site_type' => null,
            'product_mode' => null,
            'business_description' => 'An online perfume shop.',
        ]);

        Livewire::test(PublicOnboarding::class)
            ->set('websiteId', $website->id)
            ->set('draftPlanId', $plan->id)
            // Detection landed while they were on the keyword step.
            ->set('siteType', 'ecommerce_reseller')
            ->set('siteTypeSource', 'auto')
            ->call('toFirstArticles')
            ->assertSet('wizardStep', 7);

        $this->assertSame('ecommerce_reseller', $plan->fresh()->site_type,
            'the detected type must reach the plan row, or the card never shows');
    }

    public function test_the_card_then_renders_and_defaults_to_strict(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'site_type' => null,
            'product_mode' => null,
            'business_description' => 'An online perfume shop.',
        ]);

        $component = Livewire::test(PublicOnboarding::class)
            ->set('websiteId', $website->id)
            ->set('draftPlanId', $plan->id)
            ->set('siteType', 'brand')
            ->set('siteTypeSource', 'auto')
            ->call('toFirstArticles');

        $this->assertTrue($component->viewData('wizard')['requiresProductChoice']);
        $component->assertSet('productModeChoice', ContentPlan::PRODUCT_MODE_STRICT);
    }

    /** A type the CLIENT picked is never overwritten by detection. */
    public function test_a_client_chosen_site_type_survives(): void
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'site_type' => 'local_service',
            'site_type_source' => 'user',
            'business_description' => 'A clinic.',
        ]);

        Livewire::test(PublicOnboarding::class)
            ->set('websiteId', $website->id)
            ->set('draftPlanId', $plan->id)
            ->set('siteType', 'ecommerce_reseller')
            ->call('toFirstArticles');

        $this->assertSame('local_service', $plan->fresh()->site_type);
    }

    // ── 2. strict topics never name outside brands ─────────────────────

    /** @param array<string, mixed>|null $answer */
    private function validator(?array $answer): CatalogBrandValidator
    {
        $llm = new class($answer) implements LlmClient
        {
            public function __construct(private ?array $answer) {}

            public function complete(array $messages, array $options = []): array
            {
                return ['ok' => true, 'content' => ''];
            }

            public function completeJson(array $messages, array $options = []): ?array
            {
                return $this->answer;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function completeWithTools(array $messages, array $tools, callable $dispatcher, array $options = []): array
            {
                return ['ok' => true, 'content' => ''];
            }
        };

        return new CatalogBrandValidator($llm);
    }

    private function catalog(): string
    {
        $website = Website::factory()->for(User::factory())->create();
        foreach (['Nishane Hundred Silent Ways 100ml', 'Creed Aventus 50ml', 'Essential Parfums Bois Imperial'] as $name) {
            ContentProduct::factory()->create(['website_id' => $website->id, 'name' => $name]);
        }

        return (string) $website->id;
    }

    public function test_it_reports_titles_naming_products_the_shop_does_not_sell(): void
    {
        $titles = ['Creed Aventus: Is It Worth the Hype?', 'Baccarat Rouge 540 Alternatives'];

        $flagged = $this->validator(['off_catalog' => [1]])->offCatalogIndexes($this->catalog(), $titles);

        $this->assertSame([1], $flagged);
    }

    /** An LLM outage must never stall a calendar, so an unusable answer keeps everything. */
    public function test_it_fails_open(): void
    {
        foreach ([null, ['nonsense' => true], ['off_catalog' => 'not-an-array']] as $answer) {
            $this->assertSame([], $this->validator($answer)->offCatalogIndexes($this->catalog(), ['A title']));
        }
    }

    /** A model flagging nearly the whole batch is broken, not strict. */
    public function test_an_over_flagging_answer_is_ignored(): void
    {
        $titles = ['One', 'Two', 'Three', 'Four'];

        $this->assertSame([], $this->validator(['off_catalog' => [0, 1, 2, 3]])->offCatalogIndexes($this->catalog(), $titles));
    }

    public function test_out_of_range_indexes_are_discarded(): void
    {
        $flagged = $this->validator(['off_catalog' => [0, 99, 'x']])->offCatalogIndexes($this->catalog(), ['Only one title']);

        $this->assertSame([0], $flagged);
    }

    /** No catalog yet → nothing to judge against; the planner gate covers it. */
    public function test_without_products_it_flags_nothing(): void
    {
        $website = Website::factory()->for(User::factory())->create();

        $this->assertSame([], $this->validator(['off_catalog' => [0]])->offCatalogIndexes((string) $website->id, ['Anything']));
    }
}
