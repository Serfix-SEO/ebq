<?php

namespace Tests\Feature;

use App\Jobs\FetchCompetitorBacklinks;
use App\Models\User;
use App\Models\Website;
use App\Services\CompetitorBacklinkService;
use App\Services\OwnBacklinkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The paid Keywords Everywhere BACKLINK endpoints (50 credits/domain) are
 * suppressed by default (services.keywords_everywhere.backlinks_enabled,
 * 2026-07-14) — DA needs are served by Open PageRank instead. These guards
 * make sure no code path can silently re-bill KE while the switch is off.
 */
class KeBacklinkSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // Real-looking key so a slip would be a REAL billed call shape,
        // not masked by an unconfigured-client short-circuit.
        config(['services.keywords_everywhere.key' => 'test-key']);
    }

    public function test_competitor_queue_refresh_is_inert_while_suppressed(): void
    {
        Queue::fake();

        app(CompetitorBacklinkService::class)->queueRefresh(['rival.com', 'other.com']);

        Queue::assertNotPushed(FetchCompetitorBacklinks::class);
    }

    public function test_competitor_refresh_makes_no_http_call_while_suppressed(): void
    {
        $written = app(CompetitorBacklinkService::class)->refresh('rival.com');

        $this->assertSame(0, $written);
        Http::assertNothingSent();
    }

    public function test_own_backlink_sync_makes_no_http_call_while_suppressed(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);

        $written = app(OwnBacklinkSyncService::class)->syncForWebsite($website);

        $this->assertSame(0, $written);
        Http::assertNothingSent();
    }

    public function test_flag_on_restores_the_queue_path(): void
    {
        Queue::fake();
        config(['services.keywords_everywhere.backlinks_enabled' => true]);

        app(CompetitorBacklinkService::class)->queueRefresh(['rival.com']);

        Queue::assertPushed(FetchCompetitorBacklinks::class);
    }
}
