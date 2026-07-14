<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeSiteJob;
use App\Jobs\CrawlPageBatchJob;
use App\Jobs\MatchRedirectFor404Job;
use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlFrontierBuilder;
use App\Services\Crawler\PageCrawlProcessor;
use App\Services\Crawler\SiteGraphAnalyzer;
use App\Services\Crawler\SiteIssueDetector;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Crawler\BlockDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function allowAllGuard(): void
    {
        // Avoid real DNS in tests: a guard that allows any http(s) URL.
        $guard = new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return preg_match('#^https?://#i', trim($url)) ? ['ok' => true] : ['ok' => false, 'reason' => 'bad'];
            }
        };
        $this->app->instance(SafeHttpGuard::class, $guard);
    }

    private function seedGsc(Website $website): void
    {
        foreach ([['https://example.com/', 5], ['https://example.com/a', 10], ['https://example.com/b', 2], ['https://example.com/c', 0]] as [$url, $clicks]) {
            SearchConsoleData::create([
                'website_id' => $website->id,
                'date' => now()->subDays(3)->toDateString(),
                'query' => 'kw', 'page' => $url, 'clicks' => $clicks,
                'impressions' => $clicks * 20, 'position' => 5, 'country' => 'USA',
                'device' => 'DESKTOP', 'ctr' => 0.05,
            ]);
        }
    }

    /** Run a crawl entirely in-process (direct handle() calls — Bus::batch is tested separately). */
    private function runCrawl(Website $website): CrawlRun
    {
        $run = CrawlRun::create([
            'crawl_site_id' => $website->crawl_site_id, 'trigger' => CrawlRun::TRIGGER_MANUAL,
            'status' => CrawlRun::STATUS_RUNNING, 'started_at' => now(),
        ]);

        app(CrawlFrontierBuilder::class)->build($website->crawlSite);

        // Two passes so stub pages discovered via on-page links (e.g. /missing) get crawled too.
        for ($pass = 0; $pass < 2; $pass++) {
            $ids = WebsitePage::where('crawl_site_id', $website->crawl_site_id)->due()->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            (new CrawlPageBatchJob($ids, $run->id))->handle(app(PageCrawlProcessor::class));
        }

        (new AnalyzeSiteJob($run->id))->handle(
            app(SiteGraphAnalyzer::class), app(SiteIssueDetector::class), app(BlockDetector::class),
            app(\App\Services\Crawler\InternalLinkSuggester::class), app(\App\Support\Crawler\TermExtractor::class)
        );

        return $run->fresh();
    }

    public function test_full_crawl_pipeline_builds_inventory_graph_and_findings(): void
    {
        Queue::fake([MatchRedirectFor404Job::class]); // neutralize only the 404 bridge
        $this->allowAllGuard();

        $r = fn (string $b, int $s = 200, array $h = []) => Http::response($b, $s, $h);
        Http::fake([
            'https://example.com/' => $r('<html><head><title>Home Page Title Here</title><meta name="description" content="A perfectly reasonable homepage meta description for tests."></head><body><h1>Welcome</h1><p>'.str_repeat('word ', 60).'</p><a href="/a">Alpha</a> <a href="/b">Beta</a> <a href="/missing">Gone</a> <a href="https://broken.iana.org/x">ext</a></body></html>'),
            'https://example.com/a' => $r('<html><head><title>Alpha Page Title Here</title><meta name="robots" content="noindex"><meta name="description" content="Alpha description long enough to be valid for the tests here."></head><body><h1>Alpha</h1><p>'.str_repeat('alpha ', 60).'</p><a href="/b">Beta</a></body></html>'),
            'https://example.com/b' => $r('<html><head><title>Beta</title></head><body><h1>Beta</h1><p>thin</p></body></html>'),
            'https://example.com/c' => $r('<html><head><title>Cee Page Title Here</title><meta name="description" content="Cee description long enough to be valid for the tests here."></head><body><h1>Cee</h1><p>'.str_repeat('cee ', 60).'</p></body></html>'),
            'https://example.com/missing' => $r('not found', 404),
            'https://broken.iana.org/x' => $r('gone', 404),
            'https://example.com/sitemap.xml' => $r('<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url></urlset>'),
            '*' => $r('<html><body>fb</body></html>'),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->withBothSources()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $website->sitemaps()->create(['path' => 'https://example.com/sitemap.xml', 'source' => 'manual']);
        $this->seedGsc($website);

        $run = $this->runCrawl($website);

        // Inventory
        $a = WebsitePage::where('crawl_site_id', $website->crawl_site_id)->where('url_hash', WebsitePage::hashUrl('https://example.com/a'))->first();
        $this->assertSame(200, $a->http_status);
        $this->assertFalse((bool) $a->is_indexable, 'noindex page should be non-indexable');
        $missing = WebsitePage::where('crawl_site_id', $website->crawl_site_id)->where('url_hash', WebsitePage::hashUrl('https://example.com/missing'))->first();
        $this->assertSame(404, $missing->http_status);

        // Graph: /b has inbound links; /c is an orphan (GSC-only, unlinked, NOT in
        // sitemap — sitemap-listed pages are excluded from orphan_page by design,
        // see SiteIssueDetector::detectForPage).
        $b = WebsitePage::where('crawl_site_id', $website->crawl_site_id)->where('url_hash', WebsitePage::hashUrl('https://example.com/b'))->first();
        $this->assertGreaterThanOrEqual(1, (int) $b->inbound_link_count);
        $c = WebsitePage::where('crawl_site_id', $website->crawl_site_id)->where('url_hash', WebsitePage::hashUrl('https://example.com/c'))->first();
        $this->assertSame(0, (int) $c->inbound_link_count);

        // Findings
        $types = CrawlFinding::where('crawl_site_id', $website->crawl_site_id)->where('status', 'open')->pluck('type')->all();
        $this->assertContains('noindex_important', $types);
        $this->assertContains('broken_page', $types);
        $this->assertContains('broken_internal', $types);
        $this->assertContains('orphan_page', $types);
        $this->assertContains('thin_content', $types);
        $this->assertContains('broken_external', $types);

        // Run closed with a health score
        $this->assertSame(CrawlRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->health_score);
        $this->assertGreaterThan(0, (int) $run->pages_fetched);
    }

    /**
     * A confirmed 4xx external link is flagged broken_external; an external link
     * that only times out / errors at the transport layer is INCONCLUSIVE and must
     * NOT be flagged (regression: bestproservicesdubai.com's live mohre.gov.ae /
     * wam.ae links were reported broken purely on cURL error 28 timeouts).
     */
    public function test_broken_external_ignores_inconclusive_timeouts_but_flags_confirmed_dead(): void
    {
        Queue::fake([MatchRedirectFor404Job::class]);
        $this->allowAllGuard();

        $r = fn (string $b, int $s = 200) => Http::response($b, $s);
        Http::fake([
            'https://example.com/sitemap.xml' => $r('<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url></urlset>'),
            'https://dead.iana.org/x' => $r('gone', 404),
            // Both the HEAD and the GET fallback time out — pure transport failure.
            'https://slow.iana.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out after 8000 milliseconds'),
            'https://example.com*' => $r('<html><head><title>Home Page Title Here</title><meta name="description" content="A perfectly reasonable homepage meta description for tests."></head><body><h1>Hi</h1><p>'.str_repeat('word ', 60).'</p><a href="https://dead.iana.org/x">dead</a> <a href="https://slow.iana.org/y">slow</a></body></html>'),
            '*' => $r('<html><body>fb</body></html>'),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->withBothSources()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $website->sitemaps()->create(['path' => 'https://example.com/sitemap.xml', 'source' => 'manual']);

        $this->runCrawl($website);

        $broken = CrawlFinding::where('crawl_site_id', $website->crawl_site_id)
            ->where('type', 'broken_external')->where('status', 'open')
            ->pluck('affected_url')->all();

        $this->assertContains('https://dead.iana.org/x', $broken, 'confirmed 404 must be flagged');
        $this->assertNotContains('https://slow.iana.org/y', $broken, 'a timeout is not proof of a dead link');
    }

    public function test_recrawl_uses_conditional_get_and_hash_skip(): void
    {
        $this->allowAllGuard();
        $body = '<html><head><title>Beta Page Title</title></head><body><h1>Beta</h1><p>thin</p></body></html>';
        // Sequential responses for the same URL: changed -> unchanged -> 304.
        Http::fake([
            'https://example.com/b' => Http::sequence()
                ->push($body, 200, ['ETag' => '"v1"'])
                ->push($body, 200, ['ETag' => '"v1"'])
                ->push('', 304),
        ]);

        $user = User::factory()->create();
        $website = Website::factory()->withBothSources()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $page = WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/b',
            'url_hash' => WebsitePage::hashUrl('https://example.com/b'),
        ]);

        $processor = app(PageCrawlProcessor::class);
        $this->assertSame('changed', $processor->process($page->fresh()));
        $this->assertSame('"v1"', $page->fresh()->etag);
        $this->assertSame('unchanged', $processor->process($page->fresh()));
        $this->assertSame('not_modified', $processor->process($page->fresh()));
    }
}
