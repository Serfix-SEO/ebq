<?php

namespace Tests\Feature\Competitive;

use App\Models\KeywordApiRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Competitive\KeywordGapService;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordFinder\KeywordIdeasMonthlyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gap runs share the cross-user monthly discovery cache (2026-07-14): a
 * domain ANY user already discovered this month is served from cache instead
 * of re-querying a keyword server, and every completed gap-run discovery
 * WARMS that cache for the next user. Before this, every gap run re-billed
 * one keyword-server query per domain even seconds after an identical run.
 */
class KeywordGapSharedCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // Gap discovery requires the self-hosted provider to be the active one.
        \App\Models\Setting::set(
            \App\Support\KeywordProviderConfig::SETTING_KEY,
            \App\Support\KeywordProviderConfig::PROVIDER_KEYWORD_FINDER,
        );
    }

    private function cacheKeyFor(string $url): string
    {
        [$mode, $payload] = app(KeywordFinderPool::class)
            ->buildIdeasPayload(['url' => $url, 'scope' => 'site'], 'us');

        return KeywordIdeasMonthlyCache::key($mode, $payload);
    }

    /** @param list<string> $keywords */
    private function warmCache(string $url, array $keywords): void
    {
        KeywordIdeasMonthlyCache::put(
            $this->cacheKeyFor($url),
            array_map(fn ($k) => ['keyword' => $k, 'avgMonthlySearches' => 500], $keywords),
        );
    }

    public function test_cached_competitor_is_not_redispatched_and_its_keywords_still_aggregate(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);

        // Another user's earlier run already discovered rival.com this month.
        $this->warmCache('rival.com', ['cached rival keyword']);
        // Our own site is cached too → the whole run is dispatch-free.
        $this->warmCache('mysite.com', ['our keyword']);

        $service = app(KeywordGapService::class);
        $analysis = $service->start($website, ['rival.com'], 'us', $user->id);

        // ZERO website-mode DISCOVERY requests were created — both sources
        // cache-served. (A keywords-mode volume-enrichment backfill may still
        // dispatch; that's the metrics cache, a different, cheaper concern.)
        $this->assertSame(0, KeywordApiRequest::where('mode', 'website')->count());

        // And the run completed immediately with the cached keywords diffed.
        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertDatabaseHas('keyword_gap_rows', [
            'keyword_gap_analysis_id' => $analysis->id,
            'keyword' => 'cached rival keyword',
            'bucket' => 'missing',
        ]);
    }

    public function test_completed_dispatched_discovery_warms_the_shared_cache(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mysite.com']);

        // Hand-craft the collecting analysis + the completed requests the
        // webhook would have produced (same approach as
        // KeywordGapAggregationTest — no keyword servers exist in tests, so a
        // real start() would instant-fail and aggregate before we could flip
        // request statuses).
        $pool = app(KeywordFinderPool::class);
        $entries = [];
        foreach ([['mysite.com', 'ours'], ['rival.com', 'competitor']] as [$url, $role]) {
            [$mode, $payload] = $pool->buildIdeasPayload(['url' => $url, 'scope' => 'site'], 'us');
            $req = KeywordApiRequest::create([
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'ideas',
                'mode' => $mode,
                'payload' => $payload,
                'status' => 'completed',
                'result' => ['results' => [['keyword' => 'fresh keyword from '.$url, 'avgMonthlySearches' => 100]]],
            ]);
            $entries[] = ['id' => $req->request_id, 'role' => $role, 'url' => $url, 'domain' => $url];
        }
        $analysis = \App\Models\KeywordGapAnalysis::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'our_url' => 'mysite.com',
            'competitor_urls' => ['rival.com'],
            'country' => 'us',
            'status' => 'collecting',
            'request_ids' => $entries,
            'total_requests' => 2,
            'completed_requests' => 0,
        ]);

        app(KeywordGapService::class)->maybeAggregate($analysis);

        // Both domains' discovery results are now in the shared cache — the
        // NEXT run (any user) for either domain is a free cache hit.
        $this->assertSame('completed', $analysis->fresh()->status);
        $this->assertNotNull(KeywordIdeasMonthlyCache::get($this->cacheKeyFor('mysite.com')));
        $this->assertNotNull(KeywordIdeasMonthlyCache::get($this->cacheKeyFor('rival.com')));
    }
}
