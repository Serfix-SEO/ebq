<?php

namespace Tests\Feature\Content;

use App\Models\ContentPlan;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression (prod 2026-07-21): step 2 of the wizard refused with "Content
 * Autopilot is not included in your plan." for a site the user was PAYING for.
 *
 * `effectiveFeatureFlags()` short-circuits every flag to false for a frozen
 * website. Freeze is a DASHBOARD concept — `websiteLimit()` — and a
 * content-only customer has no dashboard plan, so their limit is 1 and their
 * SECOND website froze. Content Autopilot is billed separately with explicit
 * per-site coverage, so the dashboard limit must not revoke it.
 */
class ContentFrozenWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: User, 1: Website} A frozen website with covered content. */
    private function frozenButPaidSite(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);

        // First site consumes the dashboard website allowance…
        Website::factory()->for($user)->create([
            'domain' => 'first.test',
            'created_at' => now()->subDay(),
        ]);

        // …so this one is over the limit and freezes — but it IS covered.
        $content = Website::factory()->for($user)->create(['domain' => 'content.test']);
        ContentPlan::factory()->create([
            'website_id' => $content->id,
            'billing_covered_at' => now(),
        ]);

        return [$user, $content->fresh()];
    }

    public function test_a_frozen_site_keeps_content_autopilot_when_it_is_covered(): void
    {
        [, $website] = $this->frozenButPaidSite();

        $this->assertTrue($website->isFrozen(), 'precondition: over the dashboard website limit');
        $this->assertTrue(
            $website->effectiveFeatureFlags()['content_autopilot'],
            'a separately-billed, covered site must keep Content Autopilot while frozen'
        );
    }

    /** Freeze must still do its job for everything the dashboard plan covers. */
    public function test_a_frozen_site_still_loses_every_dashboard_feature(): void
    {
        [, $website] = $this->frozenButPaidSite();

        $flags = $website->effectiveFeatureFlags();
        $enabled = array_keys(array_filter($flags, fn ($v) => $v === true));

        $this->assertSame(['content_autopilot'], $enabled, 'nothing else may survive the freeze');
    }

    /** A frozen site with NO content coverage stays fully dark. */
    public function test_a_frozen_site_without_coverage_gets_nothing(): void
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create(['domain' => 'first.test', 'created_at' => now()->subDay()]);
        $extra = Website::factory()->for($user)->create(['domain' => 'extra.test']);

        $this->assertTrue($extra->fresh()->isFrozen());
        $this->assertFalse($extra->fresh()->effectiveFeatureFlags()['content_autopilot']);
    }

    /** The global kill switch must still win over a frozen-but-covered site. */
    public function test_the_global_kill_switch_still_beats_the_freeze_exemption(): void
    {
        [, $website] = $this->frozenButPaidSite();
        Setting::set('global_feature_flags', ['content_autopilot' => false]);

        $this->assertFalse($website->fresh()->effectiveFeatureFlags()['content_autopilot']);
    }
}
