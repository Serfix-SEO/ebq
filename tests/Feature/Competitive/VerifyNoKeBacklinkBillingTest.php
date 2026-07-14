<?php

namespace Tests\Feature\Competitive;

use App\Jobs\FetchCompetitorBacklinks;
use App\Models\KeywordGapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Competitive\KeywordGapService;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Regression (found 2026-07-14 on the admin usage page): gap verification's
 * opportunity scoring used to push every SERP top-10 domain through the
 * legacy Keywords Everywhere BACKLINK endpoint (50 credits/domain) just to
 * derive a DA number — one verify pass billed thousands of KE credits for
 * random SERP domains, attributed to the verifying user. Authority now comes
 * from Open PageRank (free bulk endpoint) with a cached-DA fallback.
 */
class VerifyNoKeBacklinkBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_never_dispatches_ke_backlink_fetches(): void
    {
        Queue::fake();
        Http::fake(); // OPR bulk call is faked — returns [], fallback path used.

        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->andReturn(['organic' => [
            ['link' => 'https://www.ssa.gov/page', 'position' => 1],
            ['link' => 'https://support.google.com/x', 'position' => 2],
            ['link' => 'https://www.rival.com/', 'position' => 3],
        ]]);
        $this->app->instance(SerperSearchClient::class, $serper);

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        $analysis = KeywordGapAnalysis::create([
            'website_id' => $website->id, 'user_id' => $user->id, 'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'], 'country' => 'us', 'status' => 'completed',
            'expires_at' => now()->addDays(30),
            'verify_status' => KeywordGapAnalysis::VERIFY_STATUS_VERIFYING,
            'verify_total' => 1, 'verify_done' => 0,
        ]);
        KeywordGapRow::create([
            'keyword_gap_analysis_id' => $analysis->id, 'keyword' => 'best crm',
            'keyword_hash' => KeywordMetric::hashKeyword('best crm'), 'bucket' => 'missing',
            'search_volume' => 1000,
        ]);

        app(KeywordGapService::class)->verify($analysis->id);

        // The row was verified and scored…
        $row = KeywordGapRow::firstOrFail();
        $this->assertNotNull($row->verified_at);
        $this->assertNotNull($row->opportunity_score);

        // …WITHOUT a single Keywords Everywhere backlink fetch for the SERP domains.
        Queue::assertNotPushed(FetchCompetitorBacklinks::class);
    }
}
