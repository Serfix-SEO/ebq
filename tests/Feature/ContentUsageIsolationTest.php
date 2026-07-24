<?php

namespace Tests\Feature;

use App\Models\ClientActivity;
use App\Models\User;
use App\Services\Llm\MistralClient;
use App\Services\Usage\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Content Autopilot (and every other __unmetered LLM caller) must NOT consume
 * the per-user DASHBOARD token quota — it carries its own caps
 * (ContentLlmSpendMeter + article limits). The isolation is enforced in
 * OpenAiCompatibleClient: unmetered spend is logged under a NON-pool provider
 * label ('{provider}:unmetered'), so UsageMeter (which sums the mistral/deepseek
 * pool) never counts it and the reservation release() can't corrupt an in-flight
 * dashboard reservation.
 */
class ContentUsageIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMistral(int $totalTokens): void
    {
        Http::fake(['api.mistral.ai/*' => Http::response([
            'model' => 'mistral-small-latest',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => $totalTokens - 100, 'total_tokens' => $totalTokens],
        ])]);
    }

    public function test_unmetered_spend_is_logged_outside_the_pool_and_not_counted_against_the_dashboard_cap(): void
    {
        $user = User::factory()->create();
        $this->fakeMistral(1000);

        (new MistralClient('sk-test'))->complete(
            [['role' => 'user', 'content' => 'write an article']],
            ['__user_id' => $user->id, '__unmetered' => true, '__source' => 'content_autopilot.write'],
        );

        // Spend IS logged (cost telemetry) — but under the non-pool label.
        $row = ClientActivity::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('mistral:unmetered', $row->provider);
        $this->assertSame(1000, (int) $row->units_consumed);

        // ...and therefore does NOT deplete the dashboard mistral/deepseek pool.
        $this->assertSame(0, app(UsageMeter::class)->consumedInWindow($user, 'mistral'));
        $this->assertSame(0, app(UsageMeter::class)->consumedInWindow($user, 'deepseek'));
    }

    public function test_metered_spend_still_counts_against_the_dashboard_cap(): void
    {
        $user = User::factory()->create();
        $this->fakeMistral(1000);

        (new MistralClient('sk-test'))->complete(
            [['role' => 'user', 'content' => 'dashboard ai writer call']],
            ['__user_id' => $user->id], // metered (no __unmetered)
        );

        $row = ClientActivity::where('user_id', $user->id)->first();
        $this->assertSame('mistral', $row->provider);
        $this->assertSame(1000, app(UsageMeter::class)->consumedInWindow($user, 'mistral'));
    }

    public function test_unmetered_release_does_not_corrupt_an_in_flight_dashboard_reservation(): void
    {
        $user = User::factory()->create();
        $meter = app(UsageMeter::class);

        // A real dashboard call reserves tokens (in-flight, not yet logged).
        $meter->reserve($user->id, 'mistral', 500);
        $this->assertSame(500, $meter->pendingReserved($user->id, 'mistral'));

        // Meanwhile a content article finishes and logs its (large) spend. The
        // release() inside ClientActivityLogger must hit a dead key, not the
        // live mistral reservation.
        $this->fakeMistral(20000);
        (new MistralClient('sk-test'))->complete(
            [['role' => 'user', 'content' => 'content article']],
            ['__user_id' => $user->id, '__unmetered' => true],
        );

        $this->assertSame(500, $meter->pendingReserved($user->id, 'mistral'));
    }
}
