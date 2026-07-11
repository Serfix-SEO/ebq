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

        // The suggester matches on stored content_terms (TF-IDF significant
        // terms), not raw body_text. Filler pages widen the DF denominator so
        // the shared topic term ("espresso", in 2 of 6 bodies) survives the
        // 40%-of-site boilerplate cut.
        foreach (range(1, 4) as $i) {
            WebsitePage::create([
                'crawl_site_id' => $website->crawl_site_id, 'url' => "https://example.com/filler-{$i}", 'url_hash' => WebsitePage::hashUrl("https://example.com/filler-{$i}"),
                'title' => "Filler {$i}", 'body_text' => "entirely unrelated filler topic {$i} about gardening",
                'content_terms' => json_encode(['t' => ["gardening{$i}" => 3]]),
                'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 1, 'last_crawled_at' => now(), 'word_count' => 100,
            ]);
        }

        // Authority page about "espresso machines" with inbound links.
        $authority = WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/coffee', 'url_hash' => WebsitePage::hashUrl('https://example.com/coffee'),
            'title' => 'Coffee Brewing Guide', 'body_text' => 'everything about espresso machines and grinders for coffee lovers',
            'content_terms' => json_encode(['t' => ['espresso' => 4, 'machines' => 4, 'grinders' => 2]]),
            'http_status' => 200, 'is_indexable' => true, 'inbound_link_count' => 5, 'last_crawled_at' => now(), 'word_count' => 500,
        ]);
        // Orphan page about espresso machines — no inbound links.
        $orphan = WebsitePage::create([
            'crawl_site_id' => $website->crawl_site_id, 'url' => 'https://example.com/espresso', 'url_hash' => WebsitePage::hashUrl('https://example.com/espresso'),
            'title' => 'Espresso Machines Reviewed', 'body_text' => 'reviews of espresso machines',
            'content_terms' => json_encode(['t' => ['espresso' => 3, 'machines' => 3, 'reviews' => 2]]),
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
