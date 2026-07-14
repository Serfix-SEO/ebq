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
}
