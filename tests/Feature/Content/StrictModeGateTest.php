<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Strict Product Mode planner gate. INVARIANT: only strict + catalog-not-
 * ready blocks planning; null (undecided) and normal behave exactly like
 * pre-feature plans — no bypass path may silently stall calendar top-ups.
 */
class StrictModeGateTest extends TestCase
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

    private function plan(array $attrs = []): ContentPlan
    {
        $website = Website::factory()->for(User::factory())->create();

        return ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'A real shop selling things.',
        ] + $attrs);
    }

    /** The planner "ran" when it got past the guards (log key differs per guard). */
    private function gateVerdict(ContentPlan $plan): string
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });

        (new PlanContentTopicsJob($plan->id))->handle();

        return in_array('content_autopilot.topics_skipped_catalog_pending', $messages, true)
            ? 'gated' : 'ran';
    }

    public function test_gate_matrix_only_strict_without_catalog_blocks(): void
    {
        // mode null → runs (old behavior, whatever the site type)
        $this->assertSame('ran', $this->gateVerdict($this->plan(['product_mode' => null, 'site_type' => 'ecommerce_reseller'])));

        // mode normal → runs
        $this->assertSame('ran', $this->gateVerdict($this->plan(['product_mode' => 'normal', 'site_type' => 'brand'])));

        // strict + NO catalog → gated
        $this->assertSame('gated', $this->gateVerdict($this->plan(['product_mode' => 'strict'])));

        // strict + catalog ready → runs
        $strictReady = $this->plan(['product_mode' => 'strict']);
        ContentProduct::factory()->create(['website_id' => $strictReady->website_id]);
        $this->assertSame('ran', $this->gateVerdict($strictReady));

        // strict + catalog exists but all excluded/gone → gated (not usable)
        $strictEmpty = $this->plan(['product_mode' => 'strict']);
        ContentProduct::factory()->create(['website_id' => $strictEmpty->website_id, 'is_excluded' => true]);
        ContentProduct::factory()->create(['website_id' => $strictEmpty->website_id, 'status' => ContentProduct::STATUS_GONE]);
        $this->assertSame('gated', $this->gateVerdict($strictEmpty));
    }

    public function test_factory_default_plan_is_old_behavior(): void
    {
        // The factory ships product_mode = null — pinned so no future change
        // can quietly make every test plan strict.
        $this->assertNull(ContentPlan::factory()->create([
            'website_id' => Website::factory()->for(User::factory())->create()->id,
        ])->product_mode);
    }
}
