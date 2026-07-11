<?php

namespace Tests\Feature\Api\V1;

use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InsightsPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_show_post_returns_gsc_totals_and_flags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $url = 'https://example.com/post-42';

        for ($i = 1; $i <= 10; $i++) {
            SearchConsoleData::create([
                'website_id' => $website->id,
                'date' => Carbon::parse('2026-04-20')->subDays($i)->toDateString(),
                'query' => 'sample query',
                'page' => $url,
                'clicks' => 5, 'impressions' => 200,
                'position' => 6.0, 'ctr' => 0.025,
                'country' => '', 'device' => '',
            ]);
        }

        $plain = $website->createToken('test', ['read:insights'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/posts/42/insights?url='.urlencode($url));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'url' => $url,
                'external_post_id' => '42',
                'flags' => [
                    'cannibalized' => false,
                    'tracked' => false,
                ],
            ]);
        $this->assertSame(50, $response->json('gsc.totals_30d.clicks'));
        $this->assertSame(2000, $response->json('gsc.totals_30d.impressions'));
        $this->assertNotEmpty($response->json('gsc.top_queries_30d'));
        $this->assertSame('sample query', $response->json('gsc.primary_query'));
        // No inspection row exists, but the page has recent impressions, so
        // the resolver now derives a PASS verdict from impressions instead
        // of returning null (Google serving the page implies indexed).
        $this->assertSame('PASS', $response->json('indexing.verdict'));
        $this->assertSame('impressions', $response->json('indexing.verdict_source'));
        $this->assertTrue((bool) $response->json('indexing.indexed'));
        $this->assertNull($response->json('indexing.coverage_state'));
    }

    public function test_show_post_rejects_url_not_belonging_to_website(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $plain = $website->createToken('test', ['read:insights'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/posts/42/insights?url=https://evil.com/other')
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame('url_not_for_website', $response->json('error'));
    }

    public function test_bulk_posts_returns_per_url_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $urlA = 'https://example.com/a';
        $urlB = 'https://example.com/b';

        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => '2026-04-15', 'query' => 'q',
            'page' => $urlA, 'clicks' => 12, 'impressions' => 400, 'position' => 4.0,
            'ctr' => 0.03, 'country' => '', 'device' => '',
        ]);
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => '2026-04-15', 'query' => 'q',
            'page' => $urlB, 'clicks' => 3, 'impressions' => 100, 'position' => 14.0,
            'ctr' => 0.03, 'country' => '', 'device' => '',
        ]);

        $plain = $website->createToken('test', ['read:insights'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/posts?urls[]='.urlencode($urlA).'&urls[]='.urlencode($urlB))
            ->assertOk();

        $results = $response->json('results');
        $this->assertSame(12, $results[$urlA]['clicks_30d']);
        $this->assertSame(3, $results[$urlB]['clicks_30d']);
    }

    public function test_dashboard_returns_insight_counts(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);
        $plain = $website->createToken('test', ['read:insights'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'website_id',
                'domain',
                'counts' => ['cannibalizations', 'striking_distance', 'indexing_fails_with_traffic', 'content_decay'],
                'alert' => ['last_traffic_drop_alert_at'],
            ]);
    }
}
