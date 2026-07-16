<?php

namespace Tests\Feature;

use App\Jobs\EnrichEmptyReportJob;
use App\Jobs\FinalizeReportEnrichmentJob;
use App\Jobs\GenerateWebsiteReport;
use App\Models\KeywordApiRequest;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\Competitive\SerpCache;
use App\Services\Crawler\CrawlFetcher;
use App\Services\DataForSeoBacklinkClient;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\Llm\LlmClient;
use App\Services\MozLinksClient;
use App\Services\OpenPageRankClient;
use App\Services\ReportFreshnessGate;
use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Empty-domain enrichment: a DataForSEO summary miss must produce a PARTIAL
 * report (popularity + gauges + keywords / competitor opportunities) instead
 * of the old terminal "no data" dead end. Every provider is container-mocked;
 * the Http::fake baseline fails loudly if anything tries a real call.
 */
class ReportEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 500)]);
    }

    // ------------------------------------------------------------ helpers

    private function mockDfsWithNullSummary(): DataForSeoBacklinkClient
    {
        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn(true);
        $dfs->shouldReceive('useSandbox');
        $dfs->shouldReceive('summary')->andReturn(null);
        $dfs->shouldReceive('totalCost')->andReturn(0.02);
        $this->app->instance(DataForSeoBacklinkClient::class, $dfs);

        return $dfs;
    }

    /**
     * @param  array<string, mixed>  $overrides  service constructor mocks to override
     */
    private function makeService(array $overrides = []): ReportEnrichmentService
    {
        $opr = $overrides['opr'] ?? tap(Mockery::mock(OpenPageRankClient::class), function ($m) {
            $m->shouldReceive('metricsFor')->andReturn([
                'newsite.com' => ['rank' => 5400000, 'score' => 1.2, 'referring_domains' => 0, 'history' => []],
            ])->byDefault();
        });
        $moz = $overrides['moz'] ?? tap(Mockery::mock(MozLinksClient::class), function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true)->byDefault();
            $m->shouldReceive('urlMetrics')->andReturn(['domain_authority' => 3, 'page_authority' => 5, 'spam_score' => 1])->byDefault();
        });
        $fetcher = $overrides['fetcher'] ?? tap(Mockery::mock(CrawlFetcher::class), function ($m) {
            $m->shouldReceive('fetch')->andReturn([
                'ok' => true,
                'status' => 200,
                'body' => '<html><body><h1>Handmade oak furniture</h1><p>We build custom oak tables and chairs in Austin.</p></body></html>',
                'content_type' => 'text/html',
            ])->byDefault();
        });
        $pool = $overrides['pool'] ?? tap(Mockery::mock(KeywordFinderPool::class), function ($m) {
            $m->shouldReceive('buildIdeasPayload')->andReturnUsing(
                fn (array $opts, ?string $ck = null) => ['website', ['url' => $opts['url'], 'scope' => 'site', 'location' => 'us', 'language' => 'en']],
            )->byDefault();
            $m->shouldReceive('dispatchIdeas')->andReturnUsing(function () {
                return KeywordApiRequest::create([
                    'request_id' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => KeywordApiRequest::TYPE_IDEAS,
                    'mode' => 'website',
                    'payload' => [],
                    'status' => KeywordApiRequest::STATUS_RUNNING,
                ]);
            })->byDefault();
        });
        $serp = $overrides['serp'] ?? tap(Mockery::mock(SerpCache::class), function ($m) {
            $m->shouldReceive('organic')->andReturn(['organic' => []])->byDefault();
        });
        $llm = $overrides['llm'] ?? tap(Mockery::mock(LlmClient::class), function ($m) {
            $m->shouldReceive('completeJson')->andReturn(['genuine' => true, 'queries' => []])->byDefault();
        });

        foreach (['opr' => OpenPageRankClient::class, 'moz' => MozLinksClient::class, 'fetcher' => CrawlFetcher::class,
            'pool' => KeywordFinderPool::class, 'serp' => SerpCache::class, 'llm' => LlmClient::class] as $key => $class) {
            $this->app->instance($class, ${$key});
        }

        return new ReportEnrichmentService(
            $opr, $moz, $fetcher, $pool, $serp, $llm,
            app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\Competitive\CompetitorDiscoveryService::class),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function completeRequest(string $requestId, array $rows): void
    {
        KeywordApiRequest::where('request_id', $requestId)->firstOrFail()
            ->markCompleted(['results' => $rows]);
    }

    // ------------------------------------------------------------ tests

    public function test_null_summary_starts_enrichment_for_eligible_domain(): void
    {
        Bus::fake([EnrichEmptyReportJob::class]);
        $this->mockDfsWithNullSummary();

        (new GenerateWebsiteReport('newsite.com'))->handle(
            $this->app->make(DataForSeoBacklinkClient::class),
            app(MozLinksClient::class), app(OpenPageRankClient::class),
            app(ReportFreshnessGate::class), app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\ClientActivityLogger::class),
        );

        $snapshot = WebsiteReportSnapshot::forDomain('newsite.com');
        $this->assertEquals('enriching', $snapshot->status);
        $this->assertEquals('bootstrap', $snapshot->enrichment_state['stage'] ?? null);
        Bus::assertDispatched(EnrichEmptyReportJob::class, fn ($job) => $job->domain === 'newsite.com' && ! $job->keywordsOnly);
    }

    public function test_null_summary_stays_terminal_when_enrichment_disabled(): void
    {
        Bus::fake([EnrichEmptyReportJob::class]);
        config(['services.report.enrichment.enabled' => false]);
        $this->mockDfsWithNullSummary();

        (new GenerateWebsiteReport('newsite.com'))->handle(
            $this->app->make(DataForSeoBacklinkClient::class),
            app(MozLinksClient::class), app(OpenPageRankClient::class),
            app(ReportFreshnessGate::class), app(\App\Services\Reports\ClientReportService::class),
            app(\App\Services\ClientActivityLogger::class),
        );

        $this->assertEquals('no_data', WebsiteReportSnapshot::forDomain('newsite.com')->status);
        Bus::assertNotDispatched(EnrichEmptyReportJob::class);
    }

    public function test_bootstrap_gathers_signals_and_awaits_keywords(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching',
            'enrichment_state' => ['stage' => 'bootstrap'], 'fetched_at' => now(),
        ]);

        $this->makeService()->bootstrap('newsite.com');

        $state = WebsiteReportSnapshot::forDomain('newsite.com')->enrichment_state;
        $this->assertEquals('await_own_keywords', $state['stage']);
        $this->assertNotEmpty($state['own_request']['id']);
        $this->assertNotEmpty($state['page_text']);
        $this->assertEquals(3, $state['moz']['domain_authority']);
        Bus::assertDispatched(FinalizeReportEnrichmentJob::class);
    }

    public function test_keyword_fleet_down_finalizes_minimal_partial(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching',
            'enrichment_state' => ['stage' => 'bootstrap'], 'fetched_at' => now(),
        ]);

        $pool = Mockery::mock(KeywordFinderPool::class);
        $pool->shouldReceive('buildIdeasPayload')->andReturn(['website', ['url' => 'https://newsite.com', 'scope' => 'site', 'location' => 'us', 'language' => 'en']]);
        $pool->shouldReceive('dispatchIdeas')->andReturnUsing(function () {
            $req = KeywordApiRequest::create([
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => KeywordApiRequest::TYPE_IDEAS, 'mode' => 'website',
                'payload' => [], 'status' => KeywordApiRequest::STATUS_QUEUED,
            ]);
            $req->markFailed('down');

            return $req->fresh();
        });

        $this->makeService(['pool' => $pool])->bootstrap('newsite.com');

        $snapshot = WebsiteReportSnapshot::forDomain('newsite.com');
        $this->assertEquals('partial', $snapshot->status);
        $this->assertTrue((bool) $snapshot->payload['meta']['partial']);
        $this->assertEquals(1.2, $snapshot->payload['popularity']['score']);
        $this->assertEquals(3, $snapshot->payload['gauges']['domain_authority']);
        Bus::assertNotDispatched(FinalizeReportEnrichmentJob::class);
    }

    public function test_genuine_keywords_finalize_with_serp_competitors(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);

        // Competitors are discovered via SERP for EVERY new-site enrichment,
        // genuine keywords or not — only the opportunities stage is fallback-only.
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'genuine' => true,
            'queries' => ['oak dining table', 'custom oak furniture austin'],
        ]);
        $serp = Mockery::mock(SerpCache::class);
        $serp->shouldReceive('organic')->times(2)->andReturn(['organic' => [
            ['position' => 1, 'link' => 'https://bigoak.com/tables'],
            ['position' => 2, 'link' => 'https://newsite.com/tables'], // self — excluded
        ]]);
        $opr = Mockery::mock(OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn([
            'newsite.com' => ['rank' => 5400000, 'score' => 1.2, 'referring_domains' => 0, 'history' => []],
            'bigoak.com' => ['rank' => 90000, 'score' => 5.1, 'referring_domains' => 900, 'history' => []],
        ]);

        $service = $this->makeService(['serp' => $serp, 'llm' => $llm, 'opr' => $opr]);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching', 'fetched_at' => now(),
            'enrichment_state' => ['stage' => 'bootstrap'],
        ]);
        $service->bootstrap('newsite.com');
        $requestId = WebsiteReportSnapshot::forDomain('newsite.com')->enrichment_state['own_request']['id'];

        $this->completeRequest($requestId, [
            ['keyword' => 'oak dining table', 'avgMonthlySearches' => 4400, 'highTopOfPageBid' => 1.8, 'competition' => 'HIGH'],
            ['keyword' => 'custom oak furniture', 'avgMonthlySearches' => 880, 'highTopOfPageBid' => 2.4, 'competition' => 'MEDIUM'],
        ]);

        $this->assertTrue($service->advance('newsite.com'));

        $snapshot = WebsiteReportSnapshot::forDomain('newsite.com');
        $this->assertEquals('partial', $snapshot->status);
        $this->assertNull($snapshot->enrichment_state);
        $keywords = $snapshot->payload['keywords'];
        $this->assertCount(2, $keywords);
        $this->assertEquals('oak dining table', $keywords[0]['keyword']); // volume-sorted
        $this->assertEquals(4400, $keywords[0]['volume']);
        $this->assertEquals('estimated', $snapshot->payload['meta']['sources']['keywords']);
        // Competitors present even on the genuine path…
        $this->assertEquals('bigoak.com', $snapshot->payload['competitors'][0]['domain']);
        $this->assertEquals('search_results', $snapshot->payload['meta']['sources']['competitors']);
        // …but competitor keyword-borrowing stays fallback-only.
        $this->assertSame([], $snapshot->payload['keyword_opportunities']);
    }

    public function test_boilerplate_keywords_trigger_competitor_pipeline(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);
        config(['services.report.enrichment.serp_query_cap' => 3]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->once()->andReturn([
            'genuine' => false,
            'queries' => ['custom oak table austin', 'handmade oak furniture', 'oak chairs for sale', 'extra query beyond cap'],
        ]);

        $serp = Mockery::mock(SerpCache::class);
        $serp->shouldReceive('organic')->times(3)->andReturn(['organic' => [
            ['position' => 1, 'link' => 'https://bigoak.com/tables', 'domain' => 'bigoak.com'],
            ['position' => 2, 'link' => 'https://www.wikipedia.org/wiki/Oak', 'domain' => 'wikipedia.org'], // giant — excluded
            ['position' => 3, 'link' => 'https://newsite.com/about', 'domain' => 'newsite.com'],           // self — excluded
            ['position' => 4, 'link' => 'https://oakrivals.com/shop', 'domain' => 'oakrivals.com'],
        ]]);

        $opr = Mockery::mock(OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn([
            'newsite.com' => ['rank' => 5400000, 'score' => 1.2, 'referring_domains' => 0, 'history' => []],
            'bigoak.com' => ['rank' => 90000, 'score' => 5.1, 'referring_domains' => 900, 'history' => []],
            'oakrivals.com' => ['rank' => 300000, 'score' => 3.9, 'referring_domains' => 300, 'history' => []],
        ]);

        $service = $this->makeService(['llm' => $llm, 'serp' => $serp, 'opr' => $opr]);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching', 'fetched_at' => now(),
            'enrichment_state' => ['stage' => 'bootstrap'],
        ]);
        $service->bootstrap('newsite.com');
        $ownId = WebsiteReportSnapshot::forDomain('newsite.com')->enrichment_state['own_request']['id'];
        $this->completeRequest($ownId, [
            ['keyword' => 'signup', 'avgMonthlySearches' => 100000],
            ['keyword' => 'login', 'avgMonthlySearches' => 200000],
        ]);

        // Own keywords judged boilerplate → SERP tally → competitor dispatch.
        $this->assertFalse($service->advance('newsite.com'));

        $state = WebsiteReportSnapshot::forDomain('newsite.com')->enrichment_state;
        $this->assertEquals('await_competitor_keywords', $state['stage']);
        $this->assertEquals('bigoak.com', $state['opportunity_source']); // best score wins
        $domains = array_column($state['competitors'], 'domain');
        $this->assertContains('oakrivals.com', $domains);
        $this->assertNotContains('wikipedia.org', $domains);
        $this->assertNotContains('newsite.com', $domains);

        $this->completeRequest($state['competitor_request']['id'], [
            ['keyword' => 'solid oak dining set', 'avgMonthlySearches' => 1300, 'highTopOfPageBid' => 2.1],
        ]);

        $this->assertTrue($service->advance('newsite.com'));

        $snapshot = WebsiteReportSnapshot::forDomain('newsite.com');
        $this->assertEquals('partial', $snapshot->status);
        $this->assertEquals('solid oak dining set', $snapshot->payload['keyword_opportunities'][0]['keyword']);
        $this->assertEquals('bigoak.com', $snapshot->payload['meta']['opportunity_source']);
        $this->assertEquals('similar_site', $snapshot->payload['meta']['sources']['keyword_opportunities']);
        $this->assertEquals('search_results', $snapshot->payload['meta']['sources']['competitors']);
    }

    public function test_timed_out_enrichment_finalizes_and_never_sticks(): void
    {
        Bus::fake();
        $service = $this->makeService();

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching', 'fetched_at' => now(),
            'enrichment_state' => [
                'stage' => 'await_own_keywords',
                'started_at' => now()->subHours(2)->toIso8601String(),
                'attempts' => 5,
                'own_request' => ['id' => (string) \Illuminate\Support\Str::uuid(), 'cache_key' => ''],
                'popularity' => ['rank' => 5400000, 'score' => 1.2, 'history' => []],
            ],
        ]);

        $this->assertTrue($service->advance('newsite.com'));

        $snapshot = WebsiteReportSnapshot::forDomain('newsite.com');
        $this->assertEquals('partial', $snapshot->status);
        $this->assertNull($snapshot->enrichment_state);
    }

    public function test_report_view_shows_enriching_state_without_redispatch(): void
    {
        Bus::fake([GenerateWebsiteReport::class]);
        $user = User::factory()->create();
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching',
            'enrichment_state' => ['stage' => 'bootstrap'], 'fetched_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/report/view?url=newsite.com');

        $response->assertOk()->assertSee('gathering the closest available data', false);
        Bus::assertNotDispatched(GenerateWebsiteReport::class);
    }

    public function test_partial_report_renders_banner_badges_and_keyword_sections(): void
    {
        $user = User::factory()->create();
        $payload = app(\App\Services\Reports\ClientReportService::class)->assemblePartial('newsite.com', [
            'opr' => ['rank' => 5400000, 'score' => 1.2, 'history' => []],
            'moz' => ['domain_authority' => 3, 'page_authority' => 5, 'spam_score' => 1],
            'keywords' => [['keyword' => 'oak dining table', 'volume' => 4400, 'cpc' => 1.8, 'competition' => 'High']],
            'keyword_opportunities' => [['keyword' => 'solid oak dining set', 'volume' => 1300, 'cpc' => 2.1, 'competition' => null]],
            'competitors' => [['domain' => 'bigoak.com', 'shared_keywords' => 3, 'avg_position' => 1.0, 'popularity_rank' => 90000, 'opr_score' => 5.1]],
            'opportunity_source' => 'bigoak.com',
        ]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'partial',
            'payload' => $payload, 'fetched_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/report/view?url=newsite.com');

        $response->assertOk()
            ->assertSee('looks like a new website')
            ->assertSee('Keywords this site can rank for')
            ->assertSee('Estimated')
            ->assertSee('Keyword opportunities')
            ->assertSee('From a similar site: bigoak.com')
            ->assertSee('oak dining table')
            ->assertSee('Found via related search results')
            ->assertSee('Not enough backlink data yet');
    }

    public function test_partial_snapshot_expires_on_short_ttl_but_ready_stays_fresh(): void
    {
        $gate = app(ReportFreshnessGate::class);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'young.com', 'status' => 'partial',
            'payload' => ['domain' => 'young.com'], 'fetched_at' => now()->subDays(11),
        ]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'old.com', 'status' => 'ready',
            'payload' => ['domain' => 'old.com'], 'fetched_at' => now()->subDays(11),
        ]);

        $this->assertFalse($gate->isFresh('young.com'), 'partial snapshot must expire on the 10-day TTL');
        $this->assertTrue($gate->isFresh('old.com'), 'full report keeps the 90-day TTL');
    }

    public function test_ready_report_gets_keywords_merged_asynchronously(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);
        $service = $this->makeService();

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'established.com', 'status' => 'ready',
            // Has competitors already → only the keyword section is backfilled.
            'payload' => ['domain' => 'established.com', 'gauges' => [], 'meta' => [],
                'competitors' => [['domain' => 'rival.com', 'shared_keywords' => 5]]],
            'fetched_at' => now(),
        ]);

        $service->bootstrapReadyKeywords('established.com');
        $state = WebsiteReportSnapshot::forDomain('established.com')->enrichment_state;
        $this->assertEquals('ready_merge', $state['stage']);

        $this->completeRequest($state['requests']['keywords']['id'], [
            ['keyword' => 'established brand shoes', 'avgMonthlySearches' => 900, 'highTopOfPageBid' => 0.9],
        ]);

        $this->assertTrue($service->advance('established.com'));

        $snapshot = WebsiteReportSnapshot::forDomain('established.com');
        $this->assertEquals('ready', $snapshot->status);
        $this->assertNull($snapshot->enrichment_state);
        $this->assertEquals('established brand shoes', $snapshot->payload['keywords'][0]['keyword']);
        $this->assertEquals('estimated', $snapshot->payload['meta']['sources']['keywords']);
    }

    public function test_ready_report_missing_competitors_discovers_via_serp(): void
    {
        Bus::fake([FinalizeReportEnrichmentJob::class]);

        // DataForSEO gave a full report but NO competitors → SERP discovery.
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->andReturn(['genuine' => true, 'queries' => ['seo agency london']]);
        $serp = Mockery::mock(SerpCache::class);
        $serp->shouldReceive('organic')->andReturn(['organic' => [
            ['position' => 1, 'link' => 'https://rivalseo.com/services'],
            ['position' => 2, 'link' => 'https://established.com/home'], // self — excluded
        ]]);
        $opr = Mockery::mock(OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn([
            'established.com' => ['rank' => 900000, 'score' => 2.0, 'history' => []],
            'rivalseo.com' => ['rank' => 120000, 'score' => 4.5, 'history' => []],
        ]);

        $service = $this->makeService(['llm' => $llm, 'serp' => $serp, 'opr' => $opr]);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'established.com', 'status' => 'ready',
            // Full report, keywords already present, but competitors empty.
            'payload' => ['domain' => 'established.com', 'gauges' => [], 'meta' => [],
                'competitors' => [], 'keywords' => array_fill(0, 100, ['keyword' => 'x', 'volume' => 1])],
            'fetched_at' => now(),
        ]);

        $service->bootstrapReadyKeywords('established.com');

        $snapshot = WebsiteReportSnapshot::forDomain('established.com');
        $this->assertEquals('rivalseo.com', $snapshot->payload['competitors'][0]['domain']);
        $this->assertEquals('search_results', $snapshot->payload['meta']['sources']['competitors']);
        $this->assertTrue($snapshot->payload['meta']['competitors_enriched']);
        // A best competitor was found → its keyword opportunities are being fetched.
        $this->assertEquals('ready_merge', $snapshot->enrichment_state['stage']);
        $this->assertArrayHasKey('opportunities', $snapshot->enrichment_state['requests']);
    }

    public function test_stale_schema_report_triggers_background_refresh_on_view(): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);
        Bus::fake([GenerateWebsiteReport::class]);
        $user = User::factory()->create();

        // A pre-upgrade payload (no meta.schema) — fresh by TTL, so it renders
        // as-is but must kick a one-time background regeneration.
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'oldschema.com', 'status' => 'ready',
            'payload' => ['domain' => 'oldschema.com', 'gauges' => [], 'totals' => []],
            'fetched_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/report/view?url=oldschema.com')->assertOk();

        Bus::assertDispatched(GenerateWebsiteReport::class, fn ($job) => $job->domain === 'oldschema.com' && $job->force);
    }

    public function test_current_schema_report_does_not_refresh(): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);
        Bus::fake([GenerateWebsiteReport::class]);
        $user = User::factory()->create();

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newschema.com', 'status' => 'ready',
            'payload' => ['domain' => 'newschema.com', 'gauges' => [], 'totals' => [],
                'meta' => ['schema' => \App\Services\Reports\ClientReportService::PAYLOAD_SCHEMA]],
            'fetched_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/report/view?url=newschema.com')->assertOk();

        Bus::assertNotDispatched(GenerateWebsiteReport::class);
    }

    public function test_gsc_connected_site_gets_real_queries_instead_of_estimates(): void
    {
        $user = User::factory()->create();
        $account = \App\Models\GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website = Website::create([
            'user_id' => $user->id, 'domain' => 'established.com',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => 'https://established.com/', 'gsc_google_account_id' => $account->id,
        ]);
        \Illuminate\Support\Facades\DB::table('search_console_data')->insert([
            ['id' => (string) \Illuminate\Support\Str::ulid(), 'website_id' => $website->id, 'date' => now()->subDays(3)->toDateString(), 'query' => 'real brand shoes', 'clicks' => 40, 'impressions' => 900, 'position' => 3.2, 'page' => '', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::ulid(), 'website_id' => $website->id, 'date' => now()->subDays(2)->toDateString(), 'query' => 'real brand shoes', 'clicks' => 25, 'impressions' => 600, 'position' => 2.8, 'page' => '', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::ulid(), 'website_id' => $website->id, 'date' => now()->subDays(2)->toDateString(), 'query' => 'buy shoes online', 'clicks' => 5, 'impressions' => 300, 'position' => 9.1, 'page' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $payload = app(\App\Services\Reports\ClientReportService::class)->withTraffic([
            'domain' => 'established.com',
            'keywords' => [['keyword' => 'planner estimate', 'volume' => 100, 'cpc' => 1.0, 'competition' => 'Low']],
            'meta' => ['sources' => ['keywords' => 'estimated']],
        ], $website);

        // Real Search Console queries replace the planner estimates, ranked by clicks.
        $this->assertEquals('gsc', $payload['meta']['sources']['keywords']);
        $this->assertEquals('real brand shoes', $payload['keywords'][0]['keyword']);
        $this->assertEquals(65, $payload['keywords'][0]['clicks']);
        $this->assertEquals(1500, $payload['keywords'][0]['impressions']);

        // No GSC → estimates untouched.
        $noGsc = app(\App\Services\Reports\ClientReportService::class)->withTraffic([
            'keywords' => [['keyword' => 'planner estimate', 'volume' => 100]],
            'meta' => ['sources' => ['keywords' => 'estimated']],
        ], null);
        $this->assertEquals('estimated', $noGsc['meta']['sources']['keywords']);
        $this->assertEquals('planner estimate', $noGsc['keywords'][0]['keyword']);
    }

    public function test_admin_can_remove_a_domains_report_cache(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'stale.com', 'status' => 'no_data', 'fetched_at' => now(),
        ]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'sbx:stale.com', 'status' => 'ready',
            'payload' => ['domain' => 'stale.com'], 'fetched_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.site-explorer-usage.index'))
            ->post(route('admin.site-explorer-usage.clear-cache'), ['domain' => 'https://STALE.com/']);

        $response->assertRedirect(route('admin.site-explorer-usage.index'));
        $response->assertSessionHas('cache_cleared');
        $this->assertEquals(0, WebsiteReportSnapshot::where('normalized_domain', 'like', '%stale.com')->count());
    }

    public function test_non_admin_cannot_remove_report_cache(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'stale.com', 'status' => 'no_data', 'fetched_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.site-explorer-usage.clear-cache'), ['domain' => 'stale.com'])
            ->assertForbidden();
        $this->assertNotNull(WebsiteReportSnapshot::forDomain('stale.com'));
    }

    public function test_discover_competitors_uses_keywords_directly_when_genuine(): void
    {
        config(['services.report.enrichment.serp_query_cap' => 5]);

        // Genuine keywords → NO crawl, NO query-gen; keywords ARE the queries.
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('completeJson')->once()->andReturn(['genuine' => true]); // junk-check only
        $fetcher = Mockery::mock(CrawlFetcher::class);
        $fetcher->shouldReceive('fetch')->never(); // must not crawl
        $serp = Mockery::mock(SerpCache::class);
        $serp->shouldReceive('organic')->times(2)->andReturn(['organic' => [
            ['position' => 1, 'link' => 'https://rival.com/x'],
        ]]);
        $opr = Mockery::mock(OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn(['rival.com' => ['rank' => 100, 'score' => 5.0, 'history' => []]]);

        $service = $this->makeService(['llm' => $llm, 'fetcher' => $fetcher, 'serp' => $serp, 'opr' => $opr]);
        $result = $service->discoverCompetitorsFor('newsite.com', [
            ['keyword' => 'oak dining table', 'volume' => 900],
            ['keyword' => 'custom oak chairs', 'volume' => 500],
        ]);

        $this->assertFalse($result['scrap']);
        $this->assertEquals('keywords', $result['query_source']);
        $this->assertEquals('rival.com', $result['competitors'][0]['domain']);
        $this->assertEquals(['oak dining table', 'custom oak chairs'], $result['queries']);
    }

    public function test_discover_competitors_crawls_for_queries_when_scrap(): void
    {
        config(['services.report.enrichment.serp_query_cap' => 5]);

        $llm = Mockery::mock(LlmClient::class);
        // First call = junk-check (scrap); second = classifyAndQueries from page text.
        $llm->shouldReceive('completeJson')->once()->ordered()->andReturn(['genuine' => false]);
        $llm->shouldReceive('completeJson')->once()->ordered()->andReturn(['genuine' => false, 'queries' => ['handmade oak furniture austin']]);
        $fetcher = Mockery::mock(CrawlFetcher::class);
        $fetcher->shouldReceive('fetch')->atLeast()->once()->andReturn([
            'ok' => true, 'status' => 200, 'body' => '<html><body><h1>Oak furniture</h1></body></html>', 'content_type' => 'text/html',
        ]);
        $serp = Mockery::mock(SerpCache::class);
        $serp->shouldReceive('organic')->once()->andReturn(['organic' => [['position' => 1, 'link' => 'https://bigoak.com/']]]);
        $opr = Mockery::mock(OpenPageRankClient::class);
        $opr->shouldReceive('metricsFor')->andReturn(['bigoak.com' => ['rank' => 90, 'score' => 6.0, 'history' => []]]);

        $service = $this->makeService(['llm' => $llm, 'fetcher' => $fetcher, 'serp' => $serp, 'opr' => $opr]);
        $result = $service->discoverCompetitorsFor('newsite.com', [
            ['keyword' => 'login', 'volume' => 5000],
            ['keyword' => 'sign up', 'volume' => 4000],
        ]);

        $this->assertTrue($result['scrap']);
        $this->assertEquals('page_content', $result['query_source']);
        $this->assertEquals('bigoak.com', $result['competitors'][0]['domain']);
    }

    public function test_website_tab_status_reflects_enriching_and_partial(): void
    {
        $user = User::factory()->create();
        $website = Website::create([
            'user_id' => $user->id, 'domain' => 'newsite.com',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => '', 'gsc_google_account_id' => null,
        ]);

        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'enriching',
            'enrichment_state' => ['stage' => 'bootstrap'], 'fetched_at' => now(),
        ]);
        $this->actingAs($user);
        $status = app(\App\Services\WebsiteTabStatus::class)->forWebsite($website)['explorer'];
        $this->assertEquals('processing', $status['state']);

        WebsiteReportSnapshot::forDomain('newsite.com')->forceFill([
            'status' => 'partial', 'payload' => ['domain' => 'newsite.com'], 'enrichment_state' => null,
        ])->save();
        $status = app(\App\Services\WebsiteTabStatus::class)->forWebsite($website)['explorer'];
        $this->assertEquals('ready', $status['state']);
    }
}
