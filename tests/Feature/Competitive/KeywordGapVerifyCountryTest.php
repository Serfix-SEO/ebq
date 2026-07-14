<?php

namespace Tests\Feature\Competitive;

use App\Models\KeywordGapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\KeywordMetric;
use App\Models\SerpCacheEntry;
use App\Models\User;
use App\Models\Website;
use App\Services\Competitive\KeywordGapService;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Live-ranking verification must honour the analysis's country end-to-end:
 * the SERP request carries the run's `gl`, and the SERP cache is keyed by it
 * — the same keyword verified under two countries is two distinct lookups,
 * while a repeat under the SAME country is a free cache hit.
 */
class KeywordGapVerifyCountryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> every params array Serper received */
    private array $serperCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->andReturnUsing(function (array $params): array {
            $this->serperCalls[] = $params;

            return ['organic' => [['link' => 'https://www.rival.com/', 'position' => 2]]];
        });
        $this->app->instance(SerperSearchClient::class, $serper);
    }

    private function verifyingAnalysis(string $country): KeywordGapAnalysis
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);

        $analysis = KeywordGapAnalysis::create([
            'website_id' => $website->id, 'user_id' => $user->id, 'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'], 'country' => $country, 'status' => 'completed',
            'expires_at' => now()->addDays(30),
            'verify_status' => KeywordGapAnalysis::VERIFY_STATUS_VERIFYING,
            'verify_total' => 1, 'verify_done' => 0,
        ]);
        KeywordGapRow::create([
            'keyword_gap_analysis_id' => $analysis->id, 'keyword' => 'best crm',
            'keyword_hash' => KeywordMetric::hashKeyword('best crm'), 'bucket' => 'missing',
            'search_volume' => 1000,
        ]);

        return $analysis;
    }

    public function test_serp_request_carries_the_analysis_country_and_cache_is_country_keyed(): void
    {
        // UAE run → gl=ae on the wire, cached under gl=ae.
        app(KeywordGapService::class)->verify($this->verifyingAnalysis('ae')->id);

        $this->assertCount(1, $this->serperCalls);
        $this->assertSame('ae', $this->serperCalls[0]['gl']);
        $this->assertSame(1, SerpCacheEntry::where('gl', 'ae')->count());

        // SAME keyword, DIFFERENT country → a second, distinct SERP lookup
        // under gl=fr (the ae cache entry must not bleed across countries).
        app(KeywordGapService::class)->verify($this->verifyingAnalysis('fr')->id);

        $this->assertCount(2, $this->serperCalls);
        $this->assertSame('fr', $this->serperCalls[1]['gl']);
        $this->assertSame(1, SerpCacheEntry::where('gl', 'fr')->count());

        // SAME keyword, SAME country again → served from the SERP cache,
        // no third provider call.
        app(KeywordGapService::class)->verify($this->verifyingAnalysis('ae')->id);

        $this->assertCount(2, $this->serperCalls);
    }

    public function test_global_country_maps_to_us_serp(): void
    {
        app(KeywordGapService::class)->verify($this->verifyingAnalysis('global')->id);

        $this->assertCount(1, $this->serperCalls);
        $this->assertSame('us', $this->serperCalls[0]['gl']);
    }
}
