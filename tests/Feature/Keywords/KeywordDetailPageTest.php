<?php

namespace Tests\Feature\Keywords;

use App\Livewire\Keywords\KeywordDetail;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke coverage for the portal keyword deep-dive page — its signal
 * gathering moved into KeywordDetailService (shared with the plugin HQ
 * API), so this guards the component↔service seam.
 */
class KeywordDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_signals_for_the_session_website(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        SearchConsoleData::create([
            'website_id' => $website->id,
            'date' => now()->subDays(3)->toDateString(),
            'query' => 'best coffee grinder',
            'page' => 'https://example.com/a',
            'country' => 'usa',
            'device' => 'DESKTOP',
            'clicks' => 10,
            'impressions' => 300,
            'ctr' => 0.05,
            'position' => 12.0,
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(KeywordDetail::class, ['query' => 'best coffee grinder'])
            ->assertSee('best coffee grinder')
            ->assertViewHas('gsc_totals', fn ($t) => $t !== null && $t['impressions'] === 300)
            ->assertViewHas('top_pages', fn ($p) => count($p) === 1);
    }

    public function test_no_session_website_renders_without_signals(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(KeywordDetail::class, ['query' => 'anything'])
            ->assertViewHas('has_access', false)
            ->assertViewHas('gsc_totals', null);
    }
}
