<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\OfferQueryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferQueryGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::query()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'business_description' => 'Luxury gourmand perfumes you can layer.',
            'offerings' => ['sell' => ['Vanilla eau de parfum', 'Fragrance discovery sets'], 'dont_sell' => []],
            'site_type' => 'brand',
            'audience' => 'Fragrance lovers who layer scents.',
        ], $attrs));
    }

    public function test_llm_candidates_carry_offer_lineage_and_snap_to_real_offers(): void
    {
        config(['services.mistral.key' => 'test-key']);
        $plan = $this->makePlan();

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['queries' => [
                    ['query' => 'sweet vanilla eau de parfum', 'offer' => 'Vanilla eau de parfum', 'intent' => 'commercial'],
                    ['query' => 'best fragrance sampler for women', 'offer' => 'Discovery sets', 'intent' => 'commercial'],
                    ['query' => 'how to layer perfume', 'offer' => 'A made-up offer', 'intent' => 'informational'],
                ]])]]],
                'usage' => ['total_tokens' => 200],
            ]),
        ]);

        $candidates = app(OfferQueryGenerator::class)->candidates($plan);

        $this->assertCount(3, $candidates);
        $this->assertSame('Vanilla eau de parfum', $candidates[0]['offer']);
        // "Discovery sets" snaps to the real "Fragrance discovery sets" offer.
        $this->assertSame('Fragrance discovery sets', $candidates[1]['offer']);
        // An invented offer snaps to a real one — never leaks into lineage.
        $this->assertContains($candidates[2]['offer'], ['Vanilla eau de parfum', 'Fragrance discovery sets']);
    }

    public function test_mechanical_fallback_fills_type_shapes_when_no_llm(): void
    {
        config(['services.mistral.key' => null]);
        $plan = $this->makePlan();

        $candidates = app(OfferQueryGenerator::class)->candidates($plan);

        $this->assertNotEmpty($candidates, 'fallback must never be empty for a plan with offerings');
        // Brand-type shape "how to choose {offer}" filled with the offer head.
        $queries = array_column($candidates, 'query');
        $this->assertContains('how to choose vanilla eau de parfum', $queries);
        foreach ($candidates as $c) {
            $this->assertContains($c['offer'], ['Vanilla eau de parfum', 'Fragrance discovery sets']);
        }
    }

    public function test_no_offerings_means_no_candidates(): void
    {
        $plan = $this->makePlan(['offerings' => ['sell' => [], 'dont_sell' => []]]);

        $this->assertSame([], app(OfferQueryGenerator::class)->candidates($plan));
    }

    public function test_attribute_maps_expanded_keywords_to_offers_by_token_overlap(): void
    {
        $generator = app(OfferQueryGenerator::class);
        $sell = ['Vanilla eau de parfum', 'Fragrance discovery sets'];
        $candidates = [
            ['query' => 'luxury perfume for layering', 'offer' => 'Vanilla eau de parfum', 'intent' => 'commercial'],
        ];

        $map = $generator->attribute(
            ['sweet vanilla perfume', 'perfume layering guide', 'best running shoes'],
            $candidates,
            $sell
        );

        $this->assertSame('Vanilla eau de parfum', $map['sweet vanilla perfume']);
        // Attributed via the candidate query's tokens (layering → vanilla offer).
        $this->assertSame('Vanilla eau de parfum', $map['perfume layering guide']);
        // Off-topic keyword gets NO confident lineage.
        $this->assertArrayNotHasKey('best running shoes', $map);
    }
}
