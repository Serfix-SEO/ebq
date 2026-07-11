<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PriorityActionQueue;
use App\Livewire\Dashboard\SiteHealthStats;
use App\Livewire\LinkStructure\LinkStructurePanel;
use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LinkStructureTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a tiny crawl: home → about, home → dead(404); about is the focus page. */
    private function seedCrawl(Website $website): array
    {
        CrawlRun::create(['crawl_site_id' => $website->crawl_site_id, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now()->subMinutes(2), 'finished_at' => now(), 'pages_fetched' => 3, 'health_score' => 68]);

        $home = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/', 'url_hash' => WebsitePage::hashUrl('https://example.com/'), 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'internal_link_count' => 2, 'click_depth' => 0, 'last_crawled_at' => now()]);
        $about = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/about', 'url_hash' => WebsitePage::hashUrl('https://example.com/about'), 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 1, 'internal_link_count' => 0, 'click_depth' => 1, 'page_score' => 80, 'last_crawled_at' => now()]);
        $dead = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/dead', 'url_hash' => WebsitePage::hashUrl('https://example.com/dead'), 'http_status' => 404, 'is_indexable' => false, 'inbound_link_count' => 1, 'last_crawled_at' => now()]);
        // A genuine (non-homepage) orphan: indexable, crawled, zero inbound links.
        $orphan = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/lonely', 'url_hash' => WebsitePage::hashUrl('https://example.com/lonely'), 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'click_depth' => null, 'last_crawled_at' => now()]);

        WebsiteInternalLink::create(['crawl_site_id' => $website->crawl_site_id, 'from_page_id' => $home->id, 'to_page_id' => $about->id, 'anchor_text' => 'About us', 'status' => 'discovered']);
        WebsiteInternalLink::create(['crawl_site_id' => $website->crawl_site_id, 'from_page_id' => $home->id, 'to_page_id' => $dead->id, 'anchor_text' => 'Dead', 'status' => 'discovered']);

        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $dead->id, 'category' => 'broken_link', 'type' => 'broken_internal', 'severity' => 'critical', 'impact' => 8, 'affected_url' => 'https://example.com/dead', 'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/dead'), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $orphan->id, 'category' => 'internal_links', 'type' => 'orphan_page', 'severity' => 'high', 'impact' => 0, 'affected_url' => 'https://example.com/lonely', 'affected_url_hash' => CrawlFinding::hashUrl('https://example.com/lonely'), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        return compact('home', 'about', 'dead', 'orphan');
    }

    public function test_link_structure_shows_inbound_and_outbound_for_a_page(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        // Focus the homepage: it links OUT to /about and /dead.
        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/')
            ->call('analyze')
            ->assertSee('Path from homepage')            // the depth tree
            ->assertSee('Links from this page')
            ->assertSee('https://example.com/about')
            ->assertSee('https://example.com/dead');   // broken outbound flagged
    }

    public function test_link_structure_explains_a_broken_page_and_its_referrers(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website); // /dead is a 404 linked from the homepage
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/dead')
            ->call('analyze')
            ->assertSee('returns 404')
            ->assertSee('Discovered via')
            ->assertSee('linked from 1 internal page')
            ->assertSee('https://example.com/'); // the referring page is listed
    }

    public function test_page_health_shows_the_open_finding_with_fix_guidance(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website); // /dead carries a broken_internal finding
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/dead')
            ->set('issueType', 'broken_internal')
            ->call('analyze')
            ->assertSee('Page Health')
            ->assertSee('Broken internal')
            ->assertSee("You're here")
            ->assertSee('Update or remove the link'); // start of the broken_internal guidance text
    }

    public function test_page_health_shows_clean_state_when_no_open_findings(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website); // /about has zero findings
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/about')
            ->call('analyze')
            ->assertSee('No open issues for this page');
    }

    public function test_link_structure_flags_a_genuine_orphan(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/lonely')
            ->call('analyze')
            ->assertSee('orphan');
    }

    public function test_homepage_is_not_labelled_an_orphan(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        // Homepage has zero inbound links but must NOT read as an orphan.
        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/')
            ->call('analyze')
            ->assertSee('This is the homepage')
            ->assertDontSee('is an orphan');
    }

    public function test_path_tree_shows_click_path_from_homepage(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website); // home → about
        session(['current_website_id' => $website->id]);

        // /about is one click from home → path = home → /about.
        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/about')
            ->call('analyze')
            ->assertSee('Path from homepage')
            ->assertSee('/about')
            ->assertSee('this page');
    }

    public function test_unknown_url_reports_not_found(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(LinkStructurePanel::class)
            ->set('pageUrl', 'https://example.com/nope')
            ->call('analyze')
            ->assertSee("hasn't been crawled");
    }

    public function test_dashboard_site_health_widget_shows_stats(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(SiteHealthStats::class)
            ->set('websiteId', $website->id)
            ->assertSee('Site Health')
            ->assertSee('Health score')
            ->assertSee('68');
    }

    public function test_action_queue_crawl_group_drills_down_to_urls(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(PriorityActionQueue::class)
            ->set('websiteId', $website->id)
            ->call('open', 'crawl_broken_link')
            ->assertSet('openIssue', 'crawl_broken_link')
            ->assertSee('/dead');
    }

    public function test_crawl_issues_are_grouped_in_the_action_queue_with_counts(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedCrawl($website);

        $groups = collect(app(\App\Services\ActionQueueService::class)->groupedActions($website->id))
            ->keyBy('key');

        // Grouped like cannibalization/striking-distance: named group + count + severity.
        $this->assertTrue($groups->has('crawl_broken_link'));
        $this->assertSame('Broken links', $groups['crawl_broken_link']['title']);
        $this->assertSame(1, $groups['crawl_broken_link']['count']);
        $this->assertSame('critical', $groups['crawl_broken_link']['severity']);
        $this->assertSame('Internal-link issues', $groups['crawl_internal_links']['title']);

        // The rendered per-type pills deep-link to that type's URL list
        // (/issues/{key}?type=…) instead of being inert text (2026-07-10).
        session(['current_website_id' => $website->id]);
        $type = $groups['crawl_broken_link']['types'][0]['type'] ?? null;
        $this->assertNotNull($type);
        Livewire::actingAs($user)
            ->test(PriorityActionQueue::class)
            ->set('websiteId', $website->id)
            ->assertSeeHtml('/issues/crawl_broken_link?type='.$type);
    }

    public function test_completed_crawl_flushes_the_action_queue_cache(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $before = \App\Services\ReportCache::version($website->id);

        // AnalyzeSiteJob flushes on completion — simulate the call it makes.
        \App\Services\ReportCache::flushWebsite($website->id);

        $this->assertGreaterThan($before, \App\Services\ReportCache::version($website->id));
    }
}
