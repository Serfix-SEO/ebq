<?php

namespace Tests\Feature;

use App\Jobs\EnrichEmptyReportJob;
use App\Jobs\GenerateWebsiteReport;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\MozLinksClient;
use App\Services\ReportFreshnessGate;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * Global monthly DataForSEO spend circuit-breaker. Admin-only concept: every
 * degraded path must render EXISTING neutral client UI (cached report or the
 * young-site partial flow) — these tests also pin that no dfs call happens
 * on the degraded paths.
 */
class DataForSeoSpendCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh month counter per test (phpunit pins an isolated Redis DB).
        Redis::connection()->del('dfs:spend:'.now()->utc()->format('Y-m'));
    }

    private function meter(): DataForSeoSpendMeter
    {
        return app(DataForSeoSpendMeter::class);
    }

    // ── Meter mechanics ─────────────────────────────────────────

    public function test_meter_accumulates_and_trips_thresholds(): void
    {
        config(['services.dataforseo.monthly_cap_usd' => 1.00]);

        $this->assertSame(0.0, $this->meter()->spent());
        $this->assertFalse($this->meter()->nearCap());

        $this->meter()->add(0.5);
        $this->assertEqualsWithDelta(0.5, $this->meter()->spent(), 0.001);
        $this->assertFalse($this->meter()->nearCap());

        $this->meter()->add(0.35); // 0.85 → 80%+ warning zone
        $this->assertTrue($this->meter()->nearCap());
        $this->assertFalse($this->meter()->exhausted());

        $this->meter()->add(0.20); // 1.05 → cap reached
        $this->assertTrue($this->meter()->exhausted());
    }

    public function test_meter_disabled_when_cap_null_or_zero(): void
    {
        config(['services.dataforseo.monthly_cap_usd' => null]);
        $this->meter()->add(999);
        $this->assertFalse($this->meter()->exhausted());
        $this->assertFalse($this->meter()->nearCap());

        config(['services.dataforseo.monthly_cap_usd' => 0]);
        $this->assertFalse($this->meter()->exhausted());
    }

    // ── Job guard behaviour over cap ────────────────────────────

    private function overCap(): void
    {
        config(['services.dataforseo.monthly_cap_usd' => 0.01, 'services.report.enrichment.enabled' => true]);
        $this->meter()->add(0.02);
    }

    private function runJob(string $domain, $dfs): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);
        $moz = Mockery::mock(MozLinksClient::class);
        $moz->shouldReceive('isConfigured')->andReturn(false);
        $opr = Mockery::mock(\App\Services\OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn([]);

        (new GenerateWebsiteReport($domain))->handle(
            $dfs, $moz, $opr, app(ReportFreshnessGate::class),
            app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\ClientActivityLogger::class),
        );
    }

    public function test_over_cap_lookup_degrades_to_free_signal_partial(): void
    {
        Bus::fake();
        $this->overCap();

        // NO dfs call may happen — the whole point.
        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('summary')->never();
        $dfs->shouldReceive('useSandbox')->never();

        $this->runJob('lookup-target.com', $dfs);

        $snapshot = WebsiteReportSnapshot::forDomain('lookup-target.com');
        $this->assertSame('enriching', $snapshot->status); // young-site flow, neutral UI
        Bus::assertDispatched(EnrichEmptyReportJob::class);
    }

    public function test_over_cap_attached_own_site_still_generates_fully(): void
    {
        Bus::fake();
        $this->overCap();
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'my-own-site.com']);

        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('useSandbox')->andReturnSelf();
        $dfs->shouldReceive('summary')->once()->andReturn(['backlinks' => 2, 'rank' => 50]);
        $dfs->shouldReceive('backlinksSample')->andReturn([
            ['domain_from' => 'a.test', 'url_to' => 'https://my-own-site.com/', 'anchor' => 'x', 'dofollow' => true],
        ]);
        $dfs->shouldReceive('history')->andReturn([]);
        $dfs->shouldReceive('labsCompetitors')->andReturn([]);
        $dfs->shouldReceive('competitors')->andReturn([]);
        $dfs->shouldReceive('totalCost')->andReturn(0.11);

        $this->runJob('my-own-site.com', $dfs);

        $this->assertSame('ready', WebsiteReportSnapshot::forDomain('my-own-site.com')->status);
    }

    public function test_over_cap_existing_report_is_served_stale_not_regenerated(): void
    {
        Bus::fake();
        $this->overCap();
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'cached-site.com',
            'status' => 'ready',
            'fetched_at' => now()->subDays(200), // long past TTL
            'payload' => ['domain' => 'cached-site.com', 'totals' => [], 'ratios' => []],
        ]);

        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('summary')->never();

        // force=true mimics the schema self-heal / forced TTL refresh path.
        (new GenerateWebsiteReport('cached-site.com', true))->handle(
            $dfs,
            tap(Mockery::mock(MozLinksClient::class), fn ($m) => $m->shouldReceive('isConfigured')->andReturn(false)),
            tap(Mockery::mock(\App\Services\OpenPageRankClient::class), fn ($m) => $m->shouldReceive('metricsFor')->andReturn([])),
            app(ReportFreshnessGate::class),
            app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\ClientActivityLogger::class),
        );

        $snapshot = WebsiteReportSnapshot::forDomain('cached-site.com');
        $this->assertSame('ready', $snapshot->status);
        $this->assertNotEmpty($snapshot->payload); // untouched
    }

    public function test_under_cap_everything_runs_normally(): void
    {
        Bus::fake();
        config(['services.dataforseo.monthly_cap_usd' => 100.0]);

        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('useSandbox')->andReturnSelf();
        $dfs->shouldReceive('summary')->once()->andReturn(['backlinks' => 1, 'rank' => 10]);
        $dfs->shouldReceive('backlinksSample')->andReturn([
            ['domain_from' => 'a.test', 'url_to' => 'https://normal.com/', 'anchor' => 'x', 'dofollow' => true],
        ]);
        $dfs->shouldReceive('history')->andReturn([]);
        $dfs->shouldReceive('labsCompetitors')->andReturn([]);
        $dfs->shouldReceive('competitors')->andReturn([]);
        $dfs->shouldReceive('totalCost')->andReturn(0.17);

        $this->runJob('normal.com', $dfs);

        $this->assertSame('ready', WebsiteReportSnapshot::forDomain('normal.com')->status);
        // Real billed cost got metered.
        $this->assertEqualsWithDelta(0.17, $this->meter()->spent(), 0.001);
    }

    protected function tearDown(): void
    {
        Redis::connection()->del('dfs:spend:'.now()->utc()->format('Y-m'));
        Mockery::close();
        parent::tearDown();
    }
}
