<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_backlinks(): void
    {
        $this->get(route('backlinks.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_accessible_website_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('backlinks.index'))->assertRedirect(route('onboarding'));
    }

    public function test_user_with_only_shared_website_can_view_backlinks_page(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'shared-backlinks.test']);
        $website->members()->attach($member->id);

        $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk();
    }

    public function test_scores_are_backfilled_onto_pre_score_snapshots_without_regeneration(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'scored-site.test']);

        // A cached snapshot from BEFORE Trust/Citation scores existed: current
        // schema, no `scores` key, no per-row `cs`. The read path must augment
        // it in-memory (backfill-on-read) — and must NOT dispatch a paid
        // regeneration, since scores need no new provider data.
        \App\Models\WebsiteReportSnapshot::create([
            'normalized_domain' => 'scored-site.test',
            'domain_authority' => 41,
            'payload' => [
                'domain' => 'scored-site.test',
                'popularity' => ['score' => 4.7, 'rank' => 1639043, 'history' => []],
                'gauges' => ['domain_authority' => 41, 'page_authority' => 38, 'spam_score' => 3, 'authority_score' => 47],
                'totals' => ['backlinks' => 24318, 'referring_domains' => 1842, 'referring_ips' => 1650, 'referring_subnets' => 1400],
                'ratios' => ['dofollow_pct' => 62, 'active_pct' => 94],
                'history' => [],
                'anchor_types' => ['branded' => 61, 'naked' => 19, 'generic' => 12, 'exact' => 8],
                'top_referring_domains' => [
                    ['domain' => 'en.wikipedia.org', 'rank' => 890, 'backlinks' => 1, 'first_seen' => '2024-03', 'opr_score' => 9.1],
                    ['domain' => 'smallblog.net', 'rank' => 120, 'backlinks' => 5, 'first_seen' => '2025-01', 'opr_score' => 2.1],
                    ['domain' => 'spamdir.xyz', 'rank' => 10, 'backlinks' => 44, 'first_seen' => '2025-06', 'opr_score' => 0.4],
                ],
                'anchors' => [],
                'backlinks' => [],
                'top_pages' => [],
                'competitors' => [],
                'profile_details' => null,
                'traffic' => null,
                'meta' => ['schema' => \App\Services\Reports\ClientReportService::PAYLOAD_SCHEMA],
            ],
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk()
            ->assertSee('TrustSignal')
            ->assertSee('CiteSignal')
            ->assertSee('/100');

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\GenerateWebsiteReport::class);
    }

    public function test_render_cap_shows_upgrade_message(): void
    {
        \App\Models\Plan::create(['slug' => 'trial', 'name' => 'Trial',
            'api_limits' => ['report' => ['max_backlink_rows' => 2, 'allow_link_drilldown' => 0]]]);
        $user = User::factory()->create(['current_plan_slug' => 'trial']);
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'capped.test']);

        \App\Models\WebsiteReportSnapshot::create([
            'normalized_domain' => 'capped.test',
            'payload' => [
                'domain' => 'capped.test',
                'gauges' => [], 'totals' => [], 'ratios' => [], 'anchor_types' => [],
                'backlinks' => [
                    ['url_from' => 'https://a.test/1', 'url_to' => 'https://capped.test/', 'anchor' => 'one', 'dofollow' => true],
                    ['url_from' => 'https://b.test/2', 'url_to' => 'https://capped.test/', 'anchor' => 'two', 'dofollow' => true],
                    ['url_from' => 'https://c.test/3', 'url_to' => 'https://capped.test/', 'anchor' => 'three', 'dofollow' => true],
                ],
                'meta' => ['schema' => \App\Services\Reports\ClientReportService::PAYLOAD_SCHEMA],
            ],
            'status' => 'ready',
            'fetched_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk()
            ->assertSee('Showing 2 of 3 backlinks')
            ->assertSee('Upgrade to see them all')
            ->assertSee('https://a.test/1')
            ->assertDontSee('https://c.test/3');
    }

    public function test_onboarded_user_can_view_backlinks_page(): void
    {
        // /backlinks is the read-only Site Explorer backlink view since
        // 2026-07-14 — the legacy manual "Add backlink" CRUD was unrouted.
        // No snapshot exists for this factory domain and DataForSEO isn't
        // configured in tests, so the "unavailable" state renders (never
        // the old CRUD form, never a provider call).
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk()
            ->assertSee('Backlinks')
            ->assertDontSee('Add backlink')
            ->assertDontSee('Bulk edit by date');
    }
}
