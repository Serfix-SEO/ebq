<?php

namespace Tests\Feature;

use App\Livewire\SiteIssues;
use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteIssuesTest extends TestCase
{
    use RefreshDatabase;

    private function seedFindings(Website $website): void
    {
        CrawlRun::create(['crawl_site_id' => $website->crawl_site_id, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now()->subMinutes(2), 'finished_at' => now()]);

        $missingTitle = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/no-title', 'url_hash' => WebsitePage::hashUrl('https://example.com/no-title'), 'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now()]);
        $thin = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/thin', 'url_hash' => WebsitePage::hashUrl('https://example.com/thin'), 'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now()]);

        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $missingTitle->id, 'category' => 'onpage', 'type' => 'missing_title', 'severity' => 'high', 'impact' => 0, 'affected_url' => $missingTitle->url, 'affected_url_hash' => CrawlFinding::hashUrl($missingTitle->url), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $thin->id, 'category' => 'onpage', 'type' => 'thin_content', 'severity' => 'medium', 'impact' => 0, 'affected_url' => $thin->url, 'affected_url_hash' => CrawlFinding::hashUrl($thin->url), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_default_view_groups_findings_by_type_not_a_flat_list(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedFindings($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(SiteIssues::class, ['issueKey' => 'crawl_onpage'])
            ->assertSet('type', '')
            ->assertSee('Missing title')
            ->assertSee('Thin content')
            // Grouped view shows type labels + counts, not the raw affected URLs.
            ->assertDontSee('https://example.com/no-title');
    }

    public function test_selecting_a_type_drills_into_its_affected_urls(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $this->seedFindings($website);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(SiteIssues::class, ['issueKey' => 'crawl_onpage'])
            ->call('selectType', 'missing_title')
            ->assertSet('type', 'missing_title')
            ->assertSee('/no-title')
            ->assertDontSee('/thin')
            ->assertSee('Back to all issue types');
    }
}
