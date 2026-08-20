<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\PageCrawlProcessor;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Crawler\FirecrawlBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Firecrawl crawl routing (cocomii 2026-08-20): sites behind non-Cloudflare
 * bot walls (Shopify/hCaptcha) aborted every run while the render fallback
 * sat gated behind Cloudflare-only markers, and known-blocked sites kept
 * burning doomed direct fetches. Now: any detector-classified block falls
 * back to Firecrawl, protected sites go Firecrawl-FIRST, and both are
 * bounded by a per-site daily render budget.
 */
class CrawlerFirecrawlRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const RENDERED = '<html><head><title>Rendered Product Page</title></head><body><h1>Cases</h1><p>'
        .'Real rendered content with enough words to look like a page. Lovely phone cases in many colors.</p></body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.firecrawl.enabled' => true,
            'services.firecrawl.url' => 'http://fc.test:3002',
            'services.firecrawl.key' => 'k',
            'services.firecrawl.timeout_s' => 20,
            'crawler.use_proxies' => false,
        ]);
        $this->app->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return preg_match('#^https?://#i', trim($url)) ? ['ok' => true] : ['ok' => false, 'reason' => 'bad'];
            }
        });
    }

    /** @return array{0: CrawlSite, 1: WebsitePage} */
    private function site(?string $protection = null): array
    {
        $website = Website::factory()->for(User::factory())->create(['domain' => 'shop.test']);
        $crawlSite = CrawlSite::find($website->crawl_site_id);
        if ($protection !== null) {
            $crawlSite->forceFill(['crawl_protection' => $protection, 'crawl_protection_at' => now()])->save();
        }
        $page = WebsitePage::create([
            'crawl_site_id' => $crawlSite->id,
            'url' => 'https://shop.test/',
            'url_hash' => WebsitePage::hashUrl('https://shop.test/'),
        ]);

        return [$crawlSite->fresh(), $page];
    }

    public function test_protected_site_goes_firecrawl_first_and_never_hits_the_site(): void
    {
        Http::fake([
            'http://fc.test:3002/*' => Http::response(['success' => true,
                'data' => ['html' => self::RENDERED, 'metadata' => ['statusCode' => 200]]]),
            'https://shop.test/*' => Http::response('should never be fetched', 500),
        ]);
        [$crawlSite, $page] = $this->site('cloudflare');

        app(PageCrawlProcessor::class)->process($page, $crawlSite);

        $page->refresh();
        $this->assertSame(200, (int) $page->http_status);
        $this->assertSame('Rendered Product Page', $page->title);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'shop.test'));
        $this->assertTrue(app(FirecrawlBudget::class)->renderedRecently($crawlSite->id));
    }

    public function test_non_cloudflare_captcha_wall_falls_back_to_firecrawl(): void
    {
        // hCaptcha wall: 200 status, no Cloudflare markers — RenderGate never
        // matched this, so pre-fix the fallback was dead for Shopify-style walls.
        Http::fake([
            'http://fc.test:3002/*' => Http::response(['success' => true,
                'data' => ['html' => self::RENDERED, 'metadata' => ['statusCode' => 200]]]),
            'https://shop.test/*' => Http::response('<html><body><form><div class="h-captcha"></div>Verify</form></body></html>', 200),
        ]);
        [$crawlSite, $page] = $this->site();

        app(PageCrawlProcessor::class)->process($page, $crawlSite);

        $page->refresh();
        $this->assertSame(200, (int) $page->http_status);
        $this->assertSame('Rendered Product Page', $page->title);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'fc.test'));
    }

    public function test_zero_budget_disables_the_render_path(): void
    {
        config(['crawler.firecrawl_daily_page_budget' => 0]);
        Http::fake([
            'https://shop.test/*' => Http::response('<html><body><div class="h-captcha"></div></body></html>', 200),
        ]);
        [$crawlSite, $page] = $this->site('cloudflare');

        app(PageCrawlProcessor::class)->process($page, $crawlSite);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'fc.test'));
    }

    public function test_budget_counts_attempts_and_stops_at_the_limit(): void
    {
        config(['crawler.firecrawl_daily_page_budget' => 2]);
        $budget = app(FirecrawlBudget::class);

        $this->assertTrue($budget->allow('site-x'));
        $budget->consume('site-x');
        $this->assertTrue($budget->allow('site-x'));
        $budget->consume('site-x');
        $this->assertFalse($budget->allow('site-x'));
        // Other sites are unaffected.
        $this->assertTrue($budget->allow('site-y'));
    }

    public function test_protection_stays_sticky_when_the_run_used_the_render_path(): void
    {
        [$crawlSite] = $this->site('cloudflare');
        app(FirecrawlBudget::class)->markRendered($crawlSite->id);

        $run = CrawlRun::create([
            'crawl_site_id' => $crawlSite->id, 'trigger' => CrawlRun::TRIGGER_MANUAL,
            'status' => CrawlRun::STATUS_RUNNING, 'started_at' => now(), 'pages_fetched' => 5,
        ]);
        (new \App\Jobs\AnalyzeSiteJob($run->id))->handle(
            app(\App\Services\Crawler\SiteGraphAnalyzer::class),
            app(\App\Services\Crawler\SiteIssueDetector::class),
            app(\App\Support\Crawler\BlockDetector::class),
            app(\App\Services\Crawler\InternalLinkSuggester::class),
            app(\App\Support\Crawler\TermExtractor::class),
        );

        $this->assertNotNull($crawlSite->fresh()->crawl_protection, 'render-carried runs must keep the flag');
    }
}
