<?php

namespace Tests\Feature\Content;

use App\Models\ContentPageAnalytics;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentPerformanceService;
use App\Support\UrlNormalizer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function site(): Website
    {
        return Website::factory()->for(User::factory()->create())->create();
    }

    public function test_keyword_summaries_aggregate_clicks_and_position(): void
    {
        $site = $this->site();
        // Two finalized days (safely before the GSC lag ceiling).
        foreach ([['d' => now()->subDays(5)->toDateString(), 'c' => 4, 'i' => 100, 'p' => 12.0],
            ['d' => now()->subDays(4)->toDateString(), 'c' => 6, 'i' => 200, 'p' => 8.0]] as $r) {
            SearchConsoleData::create([
                'website_id' => $site->id, 'date' => $r['d'], 'query' => 'seo dubai',
                'page' => 'https://x.test/a', 'clicks' => $r['c'], 'impressions' => $r['i'],
                'position' => $r['p'], 'ctr' => 0.05, 'country' => 'are', 'device' => 'desktop',
            ]);
        }

        $sum = app(ContentPerformanceService::class)->keywordSummaries($site, ['seo dubai']);

        $this->assertArrayHasKey('seo dubai', $sum);
        $this->assertTrue($sum['seo dubai']['has_data']);
        $this->assertSame(10, $sum['seo dubai']['clicks']);
        $this->assertSame(300, $sum['seo dubai']['impressions']);
        // Latest day (subDays(4)) wins for position.
        $this->assertSame(8.0, $sum['seo dubai']['position']);
    }

    public function test_page_series_merges_gsc_and_ga(): void
    {
        $site = $this->site();
        $url = 'https://x.test/blog/post';
        $page = UrlNormalizer::normalize($url);
        $day = now()->subDays(4)->toDateString();

        SearchConsoleData::create([
            'website_id' => $site->id, 'date' => $day, 'query' => 'kw', 'page' => $page,
            'clicks' => 7, 'impressions' => 90, 'position' => 5.0, 'ctr' => 0.07,
            'country' => 'are', 'device' => 'desktop',
        ]);
        ContentPageAnalytics::create([
            'website_id' => $site->id, 'date' => $day, 'page' => $page,
            'pageviews' => 20, 'sessions' => 15, 'users' => 12, 'engagement_rate' => 55.5,
        ]);

        $series = app(ContentPerformanceService::class)->pageSeries($site, $url);

        $this->assertTrue($series['has_gsc']);
        $this->assertTrue($series['has_ga']);
        $this->assertCount(1, $series['days']);
        $this->assertSame(7, $series['days'][0]['clicks']);
        $this->assertSame(20, $series['days'][0]['pageviews']);
        $this->assertSame(5.0, $series['days'][0]['position']);
    }
}
