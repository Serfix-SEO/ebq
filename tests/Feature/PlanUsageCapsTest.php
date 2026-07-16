<?php

namespace Tests\Feature;

use App\Models\ClientActivity;
use App\Models\KeywordApiRequest;
use App\Models\Plan;
use App\Models\User;
use App\Services\KeywordFinder\KeywordFinderPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-plan usage caps: the keyword-fleet dispatch cap
 * (api_limits.keyword_research.monthly_searches) is enforced at the pool for
 * user-initiated lookups, never for platform-initiated (meter:false) ones,
 * and every ACKed metered dispatch logs a spend row the monthly window counts.
 */
class PlanUsageCapsTest extends TestCase
{
    use RefreshDatabase;

    private function planWithFleetCap(int $cap): User
    {
        Plan::create([
            'slug' => 'capped', 'name' => 'Capped', 'is_active' => true,
            'api_limits' => ['keyword_research' => ['monthly_searches' => $cap]],
        ]);

        return User::factory()->create(['current_plan_slug' => 'capped']);
    }

    public function test_fleet_dispatch_blocked_over_plan_cap_with_friendly_error(): void
    {
        Http::fake();
        $user = $this->planWithFleetCap(2);

        // Two spends already inside this month's window.
        foreach (range(1, 2) as $i) {
            ClientActivity::create([
                'type' => 'keyword_finder.dispatch', 'user_id' => $user->id,
                'provider' => 'keyword_finder', 'units_consumed' => 1,
            ]);
        }

        $request = app(KeywordFinderPool::class)->dispatchIdeas(['seeds' => ['seo']], userId: $user->id);

        $this->assertEquals(KeywordApiRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('keyword searches', (string) $request->error);
        Http::assertNothingSent(); // never reached a fleet server
    }

    public function test_unmetered_platform_dispatch_ignores_the_cap(): void
    {
        Http::fake();
        $user = $this->planWithFleetCap(0); // zero-cap plan

        $request = app(KeywordFinderPool::class)->dispatchIdeas(
            ['seeds' => ['seo']], userId: $user->id, meter: false,
        );

        // Not quota-failed — it proceeded to server selection (none exist in
        // tests, so it fails with the fleet-unavailable message instead).
        $this->assertStringNotContainsString('keyword searches', (string) $request->error);
    }

    public function test_verify_blocked_loudly_when_serp_quota_spent_and_nothing_cached(): void
    {
        Http::fake();
        Plan::create([
            'slug' => 'serpcapped', 'name' => 'SerpCapped', 'is_active' => true,
            'api_limits' => ['serper' => ['monthly_calls' => 1]],
        ]);
        $user = User::factory()->create(['current_plan_slug' => 'serpcapped']);
        ClientActivity::create([
            'type' => 'serp.query', 'user_id' => $user->id,
            'provider' => 'serp_api', 'units_consumed' => 1,
        ]);

        $analysis = \App\Models\KeywordGapAnalysis::create([
            'website_id' => null, 'user_id' => $user->id, 'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'], 'country' => 'us', 'status' => 'completed',
            'expires_at' => now()->addDays(30),
        ]);
        \App\Models\KeywordGapRow::create([
            'keyword_gap_analysis_id' => $analysis->id, 'keyword' => 'k1',
            'keyword_hash' => \App\Models\KeywordMetric::hashKeyword('k1'),
            'bucket' => 'missing', 'search_volume' => 100,
        ]);

        // Quota spent + no cached SERPs → the start throws the plan-limit
        // exception (rendered as the big quota banner by the component).
        $this->expectException(\App\Exceptions\QuotaExceededException::class);
        app(\App\Services\Competitive\KeywordGapService::class)->startVerification($analysis);
    }

    public function test_verify_still_runs_free_on_cached_serps_when_quota_spent(): void
    {
        Http::fake();
        Plan::create([
            'slug' => 'serpcapped2', 'name' => 'SerpCapped2', 'is_active' => true,
            'api_limits' => ['serper' => ['monthly_calls' => 1]],
        ]);
        $user = User::factory()->create(['current_plan_slug' => 'serpcapped2']);
        ClientActivity::create([
            'type' => 'serp.query', 'user_id' => $user->id,
            'provider' => 'serp_api', 'units_consumed' => 1,
        ]);

        $analysis = \App\Models\KeywordGapAnalysis::create([
            'website_id' => null, 'user_id' => $user->id, 'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'], 'country' => 'us', 'status' => 'completed',
            'expires_at' => now()->addDays(30),
        ]);
        \App\Models\KeywordGapRow::create([
            'keyword_gap_analysis_id' => $analysis->id, 'keyword' => 'cachedkw',
            'keyword_hash' => \App\Models\KeywordMetric::hashKeyword('cachedkw'),
            'bucket' => 'missing', 'search_volume' => 100,
        ]);
        \App\Models\SerpCacheEntry::create([
            'query_hash' => \App\Models\SerpCacheEntry::hash('cachedkw', 'us'), 'gl' => 'us', 'query' => 'cachedkw',
            'payload' => ['organic' => [['link' => 'https://rival.com/', 'position' => 3, 'domain' => 'rival.com']]],
            'fetched_at' => now(), 'expires_at' => now()->addDays(7),
        ]);

        $queued = app(\App\Services\Competitive\KeywordGapService::class)->startVerification($analysis);

        // Cached row verifies for free even with the quota spent.
        $this->assertSame(1, $queued);
        Http::assertNothingSent();
    }

    public function test_metered_dispatch_logs_spend_on_ack(): void
    {
        $user = $this->planWithFleetCap(10);
        \App\Models\KeywordApiServer::create([
            'name' => 't', 'base_url' => 'https://fleet.test', 'api_key' => 'k',
            'webhook_secret' => 'shh', 'is_active' => true, 'is_healthy' => true, 'logged_in' => true,
        ]);
        Http::fake(['https://fleet.test/*' => Http::response(['ok' => true], 200)]);

        $request = app(KeywordFinderPool::class)->dispatchIdeas(['seeds' => ['seo']], userId: $user->id);

        $this->assertEquals(KeywordApiRequest::STATUS_RUNNING, $request->status);
        $this->assertDatabaseHas('client_activities', [
            'user_id' => $user->id, 'provider' => 'keyword_finder', 'units_consumed' => 1,
        ]);
    }
}
