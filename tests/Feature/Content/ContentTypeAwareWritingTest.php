<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F: the writer's template instructions adapt to the site type
 * (voice, CTA framing, YMYL care) and a null type changes NOTHING.
 */
class ContentTypeAwareWritingTest extends TestCase
{
    use RefreshDatabase;

    private function instructionsFor(array $planAttrs, array $topicAttrs = []): string
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'A test business.',
        ], $planAttrs));
        $topic = ContentTopic::factory()->for($plan, 'plan')->create(array_merge([
            'website_id' => $website->id,
            'target_keyword' => 'test keyword',
            'status' => ContentTopic::STATUS_APPROVED,
        ], $topicAttrs));

        $producer = app(ContentArticleProducer::class);
        $method = new \ReflectionMethod($producer, 'templateInstructions');

        return (string) $method->invoke($producer, $plan, $topic);
    }

    public function test_null_site_type_adds_no_type_rules(): void
    {
        $out = $this->instructionsFor([]);

        $this->assertStringNotContainsString('VOICE:', $out);
        $this->assertStringNotContainsString('CARE:', $out);
        $this->assertStringNotContainsString('AUDIENCE:', $out);
    }

    public function test_brand_type_gets_brand_voice_and_product_cta_framing(): void
    {
        $out = $this->instructionsFor([
            'site_type' => 'brand',
            'audience' => 'Fragrance lovers who layer scents.',
            'toggles' => ['cta_enabled' => true],
            'cta_url' => 'https://example.test/shop',
        ]);

        $this->assertStringContainsString('VOICE: write in a confident first-person-plural brand voice', $out);
        $this->assertStringContainsString('AUDIENCE: write for Fragrance lovers who layer scents.', $out);
        $this->assertStringContainsString('explore the relevant product or collection', $out);
    }

    public function test_local_service_gets_contact_cta_and_friendly_voice(): void
    {
        $out = $this->instructionsFor([
            'site_type' => 'local_service',
            'toggles' => ['cta_enabled' => true],
            'cta_url' => 'https://example.test/quote',
        ]);

        $this->assertStringContainsString('warm, plain-spoken professional voice', $out);
        $this->assertStringContainsString('request a quote or book a visit', $out);
    }

    public function test_b2b_type_gets_ymyl_care_rule(): void
    {
        $out = $this->instructionsFor(['site_type' => 'b2b_services']);

        $this->assertStringContainsString('CARE:', $out);
        $this->assertStringContainsString('consulting a qualified professional', $out);
    }

    public function test_classifier_ymyl_flag_adds_care_rule_regardless_of_type(): void
    {
        // A supplements brand: 'brand' doesn't set ymyl_care, the flag does.
        $out = $this->instructionsFor(['site_type' => 'brand', 'ymyl' => true]);
        $this->assertStringContainsString('CARE:', $out);

        // Even a type-blind (null site_type) plan gets the care rule.
        $out = $this->instructionsFor(['ymyl' => true]);
        $this->assertStringContainsString('CARE:', $out);

        // And a flagged-false brand does not.
        $out = $this->instructionsFor(['site_type' => 'brand', 'ymyl' => false]);
        $this->assertStringNotContainsString('CARE:', $out);
    }

    public function test_new_types_get_their_cta_framings(): void
    {
        $creator = $this->instructionsFor([
            'site_type' => 'creator',
            'toggles' => ['cta_enabled' => true], 'cta_url' => 'https://example.test/course',
        ]);
        $this->assertStringContainsString('check out the course or newsletter', $creator);

        $marketplace = $this->instructionsFor([
            'site_type' => 'marketplace',
            'toggles' => ['cta_enabled' => true], 'cta_url' => 'https://example.test/listings',
        ]);
        $this->assertStringContainsString('browse the listings', $marketplace);

        $education = $this->instructionsFor([
            'site_type' => 'education',
            'toggles' => ['cta_enabled' => true], 'cta_url' => 'https://example.test/enroll',
        ]);
        $this->assertStringContainsString('enroll or start learning', $education);
    }
}
