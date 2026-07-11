<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\InsightCards;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InsightsViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_insight_cards(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        // The card labels live inside the #[Lazy] InsightCards component —
        // a plain HTTP GET only renders its placeholder, so assert the page
        // loads, then render the component itself.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        Livewire::withoutLazyLoading();
        session(['current_website_id' => (string) $website->id]);

        Livewire::actingAs($user)
            ->test(InsightCards::class)
            ->assertSee('Cannibalizations')
            ->assertSee('Striking distance');
    }

    public function test_reports_page_renders_insights_panel(): void
    {
        $user = User::factory()->create();
        Website::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Insights')
            ->assertSee('Keyword cannibalization');
    }
}
