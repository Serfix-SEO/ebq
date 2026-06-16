<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInternalLink;
use App\Models\WebsitePage;
use App\Services\Crawler\InternalLinkSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalLinkSuggesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_links_to_orphan_from_related_authority_page(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);

        // Authority page about "espresso machines" with inbound links.
        $authority = WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/coffee', 'url_hash' => WebsitePage::hashUrl('https://example.com/coffee'),
            'title' => 'Coffee Brewing Guide', 'body_text' => 'everything about espresso machines and grinders for coffee lovers',
            'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 5, 'last_crawled_at' => now(), 'word_count' => 500,
        ]);
        // Orphan page about espresso machines — no inbound links.
        $orphan = WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/espresso', 'url_hash' => WebsitePage::hashUrl('https://example.com/espresso'),
            'title' => 'Espresso Machines Reviewed', 'body_text' => 'reviews of espresso machines',
            'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'last_crawled_at' => now(), 'word_count' => 400,
        ]);

        $count = app(InternalLinkSuggester::class)->suggest($website->crawl_site_id);

        $this->assertGreaterThan(0, $count);
        $this->assertDatabaseHas('website_internal_links', [
            'crawl_site_id' => $website->crawl_site_id,
            'from_page_id' => $authority->id,
            'to_page_id' => $orphan->id,
            'status' => WebsiteInternalLink::STATUS_SUGGESTED,
        ]);
    }

    public function test_suggestions_are_idempotent(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/a', 'url_hash' => WebsitePage::hashUrl('https://example.com/a'), 'title' => 'Espresso Machines Guide', 'body_text' => 'espresso machines', 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 4, 'last_crawled_at' => now()]);
        WebsitePage::create(['crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/b', 'url_hash' => WebsitePage::hashUrl('https://example.com/b'), 'title' => 'Espresso Machines Reviews', 'body_text' => 'espresso machines reviews', 'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 0, 'last_crawled_at' => now()]);

        $suggester = app(InternalLinkSuggester::class);
        $suggester->suggest($website->crawl_site_id);
        $first = WebsiteInternalLink::where('crawl_site_id', $website->crawl_site_id)->where('status', 'suggested')->count();
        $suggester->suggest($website->crawl_site_id);
        $second = WebsiteInternalLink::where('crawl_site_id', $website->crawl_site_id)->where('status', 'suggested')->count();

        $this->assertSame($first, $second, 'rerunning should not duplicate suggestions');
    }
}
