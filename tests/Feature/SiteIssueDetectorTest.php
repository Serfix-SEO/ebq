<?php

namespace Tests\Feature;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\SiteIssueDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteIssueDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_findings_skip_query_string_urls(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        // seo_signals must be non-null: detectForPage() skips all on-page +
        // link-structure checks for pages the analyzer never produced signals
        // for (a null-signal page would generate false findings).
        // Clean-path page with no inbound links -> a real orphan.
        WebsitePage::create(['crawl_site_id' => $cs, 'url' => 'https://example.com/clean', 'url_hash' => WebsitePage::hashUrl('https://example.com/clean'), 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'last_crawled_at' => now(), 'seo_signals' => []]);
        // Parameter URL with no inbound links -> expected, NOT flagged.
        WebsitePage::create(['crawl_site_id' => $cs, 'url' => 'https://example.com/gen?name=max', 'url_hash' => WebsitePage::hashUrl('https://example.com/gen?name=max'), 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'last_crawled_at' => now(), 'seo_signals' => []]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'orphan_page',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/clean'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'orphan_page',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/gen?name=max'),
        ]);
    }

    public function test_broken_page_finding_carries_discovery_provenance(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        $home = WebsitePage::create(['crawl_site_id' => $cs, 'url' => 'https://example.com/', 'url_hash' => WebsitePage::hashUrl('https://example.com/'), 'http_status' => 200, 'is_indexable' => true, 'internal_link_count' => 1, 'last_crawled_at' => now()]);
        // A 404 that is both listed in the sitemap AND linked from the homepage.
        $dead = WebsitePage::create(['crawl_site_id' => $cs, 'url' => 'https://example.com/dead', 'url_hash' => WebsitePage::hashUrl('https://example.com/dead'), 'http_status' => 404, 'is_indexable' => false, 'source_sitemap' => true, 'discovered_at' => now()->subDay(), 'last_crawled_at' => now()]);
        WebsiteInternalLink::create(['crawl_site_id' => $cs, 'from_page_id' => $home->id, 'to_page_id' => $dead->id, 'anchor_text' => 'Broken link', 'status' => 'discovered']);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $finding = CrawlFinding::where('crawl_site_id', $cs)
            ->where('type', 'broken_page')
            ->where('affected_url_hash', CrawlFinding::hashUrl('https://example.com/dead'))
            ->first();

        $this->assertNotNull($finding, 'expected a broken_page finding for the 404');
        $detail = $finding->detail;
        $this->assertSame(404, $detail['http_status']);
        $this->assertSame(1, $detail['inbound_internal']);
        $this->assertTrue($detail['source_sitemap']);
        $this->assertSame('https://example.com/', $detail['referrers'][0]['url']);
        $this->assertSame('Broken link', $detail['referrers'][0]['anchor']);
    }

    public function test_hreflang_without_self_reference_and_with_canonical_conflict_is_flagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        // /ar/ declares hreflang alternates for its siblings but never itself, and
        // its canonical points at the bare domain instead of itself — the exact
        // shape Semrush flagged on a real site (soulfamburger.com-class i18n bug).
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/ar/',
            'url_hash' => WebsitePage::hashUrl('https://example.com/ar/'),
            'http_status' => 200, 'is_indexable' => false, 'canonical_url' => 'https://example.com/',
            'inbound_link_count' => 1, 'last_crawled_at' => now(),
            'seo_signals' => [
                'canonical_points_away' => true,
                'hreflang_count' => 1,
                'hreflang_self_ref' => false,
                'hreflangs' => [['hreflang' => 'fr', 'href' => 'https://example.com/fr/']],
            ],
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'missing_self_hreflang',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/ar/'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'hreflang_canonical_conflict',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/ar/'),
        ]);
    }

    public function test_long_redirect_chain_and_slow_response_are_flagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/old',
            'url_hash' => WebsitePage::hashUrl('https://example.com/old'),
            'http_status' => 200, 'is_indexable' => false,
            'redirect_target' => 'https://example.com/new', 'last_crawled_at' => now(),
            'seo_signals' => ['redirect_chain' => 4, 'ttfb_ms' => 6000],
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'redirect_chain_too_long',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/old'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'slow_response',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/old'),
        ]);
    }

    public function test_missing_twitter_card_and_invalid_structured_data_are_flagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/page',
            'url_hash' => WebsitePage::hashUrl('https://example.com/page'),
            'http_status' => 200, 'is_indexable' => true, 'title' => 'A perfectly good title here',
            'meta_description' => str_repeat('a good description ', 4), 'word_count' => 500,
            'last_crawled_at' => now(),
            'seo_signals' => [
                'h1_count' => 1, 'og_tag_count' => 2, 'twitter_tag_count' => 0,
                'schema_types' => ['Article'], 'invalid_schema_count' => 1,
            ],
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'missing_twitter_card',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/page'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'invalid_structured_data',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/page'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'missing_structured_data',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/page'),
        ]);
    }

    public function test_mixed_content_on_an_https_page_is_flagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/page',
            'url_hash' => WebsitePage::hashUrl('https://example.com/page'),
            'http_status' => 200, 'is_indexable' => true, 'title' => 'A perfectly good title here',
            'meta_description' => str_repeat('a good description ', 4), 'word_count' => 500,
            'last_crawled_at' => now(),
            'seo_signals' => [
                'h1_count' => 1, 'og_tag_count' => 2, 'twitter_tag_count' => 2,
                'schema_types' => ['Article'],
                'mixed_content_count' => 1, 'mixed_content_urls' => ['http://cdn.example.com/logo.png'],
            ],
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'mixed_content', 'category' => 'security',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/page'),
        ]);
    }

    public function test_robots_txt_blocked_sitemap_page_is_flagged_with_no_gsc_connected(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        // No SearchConsoleData rows at all — this subscriber never connected GSC.
        // sitemap-listed is the crawl-only signal that this page is real, not an
        // intentionally-excluded utility path.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/private/secret',
            'url_hash' => WebsitePage::hashUrl('https://example.com/private/secret'),
            'http_status' => 200, 'is_indexable' => true, 'source_sitemap' => true, 'last_crawled_at' => now(),
        ]);
        // Disallowed AND not sitemap-listed AND not internally linked — the normal
        // shape of an intentionally-excluded utility path. Must stay clean.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/private/utility',
            'url_hash' => WebsitePage::hashUrl('https://example.com/private/utility'),
            'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now(),
        ]);

        $this->mock(CrawlFetcher::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->with('https://example.com/robots.txt', [], 10)
                ->andReturn(['ok' => true, 'status' => 200, 'body' => "User-agent: *\nDisallow: /private/\n"]);
        });

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'robots_blocked_important', 'severity' => 'medium',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/private/secret'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'robots_blocked_important',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/private/utility'),
        ]);
    }

    public function test_duplicate_content_across_pages_is_flagged_but_distinct_content_is_not(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        $sameHash = sha1('identical body text across two pages');
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/a',
            'url_hash' => WebsitePage::hashUrl('https://example.com/a'),
            'http_status' => 200, 'is_indexable' => true, 'word_count' => 50,
            'content_hash' => $sameHash, 'last_crawled_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/b',
            'url_hash' => WebsitePage::hashUrl('https://example.com/b'),
            'http_status' => 200, 'is_indexable' => true, 'word_count' => 50,
            'content_hash' => $sameHash, 'last_crawled_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/unique',
            'url_hash' => WebsitePage::hashUrl('https://example.com/unique'),
            'http_status' => 200, 'is_indexable' => true, 'word_count' => 50,
            'content_hash' => sha1('totally different content'), 'last_crawled_at' => now(),
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'duplicate_content',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/a'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'duplicate_content',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/b'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'duplicate_content',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/unique'),
        ]);
    }

    public function test_sitemap_listing_broken_redirect_and_noindex_urls_is_flagged(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/dead',
            'url_hash' => WebsitePage::hashUrl('https://example.com/dead'),
            'http_status' => 404, 'is_indexable' => false, 'source_sitemap' => true, 'last_crawled_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/old',
            'url_hash' => WebsitePage::hashUrl('https://example.com/old'),
            'http_status' => 200, 'is_indexable' => false, 'source_sitemap' => true,
            'redirect_target' => 'https://example.com/new', 'last_crawled_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/hidden',
            'url_hash' => WebsitePage::hashUrl('https://example.com/hidden'),
            'http_status' => 200, 'is_indexable' => false, 'source_sitemap' => true, 'last_crawled_at' => now(),
        ]);
        // A clean, sitemap-listed, indexable page — must stay quiet.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/fine',
            'url_hash' => WebsitePage::hashUrl('https://example.com/fine'),
            'http_status' => 200, 'is_indexable' => true, 'source_sitemap' => true, 'last_crawled_at' => now(),
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'sitemap_broken_url',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/dead'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'sitemap_redirect_url',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/old'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'sitemap_noindex_url',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/hidden'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/fine'),
            'category' => 'sitemap',
        ]);
    }

    public function test_noindex_important_and_canonical_mismatch_fire_without_any_gsc_data(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $cs = $website->crawl_site_id;
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);

        // No SearchConsoleData at all — this subscriber never connected GSC.
        // Both pages are sitemap-listed, the crawl-only "this page is real" signal.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/noindexed',
            'url_hash' => WebsitePage::hashUrl('https://example.com/noindexed'),
            'http_status' => 200, 'is_indexable' => false, 'source_sitemap' => true,
            'robots_directives' => 'noindex', 'last_crawled_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/canon-away',
            'url_hash' => WebsitePage::hashUrl('https://example.com/canon-away'),
            'http_status' => 200, 'is_indexable' => false, 'source_sitemap' => true,
            'canonical_url' => 'https://example.com/elsewhere', 'last_crawled_at' => now(),
            'seo_signals' => ['canonical_points_away' => true],
        ]);
        // Same canonical-points-away shape, but NOT sitemap-listed and no inbound
        // links — the common intentional ?param-dedup case. Must stay quiet.
        WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => 'https://example.com/gen?name=max',
            'url_hash' => WebsitePage::hashUrl('https://example.com/gen?name=max'),
            'http_status' => 200, 'is_indexable' => false, 'last_crawled_at' => now(),
            'canonical_url' => 'https://example.com/gen', 'seo_signals' => ['canonical_points_away' => true],
        ]);

        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'noindex_important', 'severity' => 'medium',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/noindexed'),
        ]);
        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'canonical_mismatch', 'severity' => 'medium',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/canon-away'),
        ]);
        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $cs, 'type' => 'canonical_mismatch',
            'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/gen?name=max'),
        ]);
    }
}
