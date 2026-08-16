<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\LinkChecker;
use App\Services\Crawler\SiteIssueDetector;
use App\Support\Crawler\LinkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When is an external link actually broken? (2026-08-16 overhaul.)
 *
 * Prod evidence behind every case here: of 239 open "broken external link"
 * findings, 136 were support.google.com pages that answer HEAD with 404 and
 * GET with 200, 12 were tumblr share endpoints (HEAD 403 / GET 200), 48 were
 * auth walls and rate limits, and 10 were live Korean sites our DNS couldn't
 * resolve. All reported to clients as links to fix.
 */
class ExternalLinkVerdictTest extends TestCase
{
    use RefreshDatabase;

    private function seedPageWithExternalLink(string $href): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $run = CrawlRun::create([
            'crawl_site_id' => $website->crawl_site_id, 'trigger' => 'manual',
            'status' => 'running', 'started_at' => now(),
        ]);
        WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id,
            'url' => 'https://example.com/', 'url_hash' => WebsitePage::hashUrl('https://example.com/'),
            'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now(),
            'seo_signals' => ['external_links' => [['href' => $href, 'anchor' => 'ref']]],
        ]);

        return [$website, $run];
    }

    public function test_head_404_with_a_healthy_get_is_not_a_broken_link(): void
    {
        // The support.google.com pattern, verbatim.
        $href = 'https://support.google.com/ads/answer/1634057';
        Http::fake([$href => Http::sequence()
            ->push('', 404)   // HEAD
            ->push('ok', 200) // GET fallback
        ]);

        $problems = app(LinkChecker::class)->check([['href' => $href, 'anchor' => 'help']]);

        $this->assertSame([], $problems, 'a link that answers GET 200 must never be reported');
    }

    public function test_head_404_confirmed_by_get_404_is_reported(): void
    {
        $href = 'https://example.org/really-gone';
        Http::fake([$href => Http::sequence()->push('', 404)->push('', 404)->push('', 404)]);

        $problems = app(LinkChecker::class)->check([['href' => $href, 'anchor' => 'gone']]);

        $this->assertCount(1, $problems);
        $this->assertSame(404, $problems[0]['status']);
    }

    /** @dataProvider notDeadStatuses */
    public function test_blocked_or_limited_statuses_are_not_broken_links(int $status): void
    {
        $href = 'https://example.org/walled';
        Http::fake([$href => Http::response('', $status)]);

        [$website, $run] = $this->seedPageWithExternalLink($href);
        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseMissing('crawl_findings', [
            'crawl_site_id' => $website->crawl_site_id, 'type' => 'broken_external',
        ]);
    }

    public static function notDeadStatuses(): array
    {
        return [
            'auth wall' => [401],
            'payment required' => [402],
            'WAF block' => [403],
            'not acceptable' => [406],
            'teapot / scraper trap' => [418],
            'rate limited' => [429],
            'legal block' => [451],
            'server error' => [503],
        ];
    }

    public function test_a_real_404_still_reaches_the_client(): void
    {
        $href = 'https://example.org/dead-page';
        Http::fake([$href => Http::response('', 404)]);

        [$website, $run] = $this->seedPageWithExternalLink($href);
        app(SiteIssueDetector::class)->detect($website->crawlSite, $run);

        $this->assertDatabaseHas('crawl_findings', [
            'crawl_site_id' => $website->crawl_site_id,
            'type' => 'broken_external',
            'status' => 'open',
        ]);
    }

    public function test_status_rules_are_shared_and_explicit(): void
    {
        $this->assertTrue(LinkStatus::isDead(404));
        $this->assertTrue(LinkStatus::isDead(410));
        $this->assertTrue(LinkStatus::isDead(400));

        foreach (LinkStatus::NOT_DEAD as $status) {
            $this->assertFalse(LinkStatus::isDead($status), $status.' must not count as a dead link');
        }
        $this->assertFalse(LinkStatus::isDead(500), 'a server having a bad day is not the client’s broken link');
        $this->assertFalse(LinkStatus::isDead(null), 'unverifiable is not dead');
        $this->assertFalse(LinkStatus::isDead(200));
    }

    public function test_render_server_rescues_a_link_our_ip_cannot_reach(): void
    {
        config(['services.firecrawl.enabled' => true, 'services.firecrawl.url' => 'http://render.test:3002']);
        $href = 'https://example.org/geo-blocked';

        Http::fake([
            '*/v1/scrape' => Http::response(['success' => true, 'data' => ['metadata' => ['statusCode' => 200]]]),
            $href => Http::response('', 404), // HEAD + GET + proxy GET all say 404 from our IP
        ]);

        $problems = app(LinkChecker::class)->check([['href' => $href, 'anchor' => 'x']]);

        $this->assertSame([], $problems, 'the render server saw a live page — no finding');
    }

    public function test_render_server_confirms_a_genuinely_dead_link(): void
    {
        config(['services.firecrawl.enabled' => true, 'services.firecrawl.url' => 'http://render.test:3002']);
        $href = 'https://example.org/dead';

        Http::fake([
            '*/v1/scrape' => Http::response(['success' => true, 'data' => ['metadata' => ['statusCode' => 404]]]),
            $href => Http::response('', 404),
        ]);

        $problems = app(LinkChecker::class)->check([['href' => $href, 'anchor' => 'x']]);

        $this->assertCount(1, $problems);
        $this->assertSame(404, $problems[0]['status']);
    }
}
