<?php

namespace Tests\Feature\Api\V1;

use App\Models\KeywordApiRequest;
use App\Models\KeywordApiServer;
use App\Models\User;
use App\Models\Website;
use App\Support\KeywordProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PluginKeywordFinderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        KeywordProviderConfig::setProvider(KeywordProviderConfig::PROVIDER_KEYWORD_FINDER);
        KeywordApiServer::create([
            'name' => 'Server A', 'base_url' => 'http://server-a.test',
            'api_key' => 'key-a', 'webhook_secret' => 'secret-a', 'is_active' => true,
        ]);
        Http::fake(['server-a.test/*' => Http::response(['queued' => true], 200)]);
    }

    private function website(): Website
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);
        RateLimiter::clear('plugin-kwf:'.$website->id);

        return $website;
    }

    private function token(Website $website): string
    {
        return $website->createToken('test', ['read:insights'])->plainTextToken;
    }

    public function test_requires_token(): void
    {
        $this->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['seo']])->assertStatus(401);
    }

    public function test_provider_disabled_returns_unavailable(): void
    {
        KeywordProviderConfig::setProvider(KeywordProviderConfig::PROVIDER_KEYWORDS_EVERYWHERE);
        $website = $this->website();

        $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['seo']])
            ->assertStatus(503)
            ->assertJson(['error' => 'unavailable']);
    }

    public function test_seed_and_keyword_caps_are_enforced(): void
    {
        $website = $this->website();
        $token = $this->token($website);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/ideas', [
                'seeds' => array_map(fn ($i) => "kw {$i}", range(1, 21)),
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/volume', [
                'keywords' => array_map(fn ($i) => "kw {$i}", range(1, 101)),
            ])
            ->assertStatus(422);

        $this->assertSame(0, KeywordApiRequest::count());
    }

    public function test_ideas_dispatches_and_poll_returns_results_scoped_to_website(): void
    {
        $website = $this->website();
        $token = $this->token($website);

        $resp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['seo audit']])
            ->assertStatus(202)
            ->assertJsonStructure(['status', 'request_id'])
            ->json();

        $row = KeywordApiRequest::sole();
        $this->assertSame($website->id, $row->website_id);
        $this->assertSame($resp['request_id'], $row->request_id);

        // In flight → status only.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/keyword-finder/requests/'.$row->request_id)
            ->assertOk()
            ->assertJson(['status' => KeywordApiRequest::STATUS_RUNNING])
            ->assertJsonMissingPath('results');

        // Webhook completes it → results, normalized shape.
        $row->markCompleted(['results' => [
            ['keyword' => 'seo audit tool', 'avgMonthlySearches' => 1200, 'competitionIndex' => 20, 'lowTopOfPageBid' => 0.4, 'highTopOfPageBid' => 1.1],
        ]]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/keyword-finder/requests/'.$row->request_id)
            ->assertOk()
            ->assertJsonPath('results.0.keyword', 'seo audit tool')
            ->assertJsonPath('results.0.volume', 1200)
            ->assertJsonPath('results.0.comp_level', 'low');

        // Another website's token cannot poll this request.
        $other = $this->website();
        $this->withHeader('Authorization', 'Bearer '.$this->token($other))
            ->getJson('/api/v1/hq/keyword-finder/requests/'.$row->request_id)
            ->assertStatus(404);
    }

    public function test_second_identical_ideas_lookup_hits_monthly_cache_without_dispatch(): void
    {
        $website = $this->website();
        $token = $this->token($website);

        $resp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['seo audit']])
            ->json();
        KeywordApiRequest::sole()->markCompleted(['results' => [
            ['keyword' => 'seo audit tool', 'avgMonthlySearches' => 1200],
        ]]);
        // Completion poll warms the shared monthly cache (server-derived key).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/keyword-finder/requests/'.$resp['request_id'])
            ->assertOk();

        $before = KeywordApiRequest::count();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['SEO Audit']]) // casing differs — still a hit
            ->assertOk()
            ->assertJson(['status' => 'completed', 'from_cache' => true])
            ->assertJsonPath('results.0.keyword', 'seo audit tool');
        $this->assertSame($before, KeywordApiRequest::count());
    }

    public function test_daily_dispatch_limit_returns_429(): void
    {
        $website = $this->website();
        $token = $this->token($website);

        for ($i = 1; $i <= 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ["unique kw {$i}"]])
                ->assertStatus(202);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/ideas', ['seeds' => ['one more']])
            ->assertStatus(429)
            ->assertJson(['error' => 'rate_limited']);

        $this->assertSame(10, KeywordApiRequest::count());
    }

    public function test_volume_serves_fresh_metrics_inline_and_dispatches_misses(): void
    {
        $website = $this->website();
        $token = $this->token($website);

        // Nothing cached → dispatch.
        $resp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/volume', ['keywords' => ['blue widgets'], 'location' => 'United States'])
            ->assertStatus(202)
            ->json();
        $row = KeywordApiRequest::sole();
        $this->assertSame($website->id, $row->website_id);

        // Webhook completes + caches metrics; volume-style poll re-reads them.
        $row->markCompleted(['results' => [['keyword' => 'blue widgets', 'avgMonthlySearches' => 900]]]);
        app(\App\Services\KeywordMetricsService::class)->ingestFinderResults(
            [['keyword' => 'blue widgets', 'avgMonthlySearches' => 900, 'competition' => 'LOW']],
            (string) ($row->payload['country_key'] ?? 'global'),
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/keyword-finder/requests/'.$resp['request_id'].'?keywords=blue widgets')
            ->assertOk()
            ->assertJsonPath('results.0.keyword', 'blue widgets')
            ->assertJsonPath('results.0.volume', 900);

        // Now fresh in keyword_metrics → second lookup is inline, no dispatch.
        $before = KeywordApiRequest::count();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hq/keyword-finder/volume', ['keywords' => ['blue widgets'], 'location' => 'United States'])
            ->assertOk()
            ->assertJson(['status' => 'completed', 'from_cache' => true])
            ->assertJsonPath('results.0.volume', 900);
        $this->assertSame($before, KeywordApiRequest::count());
    }
}
