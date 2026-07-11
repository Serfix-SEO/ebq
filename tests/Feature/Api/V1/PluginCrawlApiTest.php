<?php

namespace Tests\Feature\Api\V1;

use App\Models\CrawlFinding;
use App\Models\CrawlRun;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginCrawlApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Factory users have no subscription → they resolve to the trial
        // plan row, which must exist for the hq feature flag to be true.
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function website(array $attrs = []): Website
    {
        $user = User::factory()->create();

        return Website::factory()->create(array_merge(['user_id' => $user->id], $attrs));
    }

    private function token(Website $website): string
    {
        return $website->createToken('test', ['read:insights'])->plainTextToken;
    }

    private function seedCrawl(Website $website, string $host = 'example.com'): void
    {
        CrawlRun::create(['crawl_site_id' => $website->crawl_site_id, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => now()->subMinutes(5), 'finished_at' => now(), 'health_score' => 82]);

        $page = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => "https://{$host}/no-title", 'url_hash' => WebsitePage::hashUrl("https://{$host}/no-title"), 'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now()]);
        $thin = WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => "https://{$host}/thin", 'url_hash' => WebsitePage::hashUrl("https://{$host}/thin"), 'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now()]);

        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $page->id, 'category' => 'onpage', 'type' => 'missing_title', 'severity' => 'high', 'impact' => 0, 'affected_url' => $page->url, 'affected_url_hash' => CrawlFinding::hashUrl($page->url), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        CrawlFinding::create(['crawl_site_id' => $website->crawl_site_id, 'page_id' => $thin->id, 'category' => 'onpage', 'type' => 'thin_content', 'severity' => 'medium', 'impact' => 0, 'affected_url' => $thin->url, 'affected_url_hash' => CrawlFinding::hashUrl($thin->url), 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_requires_token_and_ability(): void
    {
        $this->getJson('/api/v1/hq/site-audit/summary')->assertStatus(401);

        $website = $this->website();
        $wrong = $website->createToken('test', ['unrelated:ability'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$wrong)
            ->getJson('/api/v1/hq/site-audit/summary')
            ->assertStatus(403);
    }

    public function test_hq_flag_off_is_forbidden_on_every_route(): void
    {
        $website = $this->website(['feature_flags' => ['hq' => false]]);
        $token = $this->token($website);

        foreach ([
            '/api/v1/hq/site-audit/summary',
            '/api/v1/hq/site-audit/issues',
            '/api/v1/hq/site-audit/issues/onpage',
            '/api/v1/hq/site-audit/pages',
            '/api/v1/hq/site-audit/page?url=https://example.com/x',
            '/api/v1/hq/site-audit/links',
        ] as $path) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson($path)
                ->assertStatus(403);
        }
    }

    public function test_summary_never_crawled_site_returns_shape_not_error(): void
    {
        // No GSC, no crawl rows at all — crawl-only rule: still 200.
        $website = $this->website();
        $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->getJson('/api/v1/hq/site-audit/summary')
            ->assertOk()
            ->assertJson(['has_crawl' => false])
            ->assertJsonStructure(['has_crawl', 'health_score', 'last_crawled_at', 'run_status', 'blocked', 'pages_total', 'findings' => ['critical', 'high', 'medium', 'low', 'total']]);
    }

    public function test_summary_and_issues_for_crawled_site_without_gsc(): void
    {
        $website = $this->website();
        $this->seedCrawl($website);
        $token = $this->token($website);

        $summary = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/summary')
            ->assertOk()
            ->assertJson(['has_crawl' => true, 'health_score' => 82])
            ->json();
        $this->assertSame(2, $summary['findings']['total']);

        $issues = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/issues')
            ->assertOk()
            ->json('groups');
        $this->assertCount(1, $issues);
        $this->assertSame('crawl_onpage', $issues[0]['key']);
        $this->assertSame(2, $issues[0]['count']);
        // Crawl-only site: impact degrades to 0, never an error.
        $this->assertSame(0.0, (float) $issues[0]['impact']);

        // Feature-flag envelope rides on every response (InjectFeatureFlags).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/summary')
            ->assertJsonStructure(['features', 'frozen', 'tier']);
    }

    public function test_issue_detail_paginates_filters_and_rejects_bogus_category(): void
    {
        $website = $this->website();
        $this->seedCrawl($website);
        $token = $this->token($website);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/issues/bogus_category')
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_category']);

        $detail = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/issues/onpage?type=missing_title')
            ->assertOk()
            ->json();
        $this->assertCount(1, $detail['findings']['data']);
        $this->assertSame('missing_title', $detail['findings']['data'][0]['type']);
        $this->assertFalse($detail['findings']['has_more']);
        $this->assertNotSame('', $detail['guidance']['fix']);

        // per_page is capped at 50.
        $capped = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/issues/onpage?per_page=500')
            ->assertOk()
            ->json('findings.per_page');
        $this->assertSame(50, $capped);
    }

    public function test_tenancy_website_a_token_never_sees_website_b_findings(): void
    {
        $a = $this->website(['domain' => 'a.example.com']);
        $b = $this->website(['domain' => 'b.example.com']);
        $this->seedCrawl($a, 'a.example.com');
        $this->seedCrawl($b, 'b.example.com');

        $urls = collect($this->withHeader('Authorization', 'Bearer '.$this->token($a))
            ->getJson('/api/v1/hq/site-audit/issues/onpage')
            ->assertOk()
            ->json('findings.data'))->pluck('affected_url');

        $this->assertNotEmpty($urls);
        $this->assertTrue($urls->every(fn ($u) => str_contains($u, 'a.example.com')));
    }

    public function test_page_and_links_endpoints(): void
    {
        $website = $this->website();
        $this->seedCrawl($website);
        $token = $this->token($website);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/page?url='.urlencode('https://example.com/no-title'))
            ->assertOk()
            ->assertJsonPath('page.url', 'https://example.com/no-title')
            ->assertJsonPath('findings.0.type', 'missing_title');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/page?url='.urlencode('https://example.com/never-crawled'))
            ->assertStatus(404)
            ->assertJson(['error' => 'not_crawled']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/page')
            ->assertStatus(422);

        // No url → seed suggestions; with url → full structure.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/links')
            ->assertOk()
            ->assertJsonStructure(['suggestions']);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/hq/site-audit/links?url='.urlencode('https://example.com/thin'))
            ->assertOk()
            ->assertJsonStructure(['structure' => ['page', 'inbound', 'outbound']]);
    }

    public function test_pages_inventory_with_filter(): void
    {
        $website = $this->website();
        $this->seedCrawl($website);

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token($website))
            ->getJson('/api/v1/hq/site-audit/pages?filter=all&per_page=1')
            ->assertOk()
            ->json();

        $this->assertSame('all', $resp['filter']);
        $this->assertCount(1, $resp['pages']['data']);
        $this->assertTrue($resp['pages']['has_more']);
        $this->assertArrayHasKey('url', $resp['pages']['data'][0]);
        $this->assertArrayHasKey('inbound_links', $resp['pages']['data'][0]);
        // Each seeded page carries exactly one open finding.
        $this->assertSame(1, $resp['pages']['data'][0]['open_issues']);
    }
}
