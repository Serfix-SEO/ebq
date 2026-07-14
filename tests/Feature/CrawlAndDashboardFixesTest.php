<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeSiteJob;
use App\Jobs\CrawlPassJob;
use App\Livewire\Competitive\CompetitorDiscovery;
use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CrawlAndDashboardFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // CrawlValueRank::order() uses MariaDB's CHAR_LENGTH(); register a shim so the
        // value-ordering SQL runs under the sqlite :memory: test DB (prod is MariaDB).
        $conn = \Illuminate\Support\Facades\DB::connection();
        if ($conn->getDriverName() === 'sqlite') {
            $conn->getPdo()->sqliteCreateFunction('CHAR_LENGTH', fn ($s) => mb_strlen((string) $s), 1);
        }
    }

    private function site(string $domain): array
    {
        $w = Website::factory()->create(['user_id' => User::factory()->create()->id, 'domain' => $domain]);
        $w->refresh();

        return [$w, (string) $w->crawl_site_id];
    }

    private function duePage(string $cs, string $url): WebsitePage
    {
        return WebsitePage::create([
            'crawl_site_id' => $cs, 'url' => $url, 'url_hash' => WebsitePage::hashUrl($url),
            'next_crawl_at' => now()->subMinute(),
        ]);
    }

    // ---- Bug #2: a failed/aborted recrawl must not wipe a prior good crawl ----

    public function test_summary_keeps_completed_health_when_a_later_recrawl_aborts(): void
    {
        [$w, $cs] = $this->site('shared.com');
        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'completed',
            'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2), 'health_score' => 82]);
        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'scheduled', 'status' => 'aborted',
            'started_at' => now(), 'finished_at' => now(), 'blocked_reason' => 'blocked']);

        $s = app(CrawlReportService::class)->summary($w->id);

        $this->assertSame(82, $s['health_score'], 'good score preserved over the aborted recrawl');
        $this->assertFalse($s['blocked'], 'must not flag blocked while good data exists');
        $this->assertSame('aborted', $s['run_status'], 'current run status still surfaced for the live UI');
    }

    public function test_summary_blocks_only_when_no_completed_crawl_exists(): void
    {
        [$w, $cs] = $this->site('blocked.com');
        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'aborted',
            'started_at' => now(), 'finished_at' => now(), 'blocked_reason' => 'blocked']);

        $s = app(CrawlReportService::class)->summary($w->id);

        $this->assertTrue($s['blocked']);
        $this->assertNull($s['health_score']);
    }

    // ---- Crawl fairness: a pass crawls at most pages_per_pass ----

    public function test_pass_caps_pages_per_pass(): void
    {
        config(['crawler.pages_per_pass' => 2]);
        Bus::fake();
        [, $cs] = $this->site('big.com');
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running',
            'started_at' => now(), 'pages_seen' => 0]);
        for ($i = 0; $i < 5; $i++) {
            $this->duePage($cs, "https://big.com/p{$i}");
        }

        (new CrawlPassJob($run->id, $cs, 1, false))->handle();

        $run->refresh();
        $this->assertSame(2, (int) $run->pages_seen, 'a single pass enqueues at most pages_per_pass, not the whole frontier');
        Bus::assertBatched(fn ($batch) => count($batch->jobs) >= 1);
    }

    // ---- Watchdog: recover wedged runs ----

    public function test_supervisor_resumes_a_wedged_run_with_due_pages(): void
    {
        Queue::fake();
        [, $cs] = $this->site('wedged.com');
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running',
            'started_at' => now()->subHour(), 'pages_seen' => 10]);
        CrawlRun::where('id', $run->id)->update(['updated_at' => now()->subMinutes(30)]); // stale
        $this->duePage($cs, 'https://wedged.com/more');

        $this->artisan('ebq:crawl-supervisor')->assertSuccessful();

        Queue::assertPushed(CrawlPassJob::class);
        Queue::assertNotPushed(AnalyzeSiteJob::class);
    }

    public function test_supervisor_finalizes_a_wedged_run_with_no_due_pages(): void
    {
        Queue::fake();
        [, $cs] = $this->site('exhausted.com');
        $run = CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running',
            'started_at' => now()->subHour(), 'pages_seen' => 10]);
        CrawlRun::where('id', $run->id)->update(['updated_at' => now()->subMinutes(30)]);
        // no due pages

        $this->artisan('ebq:crawl-supervisor')->assertSuccessful();

        Queue::assertPushed(AnalyzeSiteJob::class);
        Queue::assertNotPushed(CrawlPassJob::class);
    }

    public function test_supervisor_ignores_a_recently_active_run(): void
    {
        Queue::fake();
        [, $cs] = $this->site('active.com');
        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running',
            'started_at' => now(), 'pages_seen' => 5]); // fresh updated_at = now
        $this->duePage($cs, 'https://active.com/more');

        $this->artisan('ebq:crawl-supervisor')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ---- Banner display: total = live inventory, not the per-pass run counter ----

    public function test_crawl_banner_total_reflects_inventory_not_per_pass_counter(): void
    {
        $owner = User::factory()->create();
        $w = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'banner.com']);
        $w->refresh();
        $cs = (string) $w->crawl_site_id;
        // Per-pass counter (pages_seen) sits at 1000 after one fairness-capped pass...
        CrawlRun::create(['crawl_site_id' => $cs, 'trigger' => 'manual', 'status' => 'running',
            'started_at' => now()->subMinute(), 'pages_seen' => 1000, 'pages_fetched' => 1000]);
        // ...but the live inventory is 25 pages, 10 crawled this run.
        for ($i = 0; $i < 25; $i++) {
            WebsitePage::create([
                'crawl_site_id' => $cs, 'url' => "https://banner.com/p{$i}", 'url_hash' => WebsitePage::hashUrl("https://banner.com/p{$i}"),
                'last_crawled_at' => $i < 10 ? now() : null,
            ]);
        }

        $this->actingAs($owner);
        session(['current_website_id' => $w->id]);

        Livewire::test(\App\Livewire\CrawlBanner::class)
            ->assertSee('10 / 25 pages crawled') // inventory denominator, NOT pages_seen=1000
            ->assertDontSee('1,000 / 1,000');
    }

    // ---- Bug #1: IDOR — competitive components must gate on access ----

    public function test_competitor_discovery_rejects_an_inaccessible_session_website(): void
    {
        $owner = User::factory()->create();
        $w = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'owned.com']);

        $intruder = User::factory()->create();
        $this->actingAs($intruder);
        session(['current_website_id' => $w->id]); // stale/forged session pointing at a site they can't view

        Livewire::test(CompetitorDiscovery::class)
            ->call('discover')
            ->assertSet('errorMessage', 'Select a website first.');
    }

    // ---- Initial-crawl queued window: a brand-new site must read "crawling",
    //      not "all caught up", before the RUNNING run row exists. ----

    public function test_is_initial_crawl_covers_the_queued_window(): void
    {
        [$w] = $this->site('fresh.com');

        // Fresh crawl_site, no CrawlRun yet (chain still queued / syncing sitemaps).
        $this->assertTrue($w->isInitialCrawl(), 'queued first crawl reads as in-progress');

        // A completed crawl closes the window even if the crawl_site is young.
        CrawlRun::create(['crawl_site_id' => $w->crawl_site_id, 'trigger' => 'manual',
            'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        $this->assertFalse($w->fresh()->isInitialCrawl(), 'a completed crawl ends the initial state');
    }

    public function test_is_initial_crawl_expires_for_a_stale_never_started_crawl(): void
    {
        [$w, $cs] = $this->site('stuck.com');
        // No run ever appeared and the crawl_site is old → don't spin the banner forever.
        \App\Models\CrawlSite::where('id', $cs)->update(['created_at' => now()->subHours(7)]);

        $this->assertFalse($w->fresh()->isInitialCrawl());
    }

    public function test_crawl_banner_shows_getting_started_during_the_queued_window(): void
    {
        $owner = User::factory()->create();
        $w = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'queued.com']);
        $w->refresh();

        $this->actingAs($owner);
        session(['current_website_id' => $w->id]);

        // No CrawlRun exists yet — the banner must stand in rather than stay blank.
        Livewire::test(\App\Livewire\CrawlBanner::class)
            ->assertSee('We’re setting up your site')
            ->assertDontSee('pages crawled');
    }

    public function test_priority_action_queue_excludes_crawl_issues_during_the_queued_window(): void
    {
        $owner = User::factory()->create();
        $w = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'queued-paq.com']);
        $w->refresh();

        $this->actingAs($owner);
        session(['current_website_id' => $w->id]);

        // The component is #[Lazy]; without this only the placeholder renders.
        Livewire::withoutLazyLoading();

        // During the first crawl (queued), crawl-derived issues aren't final
        // yet, so the confident "You're all caught up" empty state must NOT
        // show — but the queue itself stays visible (it may still have real
        // GSC/rank-tracking-derived items unrelated to crawl state) and shows
        // an explicit "still crawling" state instead of a misleading empty one.
        Livewire::test(\App\Livewire\Dashboard\PriorityActionQueue::class)
            ->assertSee('Priority Action Queue')
            ->assertDontSee("You're all caught up")
            ->assertSee('Crawl in progress');
    }
}
