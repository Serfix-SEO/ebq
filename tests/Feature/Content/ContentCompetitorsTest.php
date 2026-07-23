<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\DomainMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentSetupInsights;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ContentCompetitorsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function userWithPlan(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT,
        ]);

        return [$user, $website, $plan];
    }

    public function test_manually_added_competitor_appears_and_fetches_moz_da_pa(): void
    {
        config(['services.moz.token' => 'fake-token']);
        Http::fake([
            'lsapi.seomoz.com/*' => Http::response([
                'results' => [['domain_authority' => 61, 'page_authority' => 55]],
            ], 200),
        ]);

        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'https://www.rival-example.com/pricing')
            ->call('addCompetitor')
            ->assertHasNoErrors()
            ->assertSee('rival-example.com')
            ->assertSee('61')
            ->assertSee('55');

        $this->assertSame(
            ['rival-example.com'],
            (array) ($plan->fresh()->competitor_overrides['added'] ?? [])
        );
    }

    public function test_dfs_referring_domains_and_backlinks_stored_and_reused_without_a_second_call(): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);
        Http::fake([
            'api.dataforseo.com/*' => Http::response([
                'tasks' => [[
                    'cost' => 0.024,
                    'result' => [['referring_domains' => 5792, 'backlinks' => 44211]],
                ]],
            ], 200),
        ]);

        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'dfs-rival.com')
            ->call('addCompetitor')
            ->assertSee('5,792')
            ->assertSee('44,211');

        $metric = DomainMetric::query()->where('domain', 'dfs-rival.com')->first();
        $this->assertSame(5792, $metric->dfs_referring_domains);
        $this->assertSame(44211, $metric->dfs_backlinks);
        $this->assertNotNull($metric->dfs_refreshed_at);

        Http::fake(['api.dataforseo.com/*' => Http::response(['tasks' => [['result' => []]]], 500)]);
        $row = app(ContentSetupInsights::class)->metricsForDomain('dfs-rival.com');
        $this->assertSame(5792, $row['referring_domains']);
        $this->assertSame(44211, $row['backlinks']);
    }

    public function test_admin_owned_site_sandboxes_dfs_and_never_persists_mock_data(): void
    {
        config(['services.dataforseo.login' => 'x', 'services.dataforseo.password' => 'y']);
        Http::fake([
            'sandbox.dataforseo.com/*' => Http::response([
                'tasks' => [['cost' => 0, 'result' => [['referring_domains' => 999, 'backlinks' => 9999]]]],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $website = Website::factory()->for($admin)->create();
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_DRAFT]);
        $this->actingAs($admin)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'admin-test-rival.com')
            ->call('addCompetitor')
            ->assertSee('999')
            ->assertSee('9,999');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sandbox.dataforseo.com'));

        $this->assertNull(
            DomainMetric::query()->where('domain', 'admin-test-rival.com')->first(),
            'Sandbox/mock DataForSEO responses must never be written into the shared domain_metrics asset.'
        );
    }

    public function test_dfs_not_configured_renders_dash_without_http_call(): void
    {
        config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
        Http::fake();

        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'no-dfs-example.com')
            ->call('addCompetitor')
            ->assertSee('no-dfs-example.com');

        Http::assertNothingSent();
    }

    public function test_rejects_own_domain_and_invalid_domain(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', $website->domain)
            ->call('addCompetitor')
            ->assertHasErrors('newCompetitorDomain');

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'not a domain')
            ->call('addCompetitor')
            ->assertHasErrors('newCompetitorDomain');

        $this->assertNull($plan->fresh()->competitor_overrides);
    }

    public function test_remove_competitor_persists_to_plan(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $plan->update(['competitor_overrides' => ['added' => ['rival-a.com', 'rival-b.com'], 'removed' => []]]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->call('removeCompetitor', 'rival-a.com')
            ->assertDontSee('rival-a.com')
            ->assertSee('rival-b.com');

        $overrides = $plan->fresh()->competitor_overrides;
        $this->assertSame(['rival-b.com'], array_values($overrides['added']));
        $this->assertContains('rival-a.com', $overrides['removed']);
    }

    public function test_removing_an_auto_discovered_competitor_filters_it_out(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();

        $insights = [
            'my_referring_domains' => 10, 'my_authority' => 40,
            'competitors' => [
                ['domain' => 'auto-a.com', 'referring_domains' => 50, 'authority' => 30, 'da' => null, 'pa' => null],
                ['domain' => 'auto-b.com', 'referring_domains' => 20, 'authority' => 20, 'da' => null, 'pa' => null],
            ],
            'median' => 35, 'gap' => 3.5, 'behind' => true,
        ];
        Cache::put('content:setup-insights:v1:'.$website->id, $insights, now()->addDay());

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->assertSee('auto-a.com')
            ->assertSee('auto-b.com')
            ->call('removeCompetitor', 'auto-a.com')
            ->assertDontSee('auto-a.com')
            ->assertSee('auto-b.com');
    }

    public function test_moz_not_configured_renders_dash_without_http_call(): void
    {
        config(['services.moz.token' => null, 'services.moz.access_id' => null, 'services.moz.secret_key' => null]);
        Http::fake(); // any real call would be recorded and we assert none happened

        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'no-moz-example.com')
            ->call('addCompetitor')
            ->assertSee('no-moz-example.com');

        Http::assertNothingSent();
    }

    public function test_content_setup_insights_with_overrides_merges_add_and_remove(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $plan->update(['competitor_overrides' => [
            'added' => ['manual-rival.com'],
            'removed' => ['auto-gone.com'],
        ]]);

        $svc = app(ContentSetupInsights::class);
        $base = [
            'my_referring_domains' => 5, 'my_authority' => 10,
            'competitors' => [
                ['domain' => 'auto-gone.com', 'referring_domains' => 5, 'authority' => 5, 'da' => null, 'pa' => null],
                ['domain' => 'auto-stays.com', 'referring_domains' => 8, 'authority' => 8, 'da' => null, 'pa' => null],
            ],
            'median' => 5, 'gap' => 1.0, 'behind' => false,
        ];

        $merged = $svc->withOverrides($base, $plan->fresh());
        $domains = array_column($merged['competitors'], 'domain');

        $this->assertNotContains('auto-gone.com', $domains);
        $this->assertContains('auto-stays.com', $domains);
        $this->assertContains('manual-rival.com', $domains);
    }

    public function test_reset_competitors_clears_overrides(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $plan->update(['competitor_overrides' => ['added' => ['a.com'], 'removed' => ['b.com']]]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->call('resetCompetitors');

        $this->assertNull($plan->fresh()->competitor_overrides);
    }

    public function test_removing_every_competitor_shows_reset_prompt_and_reset_restores_them(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        Cache::put('content:setup-insights:v1:'.$website->id, [
            'my_referring_domains' => 10, 'my_authority' => 40,
            'competitors' => [
                ['domain' => 'only-a.com', 'referring_domains' => 5, 'authority' => 5, 'da' => null, 'pa' => null],
            ],
            'median' => null, 'gap' => null, 'behind' => false,
        ], now()->addDay());
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->call('removeCompetitor', 'only-a.com')
            ->assertSee("You've removed every competitor")
            ->assertSee('Reset to auto-discovered');

        $component->call('resetCompetitors')
            ->assertSee('only-a.com')
            ->assertDontSee("You've removed every competitor");
    }

    public function test_moz_da_pa_persisted_to_domain_metrics_and_reused_without_a_second_call(): void
    {
        config(['services.moz.token' => 'fake-token']);
        Http::fake([
            'lsapi.seomoz.com/*' => Http::response([
                'results' => [['domain_authority' => 70, 'page_authority' => 60]],
            ], 200),
        ]);

        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 5)
            ->set('newCompetitorDomain', 'shared-rival.com')
            ->call('addCompetitor')
            ->assertSee('70')
            ->assertSee('60');

        $metric = DomainMetric::query()->where('domain', 'shared-rival.com')->first();
        $this->assertNotNull($metric, 'Moz DA/PA should be stored on the shared domain_metrics table.');
        $this->assertSame(70, $metric->moz_da);
        $this->assertSame(60, $metric->moz_pa);
        $this->assertNotNull($metric->moz_refreshed_at);

        // A second, unrelated website looking up the SAME domain must reuse
        // the stored value rather than calling Moz again.
        Http::fake(['lsapi.seomoz.com/*' => Http::response(['results' => []], 500)]);
        $svc = app(ContentSetupInsights::class);
        $row = $svc->metricsForDomain('shared-rival.com');
        $this->assertSame(70, $row['da']);
        $this->assertSame(60, $row['pa']);
    }

    /**
     * Owner decision 2026-07-22: SERP discovery is the PRIMARY competitor
     * source (layer 1, kept in SERP order), mega-platforms are filtered from
     * every source, and the step shows up to 10.
     */
    public function test_serp_competitors_are_the_primary_source_in_serp_order_giants_filtered(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create(['normalized_domain' => 'mysite.test']);

        // 12 SERP rows in tally order, with giants interleaved. No report
        // snapshot exists at all — SERP alone must be enough to render.
        $serp = [['domain' => 'amazon.com'], ['domain' => 'rival-1.com'], ['domain' => 'netflix.com']];
        for ($i = 2; $i <= 12; $i++) {
            $serp[] = ['domain' => "rival-{$i}.com"];
        }
        Cache::put('content:serp-competitors:'.$website->id, $serp, now()->addDay());

        $insights = app(ContentSetupInsights::class)->competitorAuthority($website);

        $this->assertNotNull($insights, 'SERP rows alone render the step — no paid report required');
        $domains = array_column($insights['competitors'], 'domain');
        $this->assertNotContains('amazon.com', $domains);
        $this->assertNotContains('netflix.com', $domains);
        $this->assertCount(10, $domains, 'capped at 10 for the competitors step');
        // SERP order preserved — no authority re-sort.
        $this->assertSame('rival-1.com', $domains[0]);
        $this->assertSame('rival-10.com', $domains[9]);
    }
}
