<?php

namespace Tests\Feature;

use App\Jobs\LinkCrawlBatchJob;
use App\Models\DomainMetric;
use App\Models\LinkCrawlFrontier;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\DomainRateLimiter;
use App\Services\Crawler\ProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class LinkCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crawler.link_crawl.enabled' => true, 'crawler.delay_ms' => 0]);
    }

    public function test_seed_command_queues_important_domains_homepages(): void
    {
        DomainMetric::create(['domain' => 'active-client.test', 'tier' => 'active', 'times_seen' => 5, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        DomainMetric::create(['domain' => 'popular-ref.test', 'tier' => 'free', 'times_seen' => 99, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('ebq:seed-link-crawl')->assertSuccessful();

        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'active-client.test', 'url' => 'https://active-client.test/', 'depth' => 0, 'status' => 'pending']);
        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'popular-ref.test']);
        // Re-run is idempotent (no duplicate hosts).
        $this->artisan('ebq:seed-link-crawl')->assertSuccessful();
        $this->assertSame(2, LinkCrawlFrontier::count());
    }

    private function fakeFetcher(string $host, string $bodyHtml, string $robots = ''): CrawlFetcher
    {
        $f = Mockery::mock(CrawlFetcher::class);
        $ok = fn (string $body) => ['ok' => true, 'blocked' => false, 'status' => 200, 'not_modified' => false, 'body' => $body, 'headers' => []];
        $f->shouldReceive('fetch')->with('https://'.$host.'/robots.txt', Mockery::any(), Mockery::any())->andReturn($ok($robots));
        $f->shouldReceive('fetch')->andReturnUsing(fn ($url) => $ok($bodyHtml));

        return $f;
    }

    private function bindNoOpPolitness(): void
    {
        $rl = Mockery::mock(DomainRateLimiter::class);
        $rl->shouldReceive('throttle', 'recordFetch', 'recordBlock', 'recordWaf');
        $rl->shouldReceive('isWafProtected')->andReturn(false);
        $this->app->instance(DomainRateLimiter::class, $rl);
        $pool = Mockery::mock(ProxyPool::class);
        $pool->shouldReceive('available')->andReturn(false);
        $pool->shouldReceive('markSuccess', 'markFailure');
        $this->app->instance(ProxyPool::class, $pool);
    }

    public function test_batch_records_external_links_and_seeds_internal_pages(): void
    {
        $this->bindNoOpPolitness();
        $row = LinkCrawlFrontier::create(['host' => 'src.test', 'url' => 'https://src.test/', 'url_hash' => LinkCrawlFrontier::hashFor('https://src.test/'), 'depth' => 0, 'status' => 'pending', 'next_at' => now()]);

        $html = '<html><body>'
            .'<a href="https://client.test/page">a client link</a>'
            .'<a href="https://other.test/">another</a>'
            .'<a href="https://src.test/about">about us</a>'  // internal → seeds a depth-1 row
            .'</body></html>';
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('src.test', $html));

        (new LinkCrawlBatchJob([$row->id]))->handle(
            app(CrawlFetcher::class), app(DomainRateLimiter::class), app(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class),
        );

        // External edges landed in the permanent graph (own_crawl source).
        $targets = DB::table('link_edges')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->where('link_edges.source', 'own_crawl')->pluck('t.name')->all();
        $this->assertContains('client.test', $targets);
        $this->assertContains('other.test', $targets);

        // Seed row done + recrawl scheduled; internal page queued as depth 1.
        $this->assertSame('done', $row->fresh()->status);
        $this->assertTrue($row->fresh()->next_at->isFuture());
        $this->assertDatabaseHas('link_crawl_frontier', ['url' => 'https://src.test/about', 'depth' => 1, 'status' => 'pending']);
    }

    public function test_batch_respects_robots_disallow(): void
    {
        $this->bindNoOpPolitness();
        $row = LinkCrawlFrontier::create(['host' => 'blocked.test', 'url' => 'https://blocked.test/', 'url_hash' => LinkCrawlFrontier::hashFor('https://blocked.test/'), 'depth' => 0, 'status' => 'pending', 'next_at' => now()]);
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('blocked.test', '<a href="https://x.test/">x</a>', "User-agent: *\nDisallow: /"));

        (new LinkCrawlBatchJob([$row->id]))->handle(
            app(CrawlFetcher::class), app(DomainRateLimiter::class), app(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class),
        );

        $this->assertSame('blocked', $row->fresh()->status);
        $this->assertSame(0, DB::table('link_edges')->count());
    }

    public function test_cloudflare_block_marks_blocked_and_records_no_edges(): void
    {
        $this->bindNoOpPolitness();
        $row = LinkCrawlFrontier::create(['host' => 'waf.test', 'url' => 'https://waf.test/', 'url_hash' => LinkCrawlFrontier::hashFor('https://waf.test/'), 'depth' => 0, 'status' => 'pending', 'next_at' => now()]);

        // Cloudflare challenge: 403 + cf-mitigated challenge header + body with
        // outbound links we must NOT trust (it's the block page, not the site).
        $f = Mockery::mock(CrawlFetcher::class);
        $f->shouldReceive('fetch')->with('https://waf.test/robots.txt', Mockery::any(), Mockery::any())
            ->andReturn(['ok' => true, 'status' => 200, 'body' => '', 'headers' => []]);
        $f->shouldReceive('fetch')->andReturn([
            'ok' => true, 'status' => 403, 'body' => '<a href="https://cloudflare.com/">cf</a> Are you a robot?',
            'headers' => ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'],
        ]);
        $this->app->instance(CrawlFetcher::class, $f);

        (new LinkCrawlBatchJob([$row->id]))->handle(
            app(CrawlFetcher::class), app(DomainRateLimiter::class), app(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class),
        );

        $this->assertSame('blocked', $row->fresh()->status);
        $this->assertSame(0, DB::table('link_edges')->count()); // never trust a WAF challenge page's links
    }

    public function test_disabled_flag_is_a_no_op(): void
    {
        config(['crawler.link_crawl.enabled' => false]);
        $row = LinkCrawlFrontier::create(['host' => 's.test', 'url' => 'https://s.test/', 'url_hash' => LinkCrawlFrontier::hashFor('https://s.test/'), 'depth' => 0, 'status' => 'pending', 'next_at' => now()]);

        (new LinkCrawlBatchJob([$row->id]))->handle(
            Mockery::mock(CrawlFetcher::class), Mockery::mock(DomainRateLimiter::class), Mockery::mock(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class),
        );

        $this->assertSame('pending', $row->fresh()->status);
    }
}
