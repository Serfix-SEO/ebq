<?php

namespace Tests\Feature\Competitive;

use App\Jobs\Competitive\ClassifyCompetitorTopicJob;
use App\Models\DiscoveredCompetitor;
use App\Models\CompetitorDiscoveryRun;
use App\Models\DomainMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Competitive\CompetitorDiscoveryService;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClient;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Site Explorer competitor discovery now enriches from the SHARED domain_metrics
 * asset (real DataForSEO referring-domains + Moz DA/PA) instead of OpenPageRank,
 * and exposes an OPT-IN topical classification. Guards the caveats: no LLM during
 * discovery, real metrics denormalized onto the discovered rows + cached platform-
 * wide, and the shared cache reused without re-billing.
 */
class CompetitorEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // isolate any side dispatch
        config([
            'services.competitive.discovery_refresh_days' => 14,
            'services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y',
            'services.moz.token' => 'fake-token',
        ]);
    }

    private function website(string $domain = 'mysite.com'): Website
    {
        $user = User::factory()->create(['is_admin' => false]);

        return Website::factory()->create(['user_id' => $user->id, 'domain' => $domain]);
    }

    private function fakeSerp(array $serpByKeyword): void
    {
        $serper = Mockery::mock(SerperSearchClient::class);
        $serper->shouldReceive('query')->andReturnUsing(function (array $params) use ($serpByKeyword): array {
            $organic = [];
            foreach ($serpByKeyword[(string) ($params['q'] ?? '')] ?? [] as $r) {
                $organic[] = ['link' => 'https://www.'.$r['domain'].'/', 'position' => $r['position'], 'title' => 'x'];
            }

            return ['organic' => $organic];
        });
        $this->app->instance(SerperSearchClient::class, $serper);
    }

    public function test_discovery_enriches_competitors_with_dataforseo_and_moz(): void
    {
        Http::fake([
            'api.dataforseo.com/*' => Http::response([
                'tasks' => [['cost' => 0.024, 'result' => [['referring_domains' => 812, 'backlinks' => 5400, 'rank' => 430]]]],
            ], 200),
            'lsapi.seomoz.com/*' => Http::response([
                'results' => [['domain_authority' => 47, 'page_authority' => 39]],
            ], 200),
        ]);

        $website = $this->website('mysite.com');
        $this->fakeSerp(['alpha' => [['domain' => 'rival.com', 'position' => 2]]]);
        CompetitorDiscoveryRun::create(['run_id' => 'r1', 'website_id' => $website->id, 'status' => 'queued']);

        app(CompetitorDiscoveryService::class)->run('r1', ['alpha']);

        $rival = DiscoveredCompetitor::where('website_id', $website->id)->where('competitor_domain', 'rival.com')->first();
        $this->assertNotNull($rival);
        $this->assertSame(812, $rival->referring_domains, 'real DataForSEO referring domains, not OPR');
        $this->assertSame(47, $rival->domain_authority, 'Moz DA preferred');
        $this->assertSame(39, $rival->page_authority);

        // Cached platform-wide on the shared asset for both products to reuse.
        $metric = DomainMetric::where('domain', 'rival.com')->first();
        $this->assertSame(812, $metric->dfs_referring_domains);
        $this->assertSame(47, $metric->moz_da);
    }

    public function test_discovery_does_not_classify_topics(): void
    {
        Http::fake([
            'api.dataforseo.com/*' => Http::response(['tasks' => [['result' => [['referring_domains' => 5, 'backlinks' => 9]]]]], 200),
            'lsapi.seomoz.com/*' => Http::response(['results' => [['domain_authority' => 10, 'page_authority' => 8]]], 200),
        ]);
        $website = $this->website();
        $this->fakeSerp(['alpha' => [['domain' => 'rival.com', 'position' => 1]]]);
        CompetitorDiscoveryRun::create(['run_id' => 'r2', 'website_id' => $website->id, 'status' => 'queued']);

        app(CompetitorDiscoveryService::class)->run('r2', ['alpha']);

        // Topical relevance is opt-in — discovery must never spend LLM tokens.
        $rival = DiscoveredCompetitor::where('competitor_domain', 'rival.com')->first();
        $this->assertNull($rival->topic);
        $this->assertNull($rival->topic_classified_at);
    }

    public function test_classify_topic_job_writes_topic_to_row_and_shared_asset(): void
    {
        $website = $this->website('mysite.com');
        DiscoveredCompetitor::create([
            'website_id' => $website->id, 'competitor_domain' => 'rival.com',
            'appearances' => 1, 'keywords_sampled' => 1, 'score' => 60, 'run_id' => 'r3',
        ]);

        $fetcher = Mockery::mock(CrawlFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn(['ok' => true, 'body' => '<title>Rival HVAC</title>']);
        $this->app->instance(CrawlFetcher::class, $fetcher);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'target_topic' => 'Home & Lifestyle',
            'domains' => [['domain' => 'rival.com', 'topic' => 'Home & Lifestyle', 'relevant' => true]],
        ]);
        $this->app->instance(LlmClient::class, $llm);

        (new ClassifyCompetitorTopicJob($website->id))->handle($fetcher, $llm);

        $rival = DiscoveredCompetitor::where('competitor_domain', 'rival.com')->first();
        $this->assertSame('Home & Lifestyle', $rival->topic);
        $this->assertNotNull($rival->topic_classified_at);
        $this->assertSame('Home & Lifestyle', DomainMetric::where('domain', 'rival.com')->value('topic'));
    }

    public function test_extract_series_parses_and_sorts_monthly_points(): void
    {
        $series = \App\Services\Competitive\CompetitorEnricher::extractSeries([
            'metrics' => ['organic' => [
                ['year' => 2026, 'month' => 7, 'etv' => 1499.6, 'count' => 360],
                ['year' => 2026, 'month' => 6, 'etv' => 1200, 'count' => 340],
            ]],
        ]);

        $this->assertSame('2026-06', $series[0]['month'], 'sorted ascending');
        $this->assertSame(1200, $series[0]['visits']);
        $this->assertSame(1500, $series[1]['visits'], 'etv rounded to visits');
        $this->assertSame(360, $series[1]['keywords']);
    }

    public function test_enrich_traffic_stores_series_and_raw_and_reuses_cache(): void
    {
        Http::fake([
            '*historical_bulk_traffic_estimation*' => Http::response([
                'tasks' => [['cost' => 0.02, 'result' => [['items' => [[
                    'target' => 'rival.com',
                    'metrics' => ['organic' => [
                        ['year' => 2026, 'month' => 6, 'etv' => 900, 'count' => 210],
                        ['year' => 2026, 'month' => 7, 'etv' => 1100, 'count' => 230],
                    ]],
                ]]]]]],
            ], 200),
        ]);

        $enricher = app(\App\Services\Competitive\CompetitorEnricher::class);
        $enricher->enrichTraffic(['rival.com'], sandbox: false);

        $metric = DomainMetric::where('domain', 'rival.com')->first();
        $this->assertNotNull($metric->dfs_traffic_refreshed_at);
        $this->assertCount(2, $metric->dfs_traffic_series);
        $this->assertSame(1100, $metric->dfs_traffic_series[1]['visits']);
        $this->assertIsArray($metric->dfs_traffic, 'full raw blob saved for reuse');

        // Fresh cache → a second call must NOT hit the API again.
        Http::fake(['*historical_bulk_traffic_estimation*' => Http::response([], 500)]);
        $enricher->enrichTraffic(['rival.com'], sandbox: false);
        $this->assertSame(1100, DomainMetric::where('domain', 'rival.com')->first()->dfs_traffic_series[1]['visits']);
    }

    public function test_discovery_fetches_organic_traffic_for_site_and_competitor(): void
    {
        Http::fake([
            '*backlinks/summary*' => Http::response(['tasks' => [['result' => [['referring_domains' => 3, 'backlinks' => 7]]]]], 200),
            'lsapi.seomoz.com/*' => Http::response(['results' => [['domain_authority' => 12, 'page_authority' => 9]]], 200),
            '*historical_bulk_traffic_estimation*' => Http::response([
                'tasks' => [['result' => [['items' => [
                    ['target' => 'mysite.com', 'metrics' => ['organic' => [['year' => 2026, 'month' => 7, 'etv' => 500, 'count' => 90]]]],
                    ['target' => 'rival.com', 'metrics' => ['organic' => [['year' => 2026, 'month' => 7, 'etv' => 4200, 'count' => 800]]]],
                ]]]]],
            ], 200),
        ]);

        $website = $this->website('mysite.com');
        $this->fakeSerp(['alpha' => [['domain' => 'rival.com', 'position' => 2]]]);
        CompetitorDiscoveryRun::create(['run_id' => 'rt', 'website_id' => $website->id, 'status' => 'queued']);

        app(CompetitorDiscoveryService::class)->run('rt', ['alpha']);

        // Both the site AND the competitor got a traffic series in one flat call.
        $this->assertSame(500, DomainMetric::where('domain', 'mysite.com')->first()->dfs_traffic_series[0]['visits']);
        $this->assertSame(4200, DomainMetric::where('domain', 'rival.com')->first()->dfs_traffic_series[0]['visits']);
    }

    public function test_classify_topic_reuses_cached_platform_topic_without_llm(): void
    {
        $website = $this->website('mysite.com');
        DiscoveredCompetitor::create([
            'website_id' => $website->id, 'competitor_domain' => 'known.com',
            'appearances' => 1, 'keywords_sampled' => 1, 'score' => 60, 'run_id' => 'r4',
        ]);
        // Already classified platform-wide → the job must still tag the row, but
        // the LLM answer for it is optional (cache wins).
        DomainMetric::create(['domain' => 'known.com', 'topic' => 'Finance & Insurance', 'topic_classified_at' => now()]);

        $fetcher = Mockery::mock(CrawlFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn(['ok' => false, 'body' => '']);
        $this->app->instance(CrawlFetcher::class, $fetcher);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('isAvailable')->andReturn(true);
        // Returns nothing new for known.com — the cached topic must still land.
        $llm->shouldReceive('completeJson')->andReturn(['target_topic' => 'Other', 'domains' => []]);
        $this->app->instance(LlmClient::class, $llm);

        (new ClassifyCompetitorTopicJob($website->id))->handle($fetcher, $llm);

        $this->assertSame('Finance & Insurance', DiscoveredCompetitor::where('competitor_domain', 'known.com')->value('topic'));
    }
}
