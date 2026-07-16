<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebsiteReport;
use App\Livewire\Competitive\KeywordGapAnalysis;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Keyword Gap competitor picker sources suggestions from the website's
 * Site Explorer snapshot (2026-07-14) — a free cache READ that must never
 * dispatch a billed generation as a side effect of opening the tab.
 */
class KeywordGapCompetitorPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        // Realistic provider config so a dispatch slip would be visible
        // (not masked by an isConfigured() short-circuit).
        config(['services.dataforseo.login' => 'test', 'services.dataforseo.password' => 'test']);
    }

    private function siteWithSnapshot(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'gap-picker.com']);
        WebsiteReportSnapshot::create([
            'normalized_domain' => 'gap-picker.com',
            'status' => 'ready',
            'fetched_at' => now(),
            'payload' => [
                'competitors' => [
                    ['domain' => 'rival-a.com', 'shared_keywords' => 300, 'avg_position' => 4.2, 'opr_score' => 5.1],
                    ['domain' => 'rival-b.com', 'shared_keywords' => 200, 'avg_position' => 6.0, 'opr_score' => 4.0],
                    ['domain' => 'rival-c.com', 'shared_keywords' => 100, 'avg_position' => 9.9, 'opr_score' => 3.2],
                    ['domain' => 'rival-d.com', 'shared_keywords' => 50, 'avg_position' => 12.0, 'opr_score' => 2.0],
                ],
            ],
        ]);

        return [$user, $website];
    }

    public function test_suggestions_load_from_snapshot_and_top_three_preselect(): void
    {
        [$user, $website] = $this->siteWithSnapshot();
        session(['current_website_id' => $website->id]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);

        $this->assertCount(4, $c->get('suggested'));
        $this->assertSame(['rival-a.com', 'rival-b.com', 'rival-c.com'], $c->get('competitors'));
        // Free cache read only — never a billed generation from opening the tab.
        Queue::assertNotPushed(GenerateWebsiteReport::class);
    }

    public function test_toggle_respects_the_max_competitor_cap(): void
    {
        [$user, $website] = $this->siteWithSnapshot();
        session(['current_website_id' => $website->id]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);

        // 4th selection over the cap of 3 → rejected with a message.
        $c->call('toggleCompetitor', 'rival-d.com');
        $this->assertCount(3, $c->get('competitors'));
        $this->assertNotNull($c->get('errorMessage'));

        // Deselect then reselect works.
        $c->call('toggleCompetitor', 'rival-a.com');
        $c->call('toggleCompetitor', 'rival-d.com');
        $this->assertContains('rival-d.com', $c->get('competitors'));
        $this->assertNotContains('rival-a.com', $c->get('competitors'));
    }

    public function test_manual_domain_is_normalized_and_added(): void
    {
        [$user, $website] = $this->siteWithSnapshot();
        session(['current_website_id' => $website->id]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);
        $c->call('toggleCompetitor', 'rival-a.com'); // free a slot

        $c->set('manualDomain', 'https://www.manual-rival.com/some/page')
            ->call('addManualCompetitor');

        $this->assertContains('manual-rival.com', $c->get('competitors'));
        $this->assertSame('', $c->get('manualDomain'));
    }

    public function test_no_snapshot_shows_empty_suggestions_without_dispatching(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'no-snapshot.com']);
        session(['current_website_id' => $website->id]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);

        $this->assertSame([], $c->get('suggested'));
        Queue::assertNotPushed(GenerateWebsiteReport::class);
    }

    public function test_target_defaults_to_current_site_and_switches_to_foreign(): void
    {
        [$user, $website] = $this->siteWithSnapshot();
        session(['current_website_id' => $website->id]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);
        // Defaults to the current owned site → its snapshot competitors load.
        $c->assertSet('targetUrl', 'gap-picker.com')->assertSet('targetIsForeign', false);
        $this->assertNotEmpty($c->get('suggested'));

        // Switch to a foreign URL → not owned, competitors cleared for manual add.
        $c->set('targetUrl', 'https://someone-elses-site.com')
            ->assertSet('targetIsForeign', true);
        $this->assertSame([], $c->get('suggested'));
        $this->assertSame([], $c->get('competitors'));
    }

    public function test_url_query_param_prefills_target_and_loads_context(): void
    {
        $user = User::factory()->create();
        session(['current_website_id' => '']);

        // Simulates ?url=foreign.com (the #[Url] binding) — must NOT be
        // overwritten by the current-website default, and must resolve foreign.
        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class, ['targetUrl' => 'foreign.com']);
        $c->assertSet('targetUrl', 'foreign.com')->assertSet('targetIsForeign', true);
    }

    public function test_find_competitors_inline_populates_from_discovery_cache(): void
    {
        $user = User::factory()->create();
        session(['current_website_id' => '']);
        // A prior discovery result already cached for this domain.
        \Illuminate\Support\Facades\Cache::put(
            \App\Jobs\DiscoverCompetitorsJob::cacheKey('foreign.com'),
            ['status' => 'done', 'competitors' => [['domain' => 'found-rival.com', 'shared_keywords' => 7, 'avg_position' => 2.0, 'opr_score' => 4.0]]],
            now()->addDay(),
        );

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class, ['targetUrl' => 'foreign.com'])
            ->call('findCompetitors');

        $c->assertSet('findStatus', 'done');
        $this->assertEquals('found-rival.com', $c->get('suggested')[0]['domain']);
        $this->assertContains('found-rival.com', $c->get('competitors'));
    }

    public function test_track_button_tracks_the_analyzed_target_domain(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        session(['current_website_id' => $website->id]);

        // Analyzing a competitor URL — Track must record it for THAT domain.
        Livewire::actingAs($user)->test(KeywordGapAnalysis::class)
            ->set('targetUrl', 'competitor.ca')
            ->set('country', 'ca')
            ->call('track', 'buy gold bars')
            ->assertSet('trackNotice', fn ($n) => str_contains((string) $n, 'buy gold bars'));

        $kw = \App\Models\RankTrackingKeyword::query()->where('keyword', 'buy gold bars')->first();
        $this->assertNotNull($kw);
        $this->assertEquals('competitor.ca', $kw->target_domain); // the analyzed target, not mysite.com
        $this->assertEquals('ca', $kw->country);
        $this->assertEquals($website->id, $kw->website_id); // still billed to the account site
    }

    public function test_saved_analysis_is_restored_on_visit_without_new_lookups(): void
    {
        Queue::fake();
        [$user, $website] = $this->siteWithSnapshot();
        session(['current_website_id' => $website->id]);
        $saved = \App\Models\KeywordGapAnalysis::create([
            'website_id' => $website->id, 'user_id' => $user->id, 'our_url' => 'gap-picker.com',
            'competitor_urls' => ['rival-a.com'], 'country' => 'us', 'status' => 'completed',
            'completed_at' => now()->subDay(), 'expires_at' => now()->addDays(10),
            'summary' => ['missing' => 3, 'weak' => 0, 'strength' => 0, 'shared' => 1],
        ]);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class);

        // Reopens the saved report instantly — no dispatches, no provider calls.
        $c->assertSet('analysisId', $saved->id)->assertSet('status', 'completed');
        $this->assertEquals(['rival-a.com'], $c->get('competitors'));
        Queue::assertNotPushed(GenerateWebsiteReport::class);
    }

    public function test_load_analysis_denies_other_users_foreign_runs(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = \App\Models\KeywordGapAnalysis::create([
            'website_id' => null, 'user_id' => $owner->id, 'our_url' => 'foreign.com',
            'competitor_urls' => ['rival.com'], 'country' => 'us', 'status' => 'completed',
            'completed_at' => now(), 'expires_at' => now()->addDays(10),
        ]);
        session(['current_website_id' => '']);

        $c = Livewire::actingAs($other)->test(KeywordGapAnalysis::class)
            ->call('loadAnalysis', $foreign->id);

        $c->assertNotSet('analysisId', $foreign->id); // not readable by others
    }

    public function test_foreign_target_run_uses_url_not_owned_website(): void
    {
        config(['services.keyword_finder.enabled' => true]);
        $user = User::factory()->create();
        session(['current_website_id' => '']);

        $c = Livewire::actingAs($user)->test(KeywordGapAnalysis::class)
            ->set('targetUrl', 'foreign-target.com')
            ->set('competitors', ['rival.com'])
            ->call('run');

        // A gap analysis row was created with NO website_id (foreign target).
        $analysis = \App\Models\KeywordGapAnalysis::query()->where('our_url', 'foreign-target.com')->first();
        $this->assertNotNull($analysis);
        $this->assertNull($analysis->website_id);
    }
}
