<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\EnrichCompetitorDomainMetricsJob;
use App\Models\DomainMetric;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichCompetitorDomainMetricsJobTest extends TestCase
{
    use RefreshDatabase;

    private function configureDfs(): void
    {
        config([
            'services.dataforseo.login' => 'test-login',
            'services.dataforseo.password' => 'test-pass',
            'services.dataforseo.force_sandbox' => false,
            'services.dataforseo.monthly_cap_usd' => null,
        ]);
    }

    private function fakeBulkResponse(array $items): void
    {
        Http::fake([
            'api.dataforseo.com/*' => Http::response([
                'tasks' => [[
                    'cost' => 0.02,
                    'status_code' => 20000,
                    'result' => [[
                        'items' => $items,
                    ]],
                ]],
            ], 200),
        ]);
    }

    public function test_it_stores_whatever_dfs_returns_per_competitor_domain(): void
    {
        $this->configureDfs();
        $this->fakeBulkResponse([
            ['target' => 'rival-one.com', 'metrics' => ['organic' => ['etv' => 12345.6, 'count' => 900]]],
            ['target' => 'rival-two.com', 'metrics' => ['organic' => ['etv' => 42.0, 'count' => 12]]],
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $website = Website::factory()->for($user)->create();

        (new EnrichCompetitorDomainMetricsJob($website->id, [
            'https://www.rival-one.com/', 'rival-two.com',
        ]))->handle(
            app(\App\Services\DataForSeoBacklinkClient::class),
            app(\App\Services\Reports\DataForSeoSpendMeter::class),
        );

        $one = DomainMetric::query()->where('domain', 'rival-one.com')->first();
        $this->assertNotNull($one);
        $this->assertNotNull($one->dfs_metrics_refreshed_at);
        $this->assertSame(12345.6, $one->dfs_metrics['metrics']['organic']['etv']);
        // The redundant target echo is stripped; the metrics blob is kept whole.
        $this->assertArrayNotHasKey('target', $one->dfs_metrics);

        $this->assertNotNull(DomainMetric::query()->where('domain', 'rival-two.com')->first());
    }

    public function test_it_skips_domains_already_fresh(): void
    {
        $this->configureDfs();
        DomainMetric::query()->create([
            'domain' => 'rival-one.com',
            'dfs_metrics' => ['metrics' => ['organic' => ['etv' => 1.0]]],
            'dfs_metrics_refreshed_at' => now()->subDay(),
        ]);

        Http::fake(); // no request expected

        $user = User::factory()->create(['is_admin' => false]);
        $website = Website::factory()->for($user)->create();

        (new EnrichCompetitorDomainMetricsJob($website->id, ['rival-one.com']))->handle(
            app(\App\Services\DataForSeoBacklinkClient::class),
            app(\App\Services\Reports\DataForSeoSpendMeter::class),
        );

        Http::assertNothingSent();
    }
}
