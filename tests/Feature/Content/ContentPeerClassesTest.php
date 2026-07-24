<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\DomainMetric;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentSetupInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase D: peer/aspirational/reference classes + the peer-honest gap,
 * overlaid at read time by ContentSetupInsights::withOverrides().
 */
class ContentPeerClassesTest extends TestCase
{
    use RefreshDatabase;

    private function planWithSite(array $planAttrs = []): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'domain' => 'mysite.test', 'normalized_domain' => 'mysite.test',
        ]);
        $plan = ContentPlan::factory()->create(array_merge([
            'website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE,
        ], $planAttrs));

        return [$website, $plan];
    }

    private function insights(array $competitors, int $myReferring = 100, ?int $myAuthority = null): array
    {
        return [
            'my_referring_domains' => $myReferring,
            'my_authority' => $myAuthority,
            'competitors' => $competitors,
            'median' => null, 'gap' => null, 'behind' => false,
        ];
    }

    public function test_guard_references_are_labeled_reference_and_excluded_from_peer_median(): void
    {
        [, $plan] = $this->planWithSite([
            'competitor_guard' => ['auto' => [], 'references' => ['bigdirectory.test'], 'manual' => [], 'removed' => []],
        ]);
        DomainMetric::query()->create(['domain' => 'mysite.test', 'moz_da' => 30]);

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'rival.test', 'referring_domains' => 300, 'backlinks' => 900, 'authority' => null, 'da' => 35, 'pa' => 30],
            ['domain' => 'bigdirectory.test', 'referring_domains' => 90000, 'backlinks' => null, 'authority' => null, 'da' => 85, 'pa' => 70],
        ], myReferring: 100), $plan);

        $byDomain = collect($out['competitors'])->keyBy('domain');
        $this->assertSame('reference', $byDomain['bigdirectory.test']['class']);
        $this->assertSame('peer', $byDomain['rival.test']['class']);
        // Peer median ignores the 90k directory entirely.
        $this->assertSame(300, $out['peer_median']);
        $this->assertSame(3.0, $out['peer_gap']);
        $this->assertTrue($out['peer_behind']);
    }

    public function test_da_band_splits_peers_from_aspirational_rivals(): void
    {
        [, $plan] = $this->planWithSite();
        DomainMetric::query()->create(['domain' => 'mysite.test', 'moz_da' => 20]);

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'samesize.test', 'referring_domains' => 150, 'backlinks' => null, 'authority' => null, 'da' => 32, 'pa' => null],
            ['domain' => 'magazine.test', 'referring_domains' => 40000, 'backlinks' => null, 'authority' => null, 'da' => 70, 'pa' => null],
        ]), $plan);

        $byDomain = collect($out['competitors'])->keyBy('domain');
        $this->assertSame('peer', $byDomain['samesize.test']['class']);      // |32-20| ≤ 25
        $this->assertSame('aspirational', $byDomain['magazine.test']['class']); // 70 vs 20
    }

    public function test_unknown_data_defaults_to_peer_never_demotes_a_rival(): void
    {
        [, $plan] = $this->planWithSite();

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'nodata.test', 'referring_domains' => null, 'backlinks' => null, 'authority' => null, 'da' => null, 'pa' => null],
        ], myReferring: 0), $plan);

        $this->assertSame('peer', $out['competitors'][0]['class']);
    }

    // ── Signal-based giant detection (2026-07-23, flag-gated) ───────────

    public function test_unlisted_mega_retailer_classes_as_giant_by_scale(): void
    {
        [, $plan] = $this->planWithSite();
        DomainMetric::query()->create(['domain' => 'mysite.test', 'moz_da' => 32, 'dfs_referring_domains' => 684]);
        // sephora-profile: DA 85, 2M referring domains — NOT on GiantDomains.
        DomainMetric::query()->create(['domain' => 'megaretail.test', 'moz_da' => 85, 'dfs_referring_domains' => 2_000_000]);

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'megaretail.test', 'referring_domains' => 2_000_000, 'backlinks' => null, 'authority' => null, 'da' => 85, 'pa' => null],
            ['domain' => 'peerbrand.test', 'referring_domains' => 900, 'backlinks' => null, 'authority' => null, 'da' => 35, 'pa' => null],
        ], myReferring: 684), $plan);

        $byDomain = collect($out['competitors'])->keyBy('domain');
        $this->assertSame('giant', $byDomain['megaretail.test']['class']);
        $this->assertSame('peer', $byDomain['peerbrand.test']['class']);
        // Giants never poison the peer math.
        $this->assertSame(900, $out['peer_median']);
    }

    public function test_classifier_entity_type_alone_demotes_a_platform(): void
    {
        [, $plan] = $this->planWithSite();
        $plan->update(['competitor_guard' => [
            'assessed_at' => now()->toIso8601String(),
            'auto' => [['brand' => 'bigshop', 'domain' => 'bigshop.test', 'reason' => 'sells perfumes']],
            'references' => [],
            'entities' => ['bigshop.test' => 'retailer'],
        ]]);

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'bigshop.test', 'referring_domains' => 500, 'backlinks' => null, 'authority' => null, 'da' => 40, 'pa' => null],
        ]), $plan);

        $this->assertSame('giant', $out['competitors'][0]['class']);
    }

    public function test_flag_off_reverts_to_exact_prior_behavior(): void
    {
        \App\Models\Setting::set('content.giant_signals.enabled', false);
        [, $plan] = $this->planWithSite();
        DomainMetric::query()->create(['domain' => 'megaretail.test', 'moz_da' => 85, 'dfs_referring_domains' => 2_000_000]);

        $out = app(ContentSetupInsights::class)->withOverrides($this->insights([
            ['domain' => 'megaretail.test', 'referring_domains' => 2_000_000, 'backlinks' => null, 'authority' => null, 'da' => 85, 'pa' => null],
        ], myReferring: 100), $plan);

        // Old behavior: big rival = aspirational, stays in the ladder.
        $this->assertSame('aspirational', $out['competitors'][0]['class']);
    }

    public function test_empty_assessment_is_detected_and_rerun_when_competitors_land(): void
    {
        [$website, $plan] = $this->planWithSite();
        $guard = app(\App\Services\Content\CompetitorMentionGuard::class);

        // Assessment raced ahead of SERP discovery → assessed against nothing.
        $guard->assess($plan);
        $plan->refresh();
        $this->assertTrue($guard->assessed($plan));
        $this->assertTrue($guard->assessedEmpty($plan));

        // Competitors land → DiscoverContentCompetitorsJob invalidates + redispatches.
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Cache::put('content:serp-competitors:'.$website->id,
            [['domain' => 'rival.com']], now()->addDay());
        // Simulate the job's post-cache hook directly (the job itself needs live SERP calls).
        if ($guard->assessedEmpty($plan->fresh())) {
            $guard->invalidate($plan->fresh());
            \App\Jobs\AssessCompetitorGuardJob::dispatch($plan->id);
        }
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AssessCompetitorGuardJob::class);

        // Re-assessment with the real list (no LLM → fail-soft) is no longer empty.
        $plan = $plan->fresh();
        $this->assertFalse($guard->assessed($plan));
        $guard->assess($plan);
        $this->assertFalse($guard->assessedEmpty($plan->fresh()));
    }

    public function test_assessment_goes_stale_when_the_competitor_list_changes(): void
    {
        [$website, $plan] = $this->planWithSite();
        $guard = app(\App\Services\Content\CompetitorMentionGuard::class);

        \Illuminate\Support\Facades\Cache::put('content:serp-competitors:'.$website->id,
            [['domain' => 'rival-a.com']], now()->addDay());
        $guard->assess($plan->fresh());
        $this->assertFalse($guard->assessmentStale($plan->fresh()), 'fresh assessment matches its list');

        // 30-day re-discovery swaps the list wholesale (the job also forgets
        // the setup-insights cache — mirror that, or the old build is read).
        \Illuminate\Support\Facades\Cache::put('content:serp-competitors:'.$website->id,
            [['domain' => 'rival-b.com'], ['domain' => 'rival-c.com']], now()->addDay());
        app(ContentSetupInsights::class)->forget($website);
        $this->assertTrue($guard->assessmentStale($plan->fresh()), 'swapped list must read as stale');

        // Re-assessing against the new list clears staleness.
        $guard->assess($plan->fresh());
        $this->assertFalse($guard->assessmentStale($plan->fresh()));
    }

    public function test_cold_metrics_scale_rule_demotes_huge_refs_without_da(): void
    {
        // noon.com case: DA not fetched yet, 8,829 refs vs a 21-ref client.
        $this->assertTrue(\App\Support\GiantDomains::isScaleGiant(8829, null, null, 21));
        // A real mid-size rival (ajmal, 1.3k refs) stays safe even for tiny clients.
        $this->assertFalse(\App\Support\GiantDomains::isScaleGiant(1313, null, null, 21));
        // And noon is now on the static list too.
        $this->assertTrue(\App\Support\GiantDomains::isGiant('noon.com'));
        $this->assertTrue(\App\Support\GiantDomains::isGiant('namshi.com'));
    }

    public function test_rank_and_filter_demotes_giants_from_the_research_slot(): void
    {
        [, $plan] = $this->planWithSite();
        $plan->update(['competitor_guard' => [
            'assessed_at' => now()->toIso8601String(),
            'auto' => [
                ['brand' => 'megaretail', 'domain' => 'megaretail.test', 'reason' => 'rival'],
                ['brand' => 'peerbrand', 'domain' => 'peerbrand.test', 'reason' => 'rival'],
            ],
            'references' => [],
            'entities' => ['megaretail.test' => 'retailer'],
        ]]);

        $ordered = app(\App\Services\Content\CompetitorMentionGuard::class)
            ->rankAndFilter($plan, ['megaretail.test', 'peerbrand.test']);

        // Both survive (never dropped), but the platform sinks below the brand.
        $this->assertSame(['peerbrand.test', 'megaretail.test'], $ordered);
    }
}
