<?php

namespace Tests\Feature\Competitive;

use App\Exceptions\QuotaExceededException;
use App\Jobs\RunKeywordGapVerification;
use App\Models\KeywordGapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Competitive\KeywordGapService;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class KeywordGapVerifyTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(Website $w): KeywordGapAnalysis
    {
        return KeywordGapAnalysis::create([
            'website_id' => $w->id, 'user_id' => $w->user_id, 'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'], 'country' => 'us', 'status' => 'completed',
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function row(string $analysisId, string $keyword, int $vol): KeywordGapRow
    {
        return KeywordGapRow::create([
            'keyword_gap_analysis_id' => $analysisId, 'keyword' => $keyword,
            'keyword_hash' => KeywordMetric::hashKeyword($keyword), 'bucket' => 'missing',
            'search_volume' => $vol,
        ]);
    }

    /** @param array<string, list<array{domain: string, position: int}>> $byKeyword */
    private function fakeSerper(array $byKeyword): void
    {
        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->andReturnUsing(function (array $params) use ($byKeyword): array {
            $organic = [];
            foreach ($byKeyword[(string) ($params['q'] ?? '')] ?? [] as $r) {
                $organic[] = ['link' => 'https://www.'.$r['domain'].'/', 'position' => $r['position']];
            }

            return ['organic' => $organic];
        });
        $this->app->instance(SerperSearchClient::class, $serper);
    }

    private function website(): Website
    {
        $user = User::factory()->create();

        return Website::factory()->withNoSources()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
    }

    public function test_verify_rebuckets_from_real_positions(): void
    {
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website);
        $analysis->forceFill(['verify_status' => 'verifying', 'verify_total' => 4])->save();

        $this->row($analysis->id, 'confirmed gap', 4000);   // competitor #3, we absent
        $this->row($analysis->id, 'we strong', 3000);       // we #6 → strength
        $this->row($analysis->id, 'we weak', 2000);         // we #15 → weak
        $this->row($analysis->id, 'nobody', 1000);          // neither in top 10

        $this->fakeSerper([
            'confirmed gap' => [['domain' => 'rival.com', 'position' => 3], ['domain' => 'other.com', 'position' => 1]],
            'we strong' => [['domain' => 'mysite.com', 'position' => 6], ['domain' => 'rival.com', 'position' => 2]],
            'we weak' => [['domain' => 'mysite.com', 'position' => 15], ['domain' => 'rival.com', 'position' => 1]],
            'nobody' => [['domain' => 'someoneelse.com', 'position' => 1]],
        ]);

        app(KeywordGapService::class)->verify($analysis->id);

        $gap = KeywordGapRow::where('keyword', 'confirmed gap')->first();
        $this->assertSame('missing', $gap->bucket);
        $this->assertSame(3, $gap->competitor_position);
        $this->assertNotNull($gap->verified_at);

        $this->assertSame('strength', KeywordGapRow::where('keyword', 'we strong')->value('bucket'));
        $this->assertSame('weak', KeywordGapRow::where('keyword', 'we weak')->value('bucket'));

        $nobody = KeywordGapRow::where('keyword', 'nobody')->first();
        $this->assertSame('missing', $nobody->bucket);
        $this->assertNull($nobody->competitor_position); // checked but unconfirmed
        $this->assertNotNull($nobody->verified_at);

        $this->assertSame('completed', $analysis->fresh()->verify_status);
    }

    public function test_verify_respects_the_cap(): void
    {
        config(['services.competitive.gap_verify_max' => 2]);
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website);
        $analysis->forceFill(['verify_status' => 'verifying', 'verify_total' => 2])->save();
        foreach (['k1', 'k2', 'k3', 'k4', 'k5'] as $i => $k) {
            $this->row($analysis->id, $k, (5 - $i) * 100);
        }

        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->times(2)->andReturn(['organic' => [['link' => 'https://rival.com/', 'position' => 1]]]);
        $this->app->instance(SerperSearchClient::class, $serper);

        app(KeywordGapService::class)->verify($analysis->id);

        $this->assertSame(2, KeywordGapRow::whereNotNull('verified_at')->count());
    }

    public function test_cached_serps_verify_for_free_beyond_the_live_cap(): void
    {
        config(['services.competitive.gap_verify_max' => 1, 'services.competitive.gap_verify_cached_max' => 150]);
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website);
        $analysis->forceFill(['verify_status' => 'verifying', 'verify_total' => 4])->save();

        // 3 keywords already have FRESH cached SERPs (free); 2 are misses.
        foreach (['c1', 'c2', 'c3', 'm1', 'm2'] as $i => $k) {
            $this->row($analysis->id, $k, (5 - $i) * 100);
        }
        foreach (['c1', 'c2', 'c3'] as $k) {
            \App\Models\SerpCacheEntry::create([
                'query_hash' => \App\Models\SerpCacheEntry::hash($k, 'us'), 'gl' => 'us', 'query' => $k,
                'payload' => ['organic' => [['link' => 'https://rival.com/', 'position' => 2, 'domain' => 'rival.com']]],
                'fetched_at' => now(), 'expires_at' => now()->addDays(7),
            ]);
        }

        // Live budget = 1 -> Serper hit exactly once for the first miss.
        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->times(1)->andReturn(['organic' => [['link' => 'https://rival.com/', 'position' => 5]]]);
        $this->app->instance(SerperSearchClient::class, $serper);

        app(KeywordGapService::class)->verify($analysis->id);

        // All 3 cached rows verified for free + 1 live = 4; the 2nd miss stays unverified.
        $this->assertSame(4, KeywordGapRow::whereNotNull('verified_at')->count());
        $this->assertNull(KeywordGapRow::where('keyword', 'm2')->first()->verified_at);
    }

    public function test_quota_mid_run_stops_and_records_partial(): void
    {
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website);
        $analysis->forceFill(['verify_status' => 'verifying', 'verify_total' => 3])->save();
        $this->row($analysis->id, 'a', 300);
        $this->row($analysis->id, 'b', 200);
        $this->row($analysis->id, 'c', 100);

        $calls = 0;
        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->andReturnUsing(function () use (&$calls): array {
            $calls++;
            if ($calls >= 2) {
                throw new QuotaExceededException('serp_api', 100, 100, 'SERP limit reached', 'https://app/upgrade');
            }

            return ['organic' => [['link' => 'https://rival.com/', 'position' => 1]]];
        });
        $this->app->instance(SerperSearchClient::class, $serper);

        app(KeywordGapService::class)->verify($analysis->id);

        $analysis->refresh();
        $this->assertSame('completed', $analysis->verify_status);
        $this->assertSame('SERP limit reached', $analysis->verify_error);
        $this->assertSame(1, KeywordGapRow::whereNotNull('verified_at')->count());
        $this->assertLessThan($analysis->verify_total, $analysis->verify_done);
    }

    public function test_start_verification_dispatches_job(): void
    {
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website);
        $this->row($analysis->id, 'x', 100);

        $queued = app(KeywordGapService::class)->startVerification($analysis);

        $this->assertSame(1, $queued);
        Queue::assertPushed(RunKeywordGapVerification::class);
        $this->assertSame('verifying', $analysis->fresh()->verify_status);
    }

    public function test_start_verification_noop_when_nothing_to_verify(): void
    {
        Queue::fake();
        $website = $this->website();
        $analysis = $this->analysis($website); // no rows

        $this->assertSame(0, app(KeywordGapService::class)->startVerification($analysis));
        Queue::assertNotPushed(RunKeywordGapVerification::class);
    }
}
