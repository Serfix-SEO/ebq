<?php

namespace Tests\Feature;

use App\Jobs\TrackKeywordRankJob;
use App\Livewire\RankTracking\RankTrackingManager;
use App\Models\RankTrackingKeyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rank tracking can target ANY website's URL, not just the account's connected
 * site — the tracked `target_domain` is independent of the `website_id` (which
 * only groups the row under the account for billing).
 */
class RankTrackingTargetDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_track_a_keyword_for_a_foreign_domain(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)->test(RankTrackingManager::class)
            ->set('newKeyword', 'best gold dealer')
            ->set('newTargetDomain', 'competitor-site.com') // NOT the owned site
            ->call('addKeyword')
            ->assertHasNoErrors();

        $kw = RankTrackingKeyword::query()->where('keyword', 'best gold dealer')->first();
        $this->assertNotNull($kw);
        $this->assertEquals('competitor-site.com', $kw->target_domain);
        // Still grouped under the account's website for billing.
        $this->assertEquals($website->id, $kw->website_id);
        Bus::assertDispatched(TrackKeywordRankJob::class);
    }

    public function test_single_input_parses_domain_and_page_path(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)->test(RankTrackingManager::class)
            ->set('newKeyword', 'gold coins')
            ->set('newTargetDomain', 'competitor.com/gold-coins') // one field: domain + path
            ->call('addKeyword')
            ->assertHasNoErrors();

        $kw = RankTrackingKeyword::query()->where('keyword', 'gold coins')->first();
        $this->assertEquals('competitor.com', $kw->target_domain);
        $this->assertEquals('https://competitor.com/gold-coins', $kw->target_url);
    }

    public function test_target_domain_defaults_to_current_website(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)->test(RankTrackingManager::class)
            ->assertSet('newTargetDomain', 'mysite.com');
    }
}
