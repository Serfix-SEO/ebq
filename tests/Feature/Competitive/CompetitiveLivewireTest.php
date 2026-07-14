<?php

namespace Tests\Feature\Competitive;

use App\Livewire\Competitive\CompetitorDiscovery;
use App\Livewire\Competitive\KeywordGapAnalysis;
use App\Models\DiscoveredCompetitor;
use App\Models\KeywordGapAnalysis as GapAnalysis;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitiveLivewireTest extends TestCase
{
    use RefreshDatabase;

    private function actingWebsite(callable $factory = null): Website
    {
        $user = User::factory()->create();
        $website = ($factory ? $factory(Website::factory()) : Website::factory())
            ->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        $this->actingAs($user);
        session(['current_website_id' => $website->id]);

        return $website;
    }

    public function test_competitor_discovery_renders_and_validates_seeds_without_gsc(): void
    {
        $this->actingWebsite(fn ($f) => $f->withNoSources());

        Livewire::test(CompetitorDiscovery::class)
            ->assertOk()
            ->call('discover')
            ->assertSet('errorMessage', fn ($v) => is_string($v) && str_contains($v, 'seed keywords'));
    }

    public function test_keyword_gap_prefills_discovered_competitors_and_validates(): void
    {
        $website = $this->actingWebsite(fn ($f) => $f->withGscOnly());
        DiscoveredCompetitor::create([
            'website_id' => $website->id, 'competitor_domain' => 'rival.com',
            'appearances' => 5, 'keywords_sampled' => 10, 'score' => 80, 'run_id' => 'r1',
        ]);

        Livewire::test(KeywordGapAnalysis::class)
            ->assertOk()
            ->assertSet('competitors.0', 'rival.com')
            ->set('competitors', ['', '', ''])
            ->call('run')
            ->assertSet('errorMessage', fn ($v) => is_string($v) && str_contains($v, 'competitor'));
    }

    // The per-row "refine" action (computeLive) was removed 2026-07-14 —
    // superseded by the batch "Verify" flow, which recomputes scores from the
    // same SERP response AND captures positions / re-buckets.
}
