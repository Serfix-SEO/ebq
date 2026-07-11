<?php

namespace Tests\Feature\Api\V1;

use App\Models\KeywordMetric;
use App\Models\RankTrackingKeyword;
use App\Models\RankTrackingSnapshot;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginHqKeywordDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Factory users have no subscription → they resolve to the trial
        // plan row, which must exist for the hq feature flag to be true.
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function website(): Website
    {
        $user = User::factory()->create();

        return Website::factory()->create(['user_id' => $user->id]);
    }

    private function token(Website $website): string
    {
        return $website->createToken('test', ['read:insights'])->plainTextToken;
    }

    public function test_requires_token_and_ability(): void
    {
        $this->getJson('/api/v1/hq/keyword-detail?query=demo')->assertStatus(401);

        $website = $this->website();
        $wrong = $website->createToken('test', ['unrelated:ability'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$wrong)
            ->getJson('/api/v1/hq/keyword-detail?query=demo')
            ->assertStatus(403);
    }

    public function test_missing_query_is_invalid(): void
    {
        $website = $this->website();
        $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->getJson('/api/v1/hq/keyword-detail')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_query');
    }

    public function test_returns_all_signals_for_a_fully_populated_keyword(): void
    {
        $website = $this->website();
        $query = 'best coffee grinder';

        KeywordMetric::create([
            'keyword' => $query,
            'keyword_hash' => KeywordMetric::hashKeyword($query),
            'country' => 'global',
            'data_source' => 'gkp',
            'search_volume' => 12000,
            'cpc' => 1.25,
            'currency' => 'USD',
            'competition' => 0.4,
            'trend_12m' => [['month' => 'January', 'year' => 2026, 'value' => 9000]],
            'fetched_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        // Two pages ranking for the query → cannibalized flag; position 12 +
        // 400 impressions → striking-distance AND quick-win.
        foreach ([['/a', 12.0, 300], ['/b', 14.0, 100]] as [$path, $pos, $impr]) {
            SearchConsoleData::create([
                'website_id' => $website->id,
                'date' => now()->subDays(3)->toDateString(),
                'query' => $query,
                'page' => 'https://example.com'.$path,
                'country' => 'usa',
                'device' => 'DESKTOP',
                'clicks' => 10,
                'impressions' => $impr,
                'ctr' => 0.05,
                'position' => $pos,
            ]);
        }

        $tracker = RankTrackingKeyword::create([
            'website_id' => $website->id,
            'user_id' => $website->user_id,
            'keyword' => $query,
            'keyword_hash' => RankTrackingKeyword::hashKeyword($query),
            'target_domain' => 'example.com',
            'search_engine' => 'google',
            'search_type' => 'organic',
            'country' => 'us',
            'language' => 'en',
            'device' => 'desktop',
            'depth' => 100,
            'is_active' => true,
            'current_position' => 12.0,
            'best_position' => 9.0,
            'last_checked_at' => now()->subHour(),
            'last_status' => 'success',
        ]);
        RankTrackingSnapshot::create([
            'rank_tracking_keyword_id' => $tracker->id,
            'checked_at' => now()->subHour(),
            'position' => 12,
            'url' => 'https://example.com/a',
            'status' => 'success',
            'related_searches' => [['query' => 'manual coffee grinder']],
            'people_also_ask' => [['question' => 'Is a burr grinder better?']],
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->getJson('/api/v1/hq/keyword-detail?query='.rawurlencode($query))
            ->assertOk();

        $res->assertJsonPath('query', $query)
            ->assertJsonPath('metric.search_volume', 12000)
            ->assertJsonPath('metric.competition', 0.4)
            ->assertJsonPath('gsc_totals.impressions', 400)
            ->assertJsonPath('gsc_totals.clicks', 20)
            ->assertJsonPath('tracker.id', $tracker->id)
            ->assertJsonPath('tracker.current_position', 12)
            ->assertJsonPath('latest_snapshot.url', 'https://example.com/a')
            ->assertJsonPath('flags.striking_distance', true)
            ->assertJsonPath('flags.cannibalized', true)
            ->assertJsonPath('flags.quick_win', true)
            ->assertJsonPath('related_searches.0.query', 'manual coffee grinder')
            ->assertJsonPath('paa.0.question', 'Is a burr grinder better?');

        $this->assertCount(2, $res->json('top_pages'));
        $this->assertCount(1, $res->json('gsc_daily'));
        $this->assertNotNull($res->json('projected_clicks'));
        // Raw-bid/CPC values are allowed; computed $ projections are not.
        $this->assertArrayNotHasKey('projected_value', $res->json());
    }

    public function test_degrades_to_nulls_with_no_data_and_no_gsc(): void
    {
        $website = $this->website();

        $res = $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->getJson('/api/v1/hq/keyword-detail?query='.rawurlencode('never seen keyword'))
            ->assertOk();

        $res->assertJsonPath('metric', null)
            ->assertJsonPath('gsc_totals', null)
            ->assertJsonPath('tracker', null)
            ->assertJsonPath('latest_snapshot', null)
            ->assertJsonPath('flags.striking_distance', false)
            ->assertJsonPath('projected_clicks', null);
        $this->assertSame([], $res->json('gsc_daily'));
        $this->assertSame([], $res->json('top_pages'));
        $this->assertSame([], $res->json('related_searches'));
    }

    public function test_tenancy_is_token_scoped_not_query_scoped(): void
    {
        $mine = $this->website();
        $other = $this->website();
        $query = 'shared keyword';

        SearchConsoleData::create([
            'website_id' => $other->id,
            'date' => now()->subDays(3)->toDateString(),
            'query' => $query,
            'page' => 'https://other.test/page',
            'country' => 'usa',
            'device' => 'DESKTOP',
            'clicks' => 99,
            'impressions' => 999,
            'ctr' => 0.1,
            'position' => 2.0,
        ]);
        RankTrackingKeyword::create([
            'website_id' => $other->id,
            'user_id' => $other->user_id,
            'keyword' => $query,
            'keyword_hash' => RankTrackingKeyword::hashKeyword($query),
            'target_domain' => 'other.test',
            'search_engine' => 'google',
            'search_type' => 'organic',
            'country' => 'us',
            'language' => 'en',
            'device' => 'desktop',
            'depth' => 100,
            'is_active' => true,
        ]);

        // My token must never see the other tenant's GSC rows or tracker.
        $this->withHeader('Authorization', 'Bearer '.$this->token($mine))
            ->getJson('/api/v1/hq/keyword-detail?query='.rawurlencode($query))
            ->assertOk()
            ->assertJsonPath('gsc_totals', null)
            ->assertJsonPath('tracker', null);
    }
}
