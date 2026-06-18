<?php

namespace Tests\Feature;

use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use App\Services\Crawler\SiteGraphAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the chunked recompute of inbound_link_count + click_depth produces the
 * same result the old whole-site UPDATE/JOIN did. The chunking exists to avoid the
 * InnoDB 1205 lock-wait timeouts that failed finalization on large sites
 * (2026-06-18 incident — see infra/crawler/known-issues.md).
 */
class SiteGraphAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_inbound_counts_and_click_depth_in_chunks(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $crawlSite = CrawlSite::find($website->crawl_site_id);
        $home = $crawlSite->homepageUrl(); // https://example.com

        $mk = fn (string $path) => WebsitePage::create([
            'crawl_site_id' => $crawlSite->id,
            'url' => $home.$path,
            'url_hash' => WebsitePage::hashUrl($home.$path),
            'http_status' => 200,
            'is_indexable' => true,
            'inbound_link_count' => 999, // pre-seed garbage so we prove it gets reset
            'click_depth' => 999,
            'last_crawled_at' => now(),
        ]);

        $homePage = $mk('');          // depth 0
        $about = $mk('/about');       // depth 1 (home -> about)
        $team = $mk('/team');         // depth 2 (about -> team)
        $orphan = $mk('/orphan');     // unreachable -> null depth, 0 inbound

        $edge = fn (WebsitePage $from, WebsitePage $to) => WebsiteInternalLink::create([
            'crawl_site_id' => $crawlSite->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'status' => 'discovered',
        ]);

        $edge($homePage, $about);
        $edge($homePage, $about); // duplicate edge -> about inbound = 2
        $edge($about, $team);     // team reachable only via about -> depth 2, inbound 1
        // a non-discovered edge must be ignored
        WebsiteInternalLink::create([
            'crawl_site_id' => $crawlSite->id,
            'from_page_id' => $homePage->id,
            'to_page_id' => $orphan->id,
            'status' => 'suggested',
        ]);

        // Drive the graph passes directly. analyze() also calls CrawlValueRank::assign(),
        // which uses MySQL CHAR_LENGTH() and can't run on the sqlite test DB — orthogonal
        // to the inbound/click-depth computation under test here.
        $analyzer = app(SiteGraphAnalyzer::class);
        $invoke = function (string $method, ...$args) use ($analyzer) {
            $ref = new \ReflectionMethod($analyzer, $method);
            $ref->setAccessible(true);

            return $ref->invoke($analyzer, ...$args);
        };
        $graph = $invoke('buildGraph', $crawlSite->id);
        $invoke('writeInboundCounts', $crawlSite->id, $graph);
        $invoke('writeClickDepth', $crawlSite, $graph);

        $this->assertSame(0, (int) $homePage->fresh()->inbound_link_count);
        $this->assertSame(2, (int) $about->fresh()->inbound_link_count);
        $this->assertSame(1, (int) $team->fresh()->inbound_link_count);
        $this->assertSame(0, (int) $orphan->fresh()->inbound_link_count, 'non-discovered edges ignored');

        $this->assertSame(0, (int) $homePage->fresh()->click_depth);
        $this->assertSame(1, (int) $about->fresh()->click_depth);
        $this->assertSame(2, (int) $team->fresh()->click_depth);
        $this->assertNull($orphan->fresh()->click_depth, 'unreachable page stays null');
    }
}
