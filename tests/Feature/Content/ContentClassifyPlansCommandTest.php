<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentClassifyPlansCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $attrs = []): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::query()->create(array_merge([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'Premium home and office cleaning services in Dubai.',
            'offerings' => ['sell' => ['Deep cleaning'], 'dont_sell' => []],
        ], $attrs));
    }

    public function test_dry_run_lists_candidates_and_writes_nothing(): void
    {
        $plan = $this->makePlan();
        Http::fake(); // any HTTP call would be a failure — dry-run is free

        $this->artisan('ebq:content-classify-plans --dry-run')
            ->expectsOutputToContain('Dry run: 1 plan(s) would be classified.')
            ->assertSuccessful();

        $this->assertNull($plan->fresh()->site_type);
        Http::assertNothingSent();
    }

    public function test_backfills_unclassified_plans_and_skips_done_and_stub_rows(): void
    {
        config(['services.mistral.key' => 'test-key']);
        $target = $this->makePlan();
        $alreadyUser = $this->makePlan(['site_type' => 'blog', 'site_type_source' => 'user']);
        $stub = $this->makePlan(['business_description' => null]);

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'site_type' => 'local_service',
                    'audience' => 'Households and offices in Dubai.',
                ])]]],
                'usage' => ['total_tokens' => 40],
            ]),
        ]);

        $this->artisan('ebq:content-classify-plans')->assertSuccessful();

        $this->assertSame('local_service', $target->fresh()->site_type);
        $this->assertSame('auto', $target->fresh()->site_type_source);
        // A human-chosen type is untouched; a profile-less stub stays null.
        $this->assertSame('blog', $alreadyUser->fresh()->site_type);
        $this->assertSame('user', $alreadyUser->fresh()->site_type_source);
        $this->assertNull($stub->fresh()->site_type);
        // Exactly one LLM call — one per unclassified profiled plan.
        Http::assertSentCount(1);
    }

    public function test_classification_failure_leaves_the_plan_null_for_retry(): void
    {
        config(['services.mistral.key' => 'test-key']);
        $plan = $this->makePlan();

        Http::fake(['api.mistral.ai/*' => Http::response('upstream sad', 500)]);

        $this->artisan('ebq:content-classify-plans')->assertSuccessful();

        $this->assertNull($plan->fresh()->site_type);
    }
}
