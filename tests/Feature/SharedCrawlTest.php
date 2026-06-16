<?php

namespace Tests\Feature;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\Plan;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteFindingState;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedCrawlTest extends TestCase
{
    use RefreshDatabase;

    private function page(int $cs, string $url, int $rank, array $extra = []): WebsitePage
    {
        return WebsitePage::create(array_merge([
            'crawl_site_id' => $cs, 'url' => $url, 'url_hash' => WebsitePage::hashUrl($url),
            'http_status' => 200, 'is_indexable' => true, 'value_rank' => $rank,
            'last_crawled_at' => now(),
        ], $extra));
    }

    public function test_two_users_same_domain_share_one_crawl_site(): void
    {
        $a = Website::factory()->create(['user_id' => User::factory()->create()->id, 'domain' => 'basepaws.com']);
        $b = Website::factory()->create(['user_id' => User::factory()->create()->id, 'domain' => 'https://www.basepaws.com/']);

        $this->assertNotNull($a->crawl_site_id);
        $this->assertSame($a->crawl_site_id, $b->crawl_site_id, 'www + apex collapse to one shared crawl_site');
        $this->assertSame(1, \App\Models\CrawlSite::count());
    }

    public function test_each_user_sees_only_their_cap_window(): void
    {
        config(['app.free' => false]);
        $bigPlan = Plan::create(['slug' => 'big', 'name' => 'Big', 'max_crawl_pages' => 1000, 'is_active' => true]);
        $smallPlan = Plan::create(['slug' => 'small', 'name' => 'Small', 'max_crawl_pages' => 2, 'is_active' => true]);

        $big = Website::factory()->create(['user_id' => User::factory()->create(['current_plan_slug' => 'big'])->id, 'domain' => 'shared.com']);
        $small = Website::factory()->create(['user_id' => User::factory()->create(['current_plan_slug' => 'small'])->id, 'domain' => 'shared.com']);
        $cs = $big->crawl_site_id;

        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'health_score' => 70]);
        $this->page($cs, 'https://shared.com/1', 1);
        $this->page($cs, 'https://shared.com/2', 2);
        $this->page($cs, 'https://shared.com/3', 3);

        $report = app(CrawlReportService::class);
        // Big cap (1000) sees all 3 pages; small cap (2) sees only the top 2.
        $this->assertSame(3, $report->summary($big->id)['pages_total']);
        $this->assertSame(2, $report->summary($small->id)['pages_total']);
    }

    public function test_finding_ignore_and_impact_are_per_user(): void
    {
        $owner1 = User::factory()->create();
        $owner2 = User::factory()->create();
        $w1 = Website::factory()->create(['user_id' => $owner1->id, 'domain' => 'shared.com']);
        $w2 = Website::factory()->create(['user_id' => $owner2->id, 'domain' => 'shared.com']);
        $cs = $w1->crawl_site_id;

        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'health_score' => 60]);
        $dead = $this->page($cs, 'https://shared.com/dead', 1, ['http_status' => 404, 'is_indexable' => false]);
        $finding = CrawlFinding::create([
            'crawl_site_id' => $cs, 'page_id' => $dead->id, 'category' => 'broken_link', 'type' => 'broken_page',
            'severity' => 'high', 'impact' => 0, 'affected_url' => 'https://shared.com/dead',
            'affected_url_hash' => CrawlFinding::hashUrl('https://shared.com/dead'), 'status' => 'open',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        // Only user 1 has Search Console traffic for the dead URL.
        SearchConsoleData::create([
            'website_id' => $w1->id, 'date' => now()->subDays(2)->toDateString(),
            'query' => 'x', 'page' => 'https://shared.com/dead', 'clicks' => 25, 'impressions' => 100, 'position' => 3, 'country' => 'usa',
        ]);

        $report = app(CrawlReportService::class);

        // Per-user impact: user 1 sees 25 clicks-at-risk, user 2 sees 0.
        $r1 = $report->categoryFindings('broken_link', $w1->id)[0];
        $r2 = $report->categoryFindings('broken_link', $w2->id)[0];
        $this->assertSame(25, $r1['impact']);
        $this->assertSame(0, $r2['impact']);

        // Per-user ignore: user 1 ignores the shared finding; user 2 still sees it.
        WebsiteFindingState::create(['website_id' => $w1->id, 'finding_id' => $finding->id, 'status' => 'ignored']);
        $this->assertSame(0, $report->summary($w1->id)['findings']['total']);
        $this->assertSame(1, $report->summary($w2->id)['findings']['total']);
    }
}
