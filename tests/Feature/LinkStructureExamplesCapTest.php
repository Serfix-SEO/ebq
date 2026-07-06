<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the cap-window leak (found 2026-07-06,
 * infra/crawler/known-issues.md): the Link Structure panel's example-pages
 * picker used to query WebsitePage directly with no value_rank <= cap
 * filter, so a small-cap user could be shown example URLs outside their
 * window. Fixed via CrawlReportService::topInboundPages().
 */
class LinkStructureExamplesCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_example_pages_exclude_pages_outside_the_cap_window(): void
    {
        config(['app.free' => false]);
        Plan::create(['slug' => 'trial', 'name' => 'Trial', 'max_crawl_pages' => 5, 'is_active' => true]);

        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'cap-example.test']);
        $cs = $website->crawl_site_id;

        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);

        // Five in-cap pages (value_rank 1-5, matches the plan's cap of 5).
        for ($i = 1; $i <= 5; $i++) {
            WebsitePage::create([
                'crawl_site_id' => $cs, 'url' => "https://cap-example.test/in-cap-{$i}",
                'url_hash' => WebsitePage::hashUrl("https://cap-example.test/in-cap-{$i}"),
                'http_status' => 200, 'is_indexable' => true, 'value_rank' => $i,
                'inbound_link_count' => 1, 'last_crawled_at' => now(),
            ]);
        }

        // One page well OUTSIDE the cap (value_rank 999) but with a huge
        // inbound_link_count — pre-fix, ordering by inbound_link_count alone
        // (no cap filter) put this at the very top of the examples list.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://cap-example.test/over-cap-but-popular',
            'url_hash' => WebsitePage::hashUrl('https://cap-example.test/over-cap-but-popular'),
            'http_status' => 200, 'is_indexable' => true, 'value_rank' => 999,
            'inbound_link_count' => 5000, 'last_crawled_at' => now(),
        ]);

        $this->assertSame(5, $website->crawlPageCap());

        $examples = app(CrawlReportService::class)->topInboundPages($website->id);

        $this->assertNotContains('https://cap-example.test/over-cap-but-popular', $examples);
        $this->assertContains('https://cap-example.test/in-cap-1', $examples);
        $this->assertCount(5, $examples);
    }
}
