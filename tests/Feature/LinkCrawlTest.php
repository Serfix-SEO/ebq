<?php

namespace Tests\Feature;

use App\Jobs\LinkCrawlBatchJob;
use App\Models\DomainMetric;
use App\Models\LinkCrawlFrontier;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\DomainRateLimiter;
use App\Services\Crawler\ProxyPool;
use App\Services\LinkGraph\FrontierClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    private function pending(string $host, int $depth = 0): LinkCrawlFrontier
    {
        $url = 'https://'.$host.'/';

        return LinkCrawlFrontier::create([
            'host' => $host, 'url' => $url, 'url_hash' => LinkCrawlFrontier::hashFor($url),
            'depth' => $depth, 'status' => 'pending', 'next_at' => now(),
        ]);
    }

    public function test_seed_command_queues_important_domains_homepages(): void
    {
        DomainMetric::create(['domain' => 'active-client.test', 'tier' => 'active', 'times_seen' => 5, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        DomainMetric::create(['domain' => 'popular-ref.test', 'tier' => 'free', 'times_seen' => 99, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('ebq:seed-link-crawl')->assertSuccessful();

        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'active-client.test', 'status' => 'pending', 'depth' => 0]);
        $this->artisan('ebq:seed-link-crawl')->assertSuccessful();
        $this->assertSame(2, LinkCrawlFrontier::count());
    }

    public function test_seed_backfills_from_discovered_graph_when_metrics_exhausted(): void
    {
        // No domain_metrics rows → phase 1 seeds nothing; the discovered graph
        // (link_domains + link_edges) must fill the frontier instead.
        $hot = DB::table('link_domains')->insertGetId(['name' => 'hot.test']);
        $cold = DB::table('link_domains')->insertGetId(['name' => 'cold.test']);
        $src = DB::table('link_domains')->insertGetId(['name' => 'src.test']);
        // hot.test has 2 inbound edges, cold.test has 1 → hot ranks first.
        $edge = fn ($from, $to) => ['from_domain_id' => $from, 'to_domain_id' => $to, 'source' => 'own_crawl',
            'dofollow' => true, 'first_seen_at' => now(), 'last_seen_at' => now()];
        DB::table('link_edges')->insert([$edge($src, $hot), $edge($cold, $hot), $edge($src, $cold)]);

        $this->artisan('ebq:seed-link-crawl')->assertSuccessful();

        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'hot.test', 'depth' => 0, 'status' => 'pending']);
        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'cold.test', 'depth' => 0]);
    }

    // ── Atomic claiming ─────────────────────────────────────────

    public function test_claim_is_atomic_and_disjoint(): void
    {
        foreach (range(1, 10) as $i) {
            $this->pending("d{$i}.test");
        }
        $claimer = app(FrontierClaimer::class);

        $a = $claimer->claim(4)->pluck('id')->all();
        $b = $claimer->claim(4)->pluck('id')->all();

        $this->assertCount(4, $a);
        $this->assertCount(4, $b);
        $this->assertEmpty(array_intersect($a, $b), 'two claims must be disjoint');
        // Claimed rows are in_progress under a lease; 2 remain pending.
        $this->assertSame(8, LinkCrawlFrontier::where('status', 'in_progress')->count());
        $this->assertSame(2, LinkCrawlFrontier::where('status', 'pending')->count());
        $this->assertNotNull(LinkCrawlFrontier::where('status', 'in_progress')->first()->leased_until);
    }

    public function test_reaper_returns_expired_leases_to_pending(): void
    {
        $row = $this->pending('stuck.test');
        $row->update(['status' => 'in_progress', 'lease_id' => 'x', 'leased_until' => now()->subMinutes(20)]);
        $fresh = $this->pending('fresh.test');
        $fresh->update(['status' => 'in_progress', 'lease_id' => 'y', 'leased_until' => now()->addMinutes(9)]);

        $reaped = app(FrontierClaimer::class)->reapExpired();

        $this->assertSame(1, $reaped);
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->lease_id);
        $this->assertSame('in_progress', $fresh->fresh()->status); // unexpired lease untouched
    }

    // ── Batch behaviour ─────────────────────────────────────────

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

    private function runBatch(): void
    {
        (new LinkCrawlBatchJob())->handle(
            app(CrawlFetcher::class), app(DomainRateLimiter::class), app(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class), app(FrontierClaimer::class),
        );
    }

    public function test_batch_claims_records_links_seeds_internal_and_self_replaces(): void
    {
        Queue::fake();
        $this->bindNoOpPolitness();
        $row = $this->pending('src.test');

        $html = '<a href="https://client.test/page">a</a><a href="https://other.test/">b</a><a href="https://src.test/about">int</a>';
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('src.test', $html));

        $this->runBatch();

        // External edges recorded, seed row done + lease cleared.
        $targets = DB::table('link_edges')->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->where('link_edges.source', 'own_crawl')->pluck('t.name')->all();
        $this->assertContains('client.test', $targets);
        $this->assertContains('other.test', $targets);
        $this->assertSame('done', $row->fresh()->status);
        $this->assertNull($row->fresh()->lease_id);
        $this->assertNull($row->fresh()->leased_until);
        // Internal page queued as depth 1; batch self-replaced.
        $this->assertDatabaseHas('link_crawl_frontier', ['url' => 'https://src.test/about', 'depth' => 1, 'status' => 'pending']);
        Queue::assertPushed(LinkCrawlBatchJob::class, 1);
    }

    public function test_batch_does_not_self_replace_when_frontier_drained(): void
    {
        Queue::fake();
        $this->bindNoOpPolitness();
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('x.test', '<a href="https://y.test/">y</a>'));

        // No pending rows → claim empty → no work, no replacement.
        $this->runBatch();

        Queue::assertNothingPushed();
    }

    public function test_cloudflare_block_marks_blocked_clears_lease_no_edges(): void
    {
        Queue::fake();
        $this->bindNoOpPolitness();
        $row = $this->pending('waf.test');

        $f = Mockery::mock(CrawlFetcher::class);
        $f->shouldReceive('fetch')->with('https://waf.test/robots.txt', Mockery::any(), Mockery::any())
            ->andReturn(['ok' => true, 'status' => 200, 'body' => '', 'headers' => []]);
        $f->shouldReceive('fetch')->andReturn([
            'ok' => true, 'status' => 403, 'body' => '<a href="https://cloudflare.com/">cf</a> Are you a robot?',
            'headers' => ['cf-mitigated' => 'challenge', 'server' => 'cloudflare'],
        ]);
        $this->app->instance(CrawlFetcher::class, $f);

        $this->runBatch();

        $this->assertSame('blocked', $row->fresh()->status);
        $this->assertNull($row->fresh()->lease_id);
        $this->assertSame(0, DB::table('link_edges')->count());
    }

    public function test_disabled_flag_is_a_no_op(): void
    {
        Queue::fake();
        config(['crawler.link_crawl.enabled' => false]);
        $row = $this->pending('s.test');

        (new LinkCrawlBatchJob())->handle(
            Mockery::mock(CrawlFetcher::class), Mockery::mock(DomainRateLimiter::class), Mockery::mock(ProxyPool::class),
            app(\App\Services\LinkGraph\EdgeRecorder::class), app(\App\Services\LinkGraph\LinkCrawlBudget::class),
            app(\App\Support\Crawler\BlockDetector::class), app(FrontierClaimer::class),
        );

        $this->assertSame('pending', $row->fresh()->status);
        Queue::assertNothingPushed();
    }

    // ── Dispatcher ──────────────────────────────────────────────

    public function test_dispatcher_tops_up_to_target(): void
    {
        Queue::fake();
        config(['crawler.link_crawl.target_in_flight' => 5]);
        $this->pending('work.test');

        $this->artisan('ebq:link-crawl-dispatch')->assertSuccessful();

        // Queue::fake() reports size 0, so it tops up the full target.
        Queue::assertPushed(LinkCrawlBatchJob::class, 5);
    }

    public function test_dispatcher_noop_when_no_work(): void
    {
        Queue::fake();
        $this->artisan('ebq:link-crawl-dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // ── Recrawl requeue (keeps the frontier perpetually fed) ────

    public function test_requeue_recrawls_returns_due_done_rows_to_pending(): void
    {
        $due = $this->pending('due.test');
        $due->update(['status' => 'done', 'next_at' => now()->subDay()]);
        $future = $this->pending('future.test');
        $future->update(['status' => 'done', 'next_at' => now()->addDays(10)]);
        $failed = $this->pending('failed.test');
        $failed->update(['status' => 'failed', 'next_at' => now()->subDay()]); // terminal — untouched

        $moved = app(FrontierClaimer::class)->requeueRecrawls(100);

        $this->assertSame(1, $moved);
        $this->assertSame('pending', $due->fresh()->status);
        $this->assertSame('done', $future->fresh()->status);   // window not elapsed
        $this->assertSame('failed', $failed->fresh()->status); // never resurrected
    }

    public function test_dispatcher_requeues_recrawls_then_tops_up(): void
    {
        Queue::fake();
        config(['crawler.link_crawl.target_in_flight' => 3]);
        // No pending work, only a recrawl-due done row → dispatcher must revive it
        // (else hasDueWork() is false and nothing is pushed).
        $row = $this->pending('recrawl.test');
        $row->update(['status' => 'done', 'next_at' => now()->subDay()]);

        $this->artisan('ebq:link-crawl-dispatch')->assertSuccessful();

        $this->assertSame('pending', $row->fresh()->status);
        Queue::assertPushed(LinkCrawlBatchJob::class, 3);
    }

    // ── Organic expansion (self-growing frontier) ───────────────

    public function test_batch_expands_frontier_with_new_external_domains(): void
    {
        Queue::fake();
        $this->bindNoOpPolitness();
        config(['crawler.link_crawl.expand_enabled' => true, 'crawler.link_crawl.expand_per_page' => 3]);
        Cache::forget('linkcrawl:frontier_count');
        $this->pending('src.test');

        // Two distinct external registrable domains + one already-queued one.
        // `known.test` is already `done` (future recrawl) so it isn't claimed by
        // this batch — it exists purely to prove expansion skips known domains.
        $this->pending('known.test')->update(['status' => 'done', 'next_at' => now()->addDays(10)]);
        $html = '<a href="https://fresh-one.test/x">1</a><a href="https://fresh-two.test/">2</a>'
            .'<a href="https://known.test/y">dup</a><a href="https://src.test/about">int</a>';
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('src.test', $html));

        $this->runBatch();

        // New external domains queued as depth-0 homepages…
        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'fresh-one.test', 'url' => 'https://fresh-one.test/', 'depth' => 0, 'status' => 'pending']);
        $this->assertDatabaseHas('link_crawl_frontier', ['host' => 'fresh-two.test', 'depth' => 0]);
        // …the already-present domain stays a single row (no duplicate).
        $this->assertSame(1, LinkCrawlFrontier::where('host', 'known.test')->count());
    }

    public function test_expansion_respects_frontier_ceiling(): void
    {
        Queue::fake();
        $this->bindNoOpPolitness();
        config(['crawler.link_crawl.expand_enabled' => true, 'crawler.link_crawl.max_frontier' => 1]);
        Cache::forget('linkcrawl:frontier_count');
        $this->pending('src.test'); // frontier already at/above the ceiling of 1

        $html = '<a href="https://newdomain.test/x">n</a>';
        $this->app->instance(CrawlFetcher::class, $this->fakeFetcher('src.test', $html));

        $this->runBatch();

        $this->assertSame(0, LinkCrawlFrontier::where('host', 'newdomain.test')->count());
    }
}
