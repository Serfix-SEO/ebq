<?php

namespace Tests\Feature;

use App\Jobs\EnrichEmptyReportJob;
use App\Jobs\GenerateWebsiteReport;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Keywords\WebsiteKeywordSuggestions;
use App\Support\KeywordJunkHeuristic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * /keywords page fallback: when a site has no usable GSC data (not connected,
 * no rows, or only scrap auth/nav queries) it surfaces keyword-server +
 * competitor suggestions from the Site Explorer enrichment — same pipeline as
 * the report.
 */
class KeywordSuggestionsFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDfs(bool $configured = true): void
    {
        $dfs = Mockery::mock(DataForSeoBacklinkClient::class);
        $dfs->shouldReceive('isConfigured')->andReturn($configured);
        $this->app->instance(DataForSeoBacklinkClient::class, $dfs);
    }

    private function website(): Website
    {
        return Website::create([
            'user_id' => User::factory()->create()->id, 'domain' => 'newsite.com',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => '', 'gsc_google_account_id' => null,
        ]);
    }

    public function test_junk_heuristic_flags_auth_nav_and_brand_terms(): void
    {
        $this->assertTrue(KeywordJunkHeuristic::isJunk('login'));
        $this->assertTrue(KeywordJunkHeuristic::isJunk('create account'));
        $this->assertTrue(KeywordJunkHeuristic::isJunk('acme', 'acme.com'));
        $this->assertTrue(KeywordJunkHeuristic::isJunk('acme login', 'acme.com'));
        $this->assertFalse(KeywordJunkHeuristic::isJunk('best running shoes'));
        $this->assertTrue(KeywordJunkHeuristic::mostlyJunk(['login', 'sign up', 'acme'], 'acme.com'));
        $this->assertFalse(KeywordJunkHeuristic::mostlyJunk(['running shoes', 'trail shoes', 'login'], 'acme.com'));
        $this->assertTrue(KeywordJunkHeuristic::mostlyJunk([], 'acme.com')); // empty = nothing to show
    }

    public function test_ready_when_snapshot_has_keywords(): void
    {
        $this->fakeDfs();
        Bus::fake();
        $website = $this->website();
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'partial', 'fetched_at' => now(),
            'payload' => [
                'domain' => 'newsite.com',
                'keywords' => [['keyword' => 'oak table', 'volume' => 400, 'cpc' => 1.2]],
                'keyword_opportunities' => [['keyword' => 'oak chairs', 'volume' => 90]],
                'meta' => ['sources' => ['keywords' => 'estimated'], 'opportunity_source' => 'bigoak.com'],
            ],
        ]);

        $result = app(WebsiteKeywordSuggestions::class)->for($website);

        $this->assertEquals('ready', $result['status']);
        $this->assertEquals('oak table', $result['keywords'][0]['keyword']);
        $this->assertEquals('oak chairs', $result['opportunities'][0]['keyword']);
        $this->assertEquals('bigoak.com', $result['opportunity_source']);
        Bus::assertNothingDispatched();
    }

    public function test_no_snapshot_kicks_full_generation_and_reports_processing(): void
    {
        $this->fakeDfs();
        Bus::fake([GenerateWebsiteReport::class, EnrichEmptyReportJob::class]);
        $website = $this->website();

        $result = app(WebsiteKeywordSuggestions::class)->for($website);

        $this->assertEquals('processing', $result['status']);
        Bus::assertDispatched(GenerateWebsiteReport::class, fn ($j) => $j->domain === 'newsite.com');
        Bus::assertNotDispatched(EnrichEmptyReportJob::class);
    }

    public function test_ready_report_without_keywords_backfills_via_fleet_only(): void
    {
        $this->fakeDfs();
        Bus::fake([GenerateWebsiteReport::class, EnrichEmptyReportJob::class]);
        $website = $this->website();
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'ready', 'fetched_at' => now(),
            'payload' => ['domain' => 'newsite.com', 'gauges' => [], 'meta' => ['schema' => 2]], // no keywords
        ]);

        $result = app(WebsiteKeywordSuggestions::class)->for($website);

        $this->assertEquals('processing', $result['status']);
        // A full report exists → backfill keywords only (free fleet), never re-bill DataForSEO.
        Bus::assertDispatched(EnrichEmptyReportJob::class, fn ($j) => $j->domain === 'newsite.com' && $j->keywordsOnly);
        Bus::assertNotDispatched(GenerateWebsiteReport::class);
    }

    public function test_unavailable_when_provider_not_configured(): void
    {
        $this->fakeDfs(configured: false);
        Bus::fake();
        $website = $this->website();

        $result = app(WebsiteKeywordSuggestions::class)->for($website);

        $this->assertEquals('unavailable', $result['status']);
        Bus::assertNothingDispatched();
    }

    public function test_competitor_finder_reuses_report_competitors_without_discovery(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\DiscoverCompetitorsJob::class]);
        $this->actingAs(User::factory()->create());
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'hasreport.com', 'status' => 'ready', 'fetched_at' => now(),
            'payload' => ['domain' => 'hasreport.com', 'competitors' => [
                ['domain' => 'rival-x.com', 'shared_keywords' => 40, 'avg_position' => 3.1, 'opr_score' => 5.5],
            ]],
        ]);

        $c = \Livewire\Livewire::test(\App\Livewire\Competitive\CompetitorFinder::class, ['url' => ''])
            ->set('url', 'hasreport.com')->call('run');

        $c->assertSet('status', 'done')->assertSet('querySource', 'report');
        $this->assertEquals('rival-x.com', $c->get('competitors')[0]['domain']);
        // Reused report data → no keyword-fleet / SERP discovery kicked off.
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\DiscoverCompetitorsJob::class);
    }

    public function test_research_hub_url_param_opens_ideas_in_website_mode(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $this->actingAs(User::factory()->create());

        \Livewire\Livewire::test(\App\Livewire\Keywords\KeywordResearch::class, ['url' => 'https://mysite.example'])
            ->assertSet('tab', 'ideas')
            ->assertSet('handoff.mode', 'website')
            ->assertSet('handoff.url', 'https://mysite.example');
    }

    public function test_keywords_page_switches_from_ideas_to_real_gsc_when_synced(): void
    {
        $this->fakeDfs();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $user = User::factory()->create();
        $website = Website::create([
            'user_id' => $user->id, 'domain' => 'newsite.com',
            'ga_property_id' => '', 'ga_google_account_id' => null,
            'gsc_site_url' => '', 'gsc_google_account_id' => null,
        ]);
        // Enriched keyword ideas already cached in the snapshot.
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'newsite.com', 'status' => 'partial', 'fetched_at' => now(),
            'payload' => [
                'domain' => 'newsite.com',
                'keywords' => [['keyword' => 'idea keyword', 'volume' => 500, 'cpc' => 0.5]],
                'meta' => ['sources' => ['keywords' => 'estimated']],
            ],
        ]);
        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        // No GSC rows yet → ideas fallback shown.
        $c = \Livewire\Livewire::test(\App\Livewire\Keywords\KeywordsTable::class);
        $c->assertSee('Keyword ideas for your site')->assertSee('idea keyword');

        // Next GSC sync lands REAL (non-junk) keywords.
        \Illuminate\Support\Facades\DB::table('search_console_data')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'website_id' => $website->id,
            'date' => now()->subDay()->toDateString(), 'query' => 'organic pizza recipe',
            'clicks' => 30, 'impressions' => 500, 'ctr' => 0.06, 'position' => 4.2, 'page' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Re-render → switches to the real GSC keyword, ideas fallback gone.
        $c->call('$refresh')
            ->assertSee('organic pizza recipe')
            ->assertDontSee('Keyword ideas for your site');
    }

    public function test_clusters_view_shows_one_topic_default_first_and_switches(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $this->actingAs(User::factory()->create());

        $results = [
            ['keyword' => 'alpha widget', 'avgMonthlySearches' => 5000],
            ['keyword' => 'beta gadget', 'avgMonthlySearches' => 800],
        ];
        $clusterMap = ['alpha widget' => 'Widgets', 'beta gadget' => 'Gadgets'];

        $c = \Livewire\Livewire::test(\App\Livewire\Keywords\KeywordIdeaFinder::class)
            ->set('results', $results)->set('hasRun', true)
            ->set('clusterMap', $clusterMap)->set('viewMode', 'clusters');

        // Default: highest-volume topic (Widgets) only.
        $c->assertSee('alpha widget')->assertDontSee('beta gadget');

        // Switch topic → only its keywords.
        $c->call('setTopic', 'Gadgets')
            ->assertSet('topic', 'Gadgets')
            ->assertSee('beta gadget')->assertDontSee('alpha widget');
    }

    public function test_idea_finder_website_preset_seeds_and_runs_website_mode(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $this->actingAs(User::factory()->create());

        // No KeywordApiServer rows → run() fails fast (no real HTTP), but the
        // website mode + URL must be seeded on mount before the run attempt.
        \Livewire\Livewire::test(\App\Livewire\Keywords\KeywordIdeaFinder::class, [
            'preset' => ['mode' => 'website', 'url' => 'https://mysite.example', 'scope' => 'site'],
        ])
            ->assertSet('mode', 'website')
            ->assertSet('url', 'https://mysite.example')
            ->assertSet('scope', 'site');
    }
}
