<?php

namespace Tests\Feature\Dashboard;

use App\Jobs\WarmDashboardCaches;
use App\Livewire\Dashboard\KpiCards;
use App\Models\User;
use App\Models\Website;
use App\Services\ReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Post-sync cache warming (added 2026-07-06): WarmDashboardCaches recomputes
 * every dashboard/statistics card payload through the SAME `payload()`
 * statics the components render from, so the first visitor after a sync
 * version-bump gets warm caches instead of ~2min of cold aggregates.
 */
class WarmDashboardCachesTest extends TestCase
{
    use RefreshDatabase;

    public function test_warm_job_populates_the_component_cache_keys(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'warm-test.com']);
        DB::table('search_console_data')->insert(ulid_rows([[
            'website_id' => $website->id,
            'date' => now()->subDays(2)->toDateString(),
            'query' => 'warm query', 'page' => 'https://warm-test.com/',
            'country' => 'USA', 'device' => 'DESKTOP',
            'clicks' => 42, 'impressions' => 420, 'ctr' => 0.1, 'position' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]]));

        Bus::dispatchSync(new WarmDashboardCaches((string) $website->id));

        // KPI key must now be hot — and hold the seeded clicks.
        $version = ReportCache::version($website->id);
        $end = now()->subDay();
        $key = sprintf('kpis:%s:%s:%s:%d', $website->id, $end->copy()->subDays(29)->toDateString(), $end->toDateString(), $version);
        $this->assertNotNull(Cache::get($key), 'KPI cache must be warmed');
        $this->assertSame(42, Cache::get($key)['clicks']['current']);

        // Country filter + top-countries + action-queue keys hot too. This
        // brand-new website has no crawl history, so isInitialCrawl() is
        // true and the warmed action-queue payload excludes crawl issues
        // (includeCrawlIssues=0) — see WarmDashboardCaches::handle().
        $this->assertNotNull(Cache::get("country_filter:{$website->id}:v{$version}"));
        $this->assertNotNull(Cache::get("top_countries:{$website->id}:v{$version}"));
        $this->assertNotNull(Cache::get(sprintf(
            'action-queue:v4:%s:%d:%d:%s:%s:%d',
            $website->id, $version, \App\Services\RankCache::version($website->id), 'all', app()->getLocale(), 0
        )));
    }

    public function test_component_render_uses_the_warmed_payload(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'warm-render.com']);

        Bus::dispatchSync(new WarmDashboardCaches((string) $website->id));

        // Same static, same key: render()'s payload() call hits the warm entry.
        $first = KpiCards::payload((string) $website->id);
        $this->assertIsArray($first);
        $this->assertArrayHasKey('clicks', $first);
    }

    public function test_warm_is_skipped_while_gsc_sync_is_in_flight(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'mid-sync.com']);

        cache()->put('gsc-sync-inflight:'.$website->id, true, 7200);
        Bus::dispatchSync(new WarmDashboardCaches((string) $website->id));

        $version = ReportCache::version($website->id);
        $this->assertNull(
            Cache::get("country_filter:{$website->id}:v{$version}"),
            'mid-sync warm must skip — the end-of-sync warm covers it'
        );

        // Flag cleared (what the sync does on completion) → warm proceeds.
        cache()->forget('gsc-sync-inflight:'.$website->id);
        Bus::dispatchSync(new WarmDashboardCaches((string) $website->id));
        $this->assertNotNull(Cache::get("country_filter:{$website->id}:v{$version}"));
    }

    public function test_frozen_website_is_skipped(): void
    {
        config(['app.free' => false]);
        $owner = User::factory()->create();
        // Freeze by plan limit: plan allows 1 website, freeze applies to the 2nd+
        // (oldest-first keeps active — backdate the kept site so order is deterministic).
        \App\Models\Plan::create(['slug' => 'trial', 'name' => 'Trial', 'max_websites' => 1, 'is_active' => true]);
        $first = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'kept.com']);
        $first->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $frozen = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'frozen.com']);
        $this->assertTrue($frozen->fresh()->isFrozen());

        Bus::dispatchSync(new WarmDashboardCaches((string) $frozen->fresh()->id));

        $version = ReportCache::version($frozen->id);
        $this->assertNull(Cache::get("country_filter:{$frozen->id}:v{$version}"), 'frozen site must not be warmed');
    }
}
