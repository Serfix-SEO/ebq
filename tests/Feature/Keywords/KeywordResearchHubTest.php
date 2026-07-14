<?php

namespace Tests\Feature\Keywords;

use App\Jobs\TrackKeywordRankJob;
use App\Livewire\Keywords\KeywordIdeaFinder;
use App\Livewire\Keywords\KeywordResearch;
use App\Livewire\Keywords\KeywordVolumeFinder;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class KeywordResearchHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_defaults_and_switches_and_ignores_invalid(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(KeywordResearch::class)
            ->assertSet('tab', 'ideas')
            ->call('setTab', 'volume')->assertSet('tab', 'volume')
            ->call('setTab', 'bogus')->assertSet('tab', 'volume');
    }

    public function test_url_tab_param_is_respected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(KeywordResearch::class, ['tab' => 'volume'])->assertSet('tab', 'volume');

        // Gap left the hub for its own page (2026-07-14) — old ?tab=gap
        // deep links redirect there.
        Livewire::test(KeywordResearch::class, ['tab' => 'gap'])
            ->assertRedirect(route('keyword-gap.index'));
    }

    public function test_kw_query_param_becomes_a_preset_handoff(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(KeywordResearch::class, ['tab' => 'volume', 'kw' => 'running shoes']);

        $handoff = $component->get('handoff');
        $this->assertSame('volume', $handoff['target']);
        $this->assertSame(['running shoes'], $handoff['keywords']);
    }

    public function test_handoff_switches_tab_and_stores_payload(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(KeywordResearch::class)
            ->dispatch('research-handoff', target: 'volume', keywords: ['running shoes'])
            ->assertSet('tab', 'volume');

        $handoff = $component->get('handoff');
        $this->assertSame('volume', $handoff['target']);
        $this->assertSame(['running shoes'], $handoff['keywords']);
        $this->assertNotEmpty($handoff['nonce']);
    }

    public function test_manual_tab_switch_clears_handoff(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(KeywordResearch::class)
            ->dispatch('research-handoff', target: 'volume', keywords: ['x'])
            ->call('setTab', 'ideas')
            ->assertSet('handoff', []);
    }

    public function test_volume_preset_prefills_and_runs_from_cache(): void
    {
        config(['services.keywords_everywhere.key' => 'k']);
        $this->actingAs(User::factory()->create());
        Http::fake();

        KeywordMetric::create([
            'keyword' => 'running shoes',
            'keyword_hash' => KeywordMetric::hashKeyword('running shoes'),
            'country' => 'global', 'data_source' => 'gkp', 'search_volume' => 5000,
            'fetched_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        Livewire::test(KeywordVolumeFinder::class, ['preset' => ['keywords' => ['running shoes']]])
            ->assertSet('keywords', 'running shoes')
            ->assertSet('hasRun', true);

        Http::assertNothingSent(); // fully cache-served
    }

    public function test_ideas_preset_prefills_seeds(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(KeywordIdeaFinder::class, ['preset' => ['keywords' => ['running shoes'], 'mode' => 'seeds']])
            ->assertSet('seedsInput', 'running shoes')
            ->assertSet('mode', 'seeds');
    }

    public function test_track_creates_rank_tracking_row_and_queues_check(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        Livewire::test(KeywordVolumeFinder::class)
            ->call('track', 'running shoes')
            ->assertSet('trackNotice', fn ($v) => is_string($v) && str_contains($v, 'rank tracker'));

        $this->assertDatabaseHas('rank_tracking_keywords', [
            'website_id' => $website->id, 'keyword' => 'running shoes',
        ]);
        Queue::assertPushed(TrackKeywordRankJob::class);
    }

    public function test_old_tool_routes_redirect_to_the_hub(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Website::factory()->create(['user_id' => $user->id]); // satisfies onboarded gate
        $this->actingAs($user);

        $this->get('/keyword-volume')->assertRedirect('/keyword-research?tab=volume');
        $this->get('/keyword-ideas')->assertRedirect('/keyword-research?tab=ideas');
        // Gap has its own page since 2026-07-14.
        $this->get('/competitive')->assertRedirect('/keyword-gap');
    }
}
