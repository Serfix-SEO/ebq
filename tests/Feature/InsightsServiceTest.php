<?php

namespace Tests\Feature;

use App\Models\PageIndexingStatus;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cannibalization_flags_query_split_across_pages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $date = '2026-04-10';
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'best seo tools',
            'page' => 'https://'.$website->domain.'/a', 'clicks' => 40, 'impressions' => 800,
            'position' => 6.0, 'ctr' => 0.05, 'country' => '', 'device' => '',
        ]);
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'best seo tools',
            'page' => 'https://'.$website->domain.'/b', 'clicks' => 20, 'impressions' => 400,
            'position' => 11.0, 'ctr' => 0.05, 'country' => '', 'device' => '',
        ]);
        // Control query — single page, should NOT appear
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'uncontested',
            'page' => 'https://'.$website->domain.'/c', 'clicks' => 200, 'impressions' => 2000,
            'position' => 3.0, 'ctr' => 0.1, 'country' => '', 'device' => '',
        ]);

        $out = app(ReportDataService::class)->cannibalizationReport($website->id);

        $this->assertCount(1, $out);
        $this->assertSame('best seo tools', $out[0]['query']);
        $this->assertSame('https://'.$website->domain.'/a', $out[0]['primary_page']);
        $this->assertSame(2, $out[0]['page_count']);
        $this->assertCount(1, $out[0]['competing_pages']);
    }

    /**
     * GSC reports host/protocol variants of one page as distinct rows —
     * the report must merge them (bug report 2026-07-11: "/symbols" showed
     * twice in every competing list, www + non-www).
     */
    public function test_cannibalization_merges_host_variants_of_the_same_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $date = '2026-04-10';
        // Genuine split: homepage vs /symbols…
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'symbols for pubg',
            'page' => 'https://example.com', 'clicks' => 37, 'impressions' => 600,
            'position' => 5.0, 'ctr' => 0.06, 'country' => '', 'device' => '',
        ]);
        // …but /symbols arrives as TWO host variants of one page.
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'symbols for pubg',
            'page' => 'https://www.example.com/symbols', 'clicks' => 5, 'impressions' => 100,
            'position' => 8.0, 'ctr' => 0.05, 'country' => '', 'device' => '',
        ]);
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'symbols for pubg',
            'page' => 'https://example.com/symbols/', 'clicks' => 0, 'impressions' => 100,
            'position' => 40.0, 'ctr' => 0.0, 'country' => '', 'device' => '',
        ]);
        // Control: a query whose "pages" are ONLY variants of one page —
        // canonicalization noise, not cannibalization; must not appear.
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'variant only',
            'page' => 'https://example.com/one', 'clicks' => 30, 'impressions' => 300,
            'position' => 4.0, 'ctr' => 0.1, 'country' => '', 'device' => '',
        ]);
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => $date, 'query' => 'variant only',
            'page' => 'http://www.example.com/one/', 'clicks' => 25, 'impressions' => 250,
            'position' => 4.5, 'ctr' => 0.1, 'country' => '', 'device' => '',
        ]);

        $out = app(ReportDataService::class)->cannibalizationReport($website->id);

        $this->assertCount(1, $out);
        $row = $out[0];
        $this->assertSame('symbols for pubg', $row['query']);
        // The two /symbols variants collapsed into one competing entry…
        $this->assertSame(2, $row['page_count']);
        $this->assertCount(1, $row['competing_pages']);
        $competing = $row['competing_pages'][0];
        // …with summed stats (5+0 clicks, 100+100 impressions) and an
        // impressions-weighted position ((8*100 + 40*100) / 200 = 24).
        $this->assertSame(5, $competing['clicks']);
        $this->assertSame(200, $competing['impressions']);
        $this->assertSame(24.0, $competing['position']);
        // Display URL = the variant with the most clicks.
        $this->assertSame('https://www.example.com/symbols', $competing['page']);
        $this->assertSame(42, $row['total_clicks']);
    }

    /**
     * Sitelinks under the primary result log impressions at the parent's
     * position with 0 clicks — GSC doesn't label them, so they showed as
     * 0%-share "competitors". A competing page must take clicks or hold
     * ≥10% of the query's impressions on its own.
     */
    public function test_cannibalization_filters_sitelinks_noise_competitors(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $date = '2026-04-10';
        $mk = function (string $query, string $page, int $clicks, int $impressions, float $position) use ($website, $date) {
            SearchConsoleData::create([
                'website_id' => $website->id, 'date' => $date, 'query' => $query,
                'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions,
                'position' => $position, 'ctr' => 0.05, 'country' => '', 'device' => '',
            ]);
        };

        // Primary + one REAL competitor (has clicks) + sitelinks noise
        // (0 clicks, 13/198 ≈ 6.6% impression share each — below 10%).
        $mk('pubg symbols copy paste', 'https://example.com', 11, 113, 4.3);
        $mk('pubg symbols copy paste', 'https://example.com/symbols', 3, 33, 4.6);
        $mk('pubg symbols copy paste', 'https://example.com/fonts', 0, 13, 3.9);
        $mk('pubg symbols copy paste', 'https://example.com/blogs', 0, 13, 3.9);
        $mk('pubg symbols copy paste', 'https://example.com/name-ideas', 0, 13, 3.9);

        // Control: 0-click page that holds a big impression slice (real
        // competitor Google is testing) — must survive the filter. A second
        // clicked page keeps the primary under the 90%-dominance gate.
        $mk('big impression rival', 'https://example.com/main', 30, 300, 3.0);
        $mk('big impression rival', 'https://example.com/second', 10, 100, 6.0);
        $mk('big impression rival', 'https://example.com/rival', 0, 200, 9.0);

        // Control: "split" that is ONLY sitelinks noise — row must vanish.
        $mk('noise only', 'https://example.com/solo', 50, 500, 2.0);
        $mk('noise only', 'https://example.com/a', 0, 20, 2.0);
        $mk('noise only', 'https://example.com/b', 0, 20, 2.0);

        $out = app(ReportDataService::class)->cannibalizationReport($website->id);
        $byQuery = collect($out)->keyBy('query');

        $this->assertCount(2, $out);

        $row = $byQuery['pubg symbols copy paste'];
        $this->assertSame(2, $row['page_count']);
        $this->assertCount(1, $row['competing_pages']);
        $this->assertSame('https://example.com/symbols', $row['competing_pages'][0]['page']);
        // Query totals still reflect ALL pages (the noise earned impressions).
        $this->assertSame(185, $row['total_impressions']);

        $rival = $byQuery['big impression rival'];
        $this->assertCount(2, $rival['competing_pages']);
        $competingPages = array_column($rival['competing_pages'], 'page');
        $this->assertContains('https://example.com/second', $competingPages);
        // 0 clicks but 200/600 impressions — a real rival, kept.
        $this->assertContains('https://example.com/rival', $competingPages);

        $this->assertFalse($byQuery->has('noise only'));
    }

    public function test_striking_distance_returns_position_5_to_20_high_impression_queries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        // Qualifies: position 11, 2000 impressions, low CTR
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => '2026-04-10', 'query' => 'almost page 1',
            'page' => 'https://x/y', 'clicks' => 20, 'impressions' => 2000,
            'position' => 11.0, 'ctr' => 0.01, 'country' => '', 'device' => '',
        ]);
        // Does not qualify: too high a position
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => '2026-04-10', 'query' => 'already ranking',
            'page' => 'https://x/z', 'clicks' => 200, 'impressions' => 2000,
            'position' => 2.0, 'ctr' => 0.1, 'country' => '', 'device' => '',
        ]);
        // Does not qualify: not enough impressions
        SearchConsoleData::create([
            'website_id' => $website->id, 'date' => '2026-04-10', 'query' => 'too small',
            'page' => 'https://x/w', 'clicks' => 1, 'impressions' => 50,
            'position' => 12.0, 'ctr' => 0.02, 'country' => '', 'device' => '',
        ]);

        $out = app(ReportDataService::class)->strikingDistance($website->id);

        $this->assertCount(1, $out);
        $this->assertSame('almost page 1', $out[0]['query']);
    }

    public function test_indexing_fails_with_traffic_surfaces_only_failing_pages_with_recent_impressions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20', 'UTC'));
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);

        $failingPage = 'https://'.$website->domain.'/broken';
        $passingPage = 'https://'.$website->domain.'/ok';
        $quietFailingPage = 'https://'.$website->domain.'/nobody-visits';

        PageIndexingStatus::create([
            'website_id' => $website->id, 'page' => $failingPage, 'google_verdict' => 'FAIL',
            'google_coverage_state' => 'Crawled — currently not indexed',
        ]);
        PageIndexingStatus::create([
            'website_id' => $website->id, 'page' => $passingPage, 'google_verdict' => 'PASS',
            'google_coverage_state' => 'Submitted and indexed',
        ]);
        PageIndexingStatus::create([
            'website_id' => $website->id, 'page' => $quietFailingPage, 'google_verdict' => 'FAIL',
            'google_coverage_state' => 'Discovered — currently not indexed',
        ]);

        // Traffic within the 14d window for failing + passing, none for quiet-failing
        foreach (['2026-04-15', '2026-04-16'] as $date) {
            SearchConsoleData::create([
                'website_id' => $website->id, 'date' => $date, 'query' => 'x',
                'page' => $failingPage, 'clicks' => 3, 'impressions' => 50, 'position' => 15.0,
                'ctr' => 0.06, 'country' => '', 'device' => '',
            ]);
            SearchConsoleData::create([
                'website_id' => $website->id, 'date' => $date, 'query' => 'x',
                'page' => $passingPage, 'clicks' => 10, 'impressions' => 120, 'position' => 4.0,
                'ctr' => 0.08, 'country' => '', 'device' => '',
            ]);
        }

        $out = app(ReportDataService::class)->indexingFailsWithTraffic($website->id);

        $this->assertCount(1, $out);
        $this->assertSame($failingPage, $out[0]['page']);
        $this->assertSame('FAIL', $out[0]['verdict']);
        $this->assertSame(100, $out[0]['recent_impressions']);
    }
}
