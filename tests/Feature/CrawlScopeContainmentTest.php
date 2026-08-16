<?php

namespace Tests\Feature;

use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\PageCrawlProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A crawl must never wander off the site it belongs to.
 *
 * The leak this locks down: `serfix.io/auth/google/sso` 302s to
 * accounts.google.com, the analyzer (correctly) re-anchors relative links to
 * the post-redirect URL, and HtmlAuditor then calls every link on GOOGLE's
 * page "internal" because it shares a host with the document it parsed. Each
 * one became a page row under serfix.io's crawl site and went back into the
 * frontier: 194 of 234 pages were policies.google.com, and the client's Site
 * Health showed 164 "broken internal links" they could never fix.
 * facebook.com's crawl pulled in 15,508 off-domain pages the same way.
 */
class CrawlScopeContainmentTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithPage(string $domain, string $url): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => $domain]);
        $site = CrawlSite::find($website->crawl_site_id);
        $page = WebsitePage::create([
            'crawl_site_id' => $site->id,
            'url' => $url,
            'url_hash' => WebsitePage::hashUrl($url),
            'http_status' => 200,
            'is_indexable' => true,
        ]);

        return [$site, $page];
    }

    public function test_an_off_domain_redirect_cannot_seed_pages_for_our_crawl_site(): void
    {
        [$site, $page] = $this->siteWithPage('serfix.io', 'https://serfix.io/auth/google/sso');

        // The redirect target's HTML: every link is same-host with the FINAL
        // url, so HtmlAuditor hands them all over as "internal".
        Http::fake([
            '*' => Http::response(
                '<html><head><title>Sign in</title></head><body><p>'.str_repeat('sign in with google ', 60).'</p>'
                .'<a href="https://accounts.google.com/TOS">Terms</a>'
                .'<a href="https://policies.google.com/privacy">Privacy</a>'
                .'<a href="/v3/signin/identifier">Identifier</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        app(PageCrawlProcessor::class)->process($page->fresh(), $site);

        $urls = DB::table('website_pages')->where('crawl_site_id', $site->id)->pluck('url')->all();

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('google.com', $url, 'a foreign host was seeded into the crawl');
        }
    }

    public function test_same_site_links_are_still_discovered(): void
    {
        [$site, $page] = $this->siteWithPage('serfix.io', 'https://serfix.io/');

        Http::fake([
            '*' => Http::response(
                '<html><head><title>Home</title></head><body><p>'.str_repeat('content words here ', 60).'</p>'
                .'<a href="https://serfix.io/pricing">Pricing</a>'
                .'<a href="https://serfix.io/guide">Guide</a>'
                .'<a href="https://policies.google.com/privacy">Privacy</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        app(PageCrawlProcessor::class)->process($page->fresh(), $site);

        $urls = DB::table('website_pages')->where('crawl_site_id', $site->id)->pluck('url')->all();

        $this->assertContains('https://serfix.io/pricing', $urls);
        $this->assertContains('https://serfix.io/guide', $urls);
        $this->assertNotContains('https://policies.google.com/privacy', $urls);
    }

    public function test_a_sitemap_listing_foreign_hosts_cannot_widen_the_crawl(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.org']);
        $site = CrawlSite::find($website->crawl_site_id);

        DB::table('website_sitemaps')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'website_id' => $website->id,
            'path' => 'https://example.org/sitemap.xml',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            '*sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.org/a</loc></url>'
                .'<url><loc>https://evil.test/b</loc></url>'
                .'<url><loc>https://cdn.example.org/c</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
            '*' => Http::response('', 200),
        ]);

        app(\App\Services\Crawler\CrawlFrontierBuilder::class)->build($site);

        $urls = DB::table('website_pages')->where('crawl_site_id', $site->id)->pluck('url')->all();

        $this->assertContains('https://example.org/a', $urls);
        $this->assertContains('https://cdn.example.org/c', $urls);
        $this->assertNotContains('https://evil.test/b', $urls);
    }

    public function test_a_batch_whose_crawl_site_was_deleted_mid_run_writes_nothing(): void
    {
        [$site, $page] = $this->siteWithPage('example.net', 'https://example.net/');
        $siteId = $site->id;

        Http::fake(['*' => Http::response('<html><body>hi</body></html>', 200)]);

        // Last subscriber leaves while the batch is queued: Website::deleted
        // purges the shared crawl, but this job is already in flight.
        DB::table('websites')->where('crawl_site_id', $siteId)->delete();
        DB::table('crawl_sites')->where('id', $siteId)->delete();

        (new \App\Jobs\CrawlPageBatchJob([$page->id], (string) \Illuminate\Support\Str::ulid()))
            ->handle(app(PageCrawlProcessor::class));

        Http::assertSentCount(0);
        $this->assertNull(WebsitePage::find($page->id)->last_crawled_at);
    }
}
