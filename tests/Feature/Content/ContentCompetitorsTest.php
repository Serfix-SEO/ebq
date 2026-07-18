<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
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
            ->set('wizardStep', 4)
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

    public function test_rejects_own_domain_and_invalid_domain(): void
    {
        [$user, $website, $plan] = $this->userWithPlan();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 4)
            ->set('newCompetitorDomain', $website->domain)
            ->call('addCompetitor')
            ->assertHasErrors('newCompetitorDomain');

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('wizardStep', 4)
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
            ->set('wizardStep', 4)
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
            ->set('wizardStep', 4)
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
            ->set('wizardStep', 4)
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
}
