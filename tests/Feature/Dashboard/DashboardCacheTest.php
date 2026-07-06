<?php

namespace Tests\Feature\Dashboard;

use App\Jobs\SyncAnalyticsData;
use App\Livewire\Dashboard\KpiCards;
use App\Livewire\Dashboard\TopCountriesCard;
use App\Models\User;
use App\Models\Website;
use App\Services\ReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for dashboard/statistics caching (fixed 2026-07-06):
 * every component hardcoded a 600s TTL, so the heavy GSC/GA aggregates
 * re-ran every 10 minutes even though fresh data only lands on sync. Five
 * of eight components also omitted ReportCache::version from their keys,
 * so a completed sync did NOT refresh them until TTL expiry. Now: 24h
 * sanity TTL + version-keyed keys ("cached until fresh data is fetched").
 */
class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The dashboard cards are #[Lazy]; in tests that renders only the
        // placeholder, so render() (where the caching lives) never runs.
        Livewire::withoutLazyLoading();
    }

    private function seedGscRow(Website $website, string $date, int $clicks): void
    {
        DB::table('search_console_data')->insert(ulid_rows([[
            'website_id' => $website->id,
            'date' => $date,
            'query' => 'test query',
            'page' => 'https://example.com/',
            'country' => 'USA',
            'device' => 'DESKTOP',
            'clicks' => $clicks,
            'impressions' => $clicks * 10,
            'ctr' => 0.1,
            'position' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]]));
    }

    public function test_component_payload_is_served_from_cache_until_version_bump(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'cache-test.com']);
        $this->seedGscRow($website, now()->subDays(2)->toDateString(), 100);

        // First render caches under the current version.
        session(['current_website_id' => $website->id]);
        Livewire::actingAs($user)
            ->test(TopCountriesCard::class, ['websiteId' => $website->id])
            ->assertSee('United States');

        // New GSC row lands but no version bump — cached payload still served
        // (the whole point: no re-aggregation until a sync says data changed).
        $this->seedGscRow($website, now()->subDay()->toDateString(), 999);
        $key = 'top_countries:'.$website->id.':v'.ReportCache::version($website->id);
        $this->assertNotNull(cache()->get($key), 'payload should be cached under the version key');
        $cached = cache()->get($key);

        // Version bump (what SyncSearchConsoleData does) → next render recomputes.
        ReportCache::flushWebsite($website->id);
        $newKey = 'top_countries:'.$website->id.':v'.ReportCache::version($website->id);
        $this->assertNotSame($key, $newKey, 'version bump must change the cache key');
        $this->assertNull(cache()->get($newKey), 'new version key must start cold');
    }

    public function test_kpi_cache_ttl_is_a_day_not_ten_minutes(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'ttl-test.com']);
        $this->seedGscRow($website, now()->subDays(2)->toDateString(), 50);

        session(['current_website_id' => $website->id]);
        Livewire::actingAs($user)
            ->test(KpiCards::class, ['websiteId' => $website->id]);

        // Advance past the old 600s TTL; the cached payload must still be there.
        $this->travel(30)->minutes();
        $version = ReportCache::version($website->id);
        $keys = collect(range(0, 0))->map(fn () => sprintf(
            'kpis:%s:%s:%s:%d',
            $website->id,
            now()->subDay()->subDays(29)->toDateString(),
            now()->subDay()->toDateString(),
            $version,
        ));
        $this->assertTrue(
            $keys->contains(fn (string $k) => cache()->get($k) !== null),
            'KPI payload must survive past the old 600s TTL'
        );
    }

    public function test_analytics_sync_bumps_the_report_cache_version(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create([
            'user_id' => $user->id,
            'domain' => 'ga-bump.com',
            'ga_property_id' => 'properties/123',
        ]);
        \App\Models\GoogleAccount::create([
            'user_id' => $user->id,
            'access_token' => 'test-token',
            'refresh_token' => null,
            'expires_at' => now()->addHour(),
            'google_id' => 'gid-cache-test',
        ]);

        $mock = \Mockery::mock(\App\Services\Google\GoogleAnalyticsService::class);
        $mock->shouldReceive('fetchDailyTraffic')->once()->andReturn([[
            'date' => now()->subDay()->toDateString(),
            'source' => 'google',
            'users' => 10,
            'sessions' => 12,
            'bounce_rate' => 0.4,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]]);
        $this->app->instance(\App\Services\Google\GoogleAnalyticsService::class, $mock);

        $before = ReportCache::version($website->id);

        \Illuminate\Support\Facades\Bus::dispatchSync(new SyncAnalyticsData($website->id));

        // A GA sync that landed rows must orphan the dashboard caches, exactly
        // like the GSC sync does — pre-fix, SyncAnalyticsData never bumped the
        // version, so KPI/traffic payloads served stale GA numbers all TTL.
        $this->assertGreaterThan($before, ReportCache::version($website->id));
    }
}
