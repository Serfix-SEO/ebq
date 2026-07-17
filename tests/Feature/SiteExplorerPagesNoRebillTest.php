<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebsiteReport;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cost guard for the Pulse Backlinks + Competitors pages (and the /overview
 * Explorer tab, which shares the same ReportViewController::resolve() path):
 * opening them for a domain whose snapshot already exists must NEVER
 * dispatch a new DataForSEO generation — the whole point of the shared
 * per-domain cache is that re-viewing is free. Generation may only be
 * dispatched when no usable snapshot exists at all.
 */
class SiteExplorerPagesNoRebillTest extends TestCase
{
    use RefreshDatabase;

    private function userWithSite(string $domain): array
    {
        // Real-looking provider config so isConfigured() is true — the
        // dispatch decision must come from the snapshot/freshness logic,
        // not from "provider unconfigured" short-circuits. Http::fake() +
        // Queue::fake() make any slip impossible to bill.
        config(['services.dataforseo.login' => 'test', 'services.dataforseo.password' => 'test']);
        Http::fake();
        Queue::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => $domain]);

        return [$user, $website];
    }

    private function seedSnapshot(string $domain): void
    {
        WebsiteReportSnapshot::create([
            'normalized_domain' => $domain,
            'status' => 'ready',
            'fetched_at' => now()->subDay(),
            'payload' => [
                'domain' => $domain,
                // Current schema — an OLD-schema snapshot deliberately triggers
                // ONE self-heal regeneration (ReportViewController::resolve);
                // this test guards the no-rebill path for CURRENT snapshots.
                'meta' => ['schema' => \App\Services\Reports\ClientReportService::PAYLOAD_SCHEMA],
                'totals' => ['backlinks' => 100, 'referring_domains' => 10, 'referring_ips' => 8, 'referring_subnets' => 6],
                'ratios' => ['dofollow_pct' => 70, 'active_pct' => 60],
                'history' => [], 'anchor_types' => [], 'gauges' => [], 'popularity' => [],
                'top_referring_domains' => [['domain' => 'ref.example', 'rank' => 10, 'backlinks' => 5, 'first_seen' => '2024-01', 'opr_score' => 3.2]],
                'anchors' => [], 'backlinks' => [],
                'competitors' => [['domain' => 'rival.example', 'shared_keywords' => 42, 'avg_position' => 8.1, 'popularity_rank' => 1000, 'opr_score' => 4.0]],
                'top_pages' => [],
            ],
        ]);
    }

    public function test_backlinks_page_with_existing_snapshot_dispatches_nothing(): void
    {
        [$user, $website] = $this->userWithSite('cached-domain.com');
        $this->seedSnapshot('cached-domain.com');

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk()
            ->assertSee('ref.example');

        Queue::assertNotPushed(GenerateWebsiteReport::class);
        Http::assertNothingSent();
    }

    public function test_competitors_page_with_existing_snapshot_dispatches_nothing(): void
    {
        [$user, $website] = $this->userWithSite('cached-domain.com');
        $this->seedSnapshot('cached-domain.com');

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('competitors.index'))
            ->assertOk()
            ->assertSee('rival.example');

        Queue::assertNotPushed(GenerateWebsiteReport::class);
        Http::assertNothingSent();
    }

    public function test_stale_but_present_snapshot_still_dispatches_nothing(): void
    {
        // Even past the freshness TTL, an existing payload is served as-is —
        // merely VIEWING a page must never trigger a re-billed regeneration.
        [$user, $website] = $this->userWithSite('stale-domain.com');
        $this->seedSnapshot('stale-domain.com');
        WebsiteReportSnapshot::query()->update(['fetched_at' => now()->subDays(400)]);

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk();

        Queue::assertNotPushed(GenerateWebsiteReport::class);
        Http::assertNothingSent();
    }

    public function test_missing_snapshot_dispatches_exactly_one_generation(): void
    {
        [$user, $website] = $this->userWithSite('never-analyzed.com');

        $this->actingAs($user)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk(); // pending state

        Queue::assertPushed(GenerateWebsiteReport::class, 1);
        Http::assertNothingSent(); // queued, not executed inline
    }
}
