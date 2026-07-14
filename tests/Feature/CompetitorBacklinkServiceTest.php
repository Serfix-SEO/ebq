<?php

namespace Tests\Feature;

use App\Jobs\FetchCompetitorBacklinks;
use App\Models\CompetitorBacklink;
use App\Services\CompetitorBacklinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompetitorBacklinkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.keywords_everywhere.key' => 'test-ke-key',
            'services.keywords_everywhere.base_url' => 'https://api.keywordseverywhere.com',
            'services.keywords_everywhere.backlinks_endpoint' => '/v1/get_backlinks',
            // Suppressed by default in prod (2026-07-14) — enabled here so the
            // KE machinery keeps its coverage for when the switch is flipped
            // back on. Suppression itself is covered by KeBacklinkSuppressionTest.
            'services.keywords_everywhere.backlinks_enabled' => true,
            'services.competitor_backlinks.fresh_days' => 30,
            'services.competitor_backlinks.limit_per_competitor' => 50,
        ]);
    }

    public function test_extract_domain_normalizes_urls(): void
    {
        $this->assertSame('example.com', CompetitorBacklink::extractDomain('https://www.example.com/path?q=1'));
        $this->assertSame('example.com', CompetitorBacklink::extractDomain('http://example.com'));
        $this->assertSame('sub.example.com', CompetitorBacklink::extractDomain('https://sub.example.com/page'));
        $this->assertSame('example.com', CompetitorBacklink::extractDomain('example.com'));
        $this->assertSame('', CompetitorBacklink::extractDomain(''));
    }

    public function test_is_fresh_returns_false_when_no_data(): void
    {
        $this->assertFalse(app(CompetitorBacklinkService::class)->isFresh('example.com'));
    }

    public function test_is_fresh_returns_true_when_row_not_expired(): void
    {
        CompetitorBacklink::create([
            'competitor_domain' => 'example.com',
            'referring_page_url' => 'https://other.com/a',
            'referring_page_hash' => CompetitorBacklink::hashUrl('https://other.com/a'),
            'fetched_at' => Carbon::now()->subDays(5),
            'expires_at' => Carbon::now()->addDays(25),
        ]);

        $this->assertTrue(app(CompetitorBacklinkService::class)->isFresh('example.com'));
    }

    public function test_is_fresh_returns_false_when_row_expired(): void
    {
        CompetitorBacklink::create([
            'competitor_domain' => 'example.com',
            'referring_page_url' => 'https://other.com/a',
            'referring_page_hash' => CompetitorBacklink::hashUrl('https://other.com/a'),
            'fetched_at' => Carbon::now()->subDays(40),
            'expires_at' => Carbon::now()->subDays(10),
        ]);

        $this->assertFalse(app(CompetitorBacklinkService::class)->isFresh('example.com'));
    }

    public function test_queue_refresh_skips_fresh_domains_and_dispatches_stale_ones(): void
    {
        Queue::fake();

        CompetitorBacklink::create([
            'competitor_domain' => 'cached.com',
            'referring_page_url' => 'https://x.com/a',
            'referring_page_hash' => CompetitorBacklink::hashUrl('https://x.com/a'),
            'fetched_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(25),
        ]);

        app(CompetitorBacklinkService::class)->queueRefresh([
            'https://cached.com/page',
            'https://uncached.com/other',
        ]);

        Queue::assertPushed(FetchCompetitorBacklinks::class, function ($job) {
            return in_array('uncached.com', $job->domains, true)
                && ! in_array('cached.com', $job->domains, true);
        });
    }

    public function test_refresh_upserts_keywords_everywhere_response(): void
    {
        Http::fake([
            '*keywordseverywhere.com*' => Http::response([
                'data' => [
                    ['url_from' => 'https://news.com/article', 'domain_from' => 'news.com', 'anchor' => 'read more', 'domain_rating' => 72, 'dofollow' => true, 'first_seen' => '2025-06-01'],
                    ['url_from' => 'https://blog.io/post', 'domain_from' => 'blog.io', 'anchor' => 'see this', 'domain_rating' => 54, 'dofollow' => false, 'first_seen' => '2025-07-15'],
                ],
                'credits' => 99500,
            ], 200),
        ]);

        $written = app(CompetitorBacklinkService::class)->refresh('example.com');

        $this->assertSame(2, $written);
        $rows = CompetitorBacklink::query()->where('competitor_domain', 'example.com')->get();
        $this->assertCount(2, $rows);

        $top = $rows->firstWhere('domain_authority', 72);
        $this->assertNotNull($top);
        $this->assertSame('news.com', $top->referring_domain);
        $this->assertSame('read more', $top->anchor_text);
        $this->assertSame('dofollow', $top->backlink_type);
        $this->assertTrue($top->fetched_at->isToday());
    }

    public function test_refresh_parses_exact_keywords_everywhere_shape(): void
    {
        // The actual response shape from /v1/get_domain_backlinks.
        Http::fake([
            '*keywordseverywhere.com*' => Http::response([
                'data' => [[
                    'anchor_text' => 'Nickfinder',
                    'domain_source' => 'lifewire.com',
                    'domain_target' => 'nickfinder.com',
                    'url_source' => 'https://www.lifewire.com/how-to-rename-airpods-4691178',
                    'url_target' => 'https://nickfinder.com/AirPods',
                ]],
                'credits_consumed' => 1,
                'time_taken' => 4.9032,
            ], 200),
        ]);

        $written = app(CompetitorBacklinkService::class)->refresh('nickfinder.com');

        $this->assertSame(1, $written);
        $row = CompetitorBacklink::query()->where('competitor_domain', 'nickfinder.com')->firstOrFail();
        $this->assertSame('https://www.lifewire.com/how-to-rename-airpods-4691178', $row->referring_page_url);
        $this->assertSame('lifewire.com', $row->referring_domain);
        $this->assertSame('Nickfinder', $row->anchor_text);
        // KE's domain-backlinks endpoint doesn't currently return DA or follow-type.
        $this->assertNull($row->domain_authority);
        $this->assertNull($row->backlink_type);
    }

    public function test_refresh_accepts_alternate_field_names(): void
    {
        // Response uses `page_url`, `domain`, `anchor_text`, `dr`, `rel` — all
        // common alternate spellings the parser should handle.
        Http::fake([
            '*keywordseverywhere.com*' => Http::response([
                'data' => [
                    ['page_url' => 'https://ref.com/x', 'domain' => 'ref.com', 'anchor_text' => 'click here', 'dr' => 40, 'rel' => 'nofollow', 'discovered_at' => '2025-05-01'],
                ],
            ], 200),
        ]);

        app(CompetitorBacklinkService::class)->refresh('example.com');

        $row = CompetitorBacklink::query()->where('competitor_domain', 'example.com')->firstOrFail();
        $this->assertSame('ref.com', $row->referring_domain);
        $this->assertSame('click here', $row->anchor_text);
        $this->assertSame(40, $row->domain_authority);
        $this->assertSame('nofollow', $row->backlink_type);
    }

    public function test_refresh_prunes_rows_absent_from_latest_fetch(): void
    {
        CompetitorBacklink::create([
            'competitor_domain' => 'example.com',
            'referring_page_url' => 'https://old.com/gone',
            'referring_page_hash' => CompetitorBacklink::hashUrl('https://old.com/gone'),
            'fetched_at' => Carbon::now()->subDays(40),
            'expires_at' => Carbon::now()->subDays(10),
        ]);

        Http::fake([
            '*keywordseverywhere.com*' => Http::response([
                'data' => [
                    ['url_from' => 'https://news.com/article', 'domain_from' => 'news.com', 'anchor' => 'x', 'domain_rating' => 60, 'dofollow' => true],
                ],
            ], 200),
        ]);

        app(CompetitorBacklinkService::class)->refresh('example.com');

        $domains = CompetitorBacklink::query()->where('competitor_domain', 'example.com')->pluck('referring_domain')->all();
        $this->assertSame(['news.com'], $domains);
    }

    public function test_refresh_respects_limit_config(): void
    {
        config(['services.competitor_backlinks.limit_per_competitor' => 3]);

        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['url_from' => "https://s{$i}.com/p", 'domain_from' => "s{$i}.com", 'anchor' => "a{$i}", 'domain_rating' => 50 - $i, 'dofollow' => true];
        }

        Http::fake([
            '*keywordseverywhere.com*' => Http::response(['data' => $items], 200),
        ]);

        $written = app(CompetitorBacklinkService::class)->refresh('example.com');

        $this->assertSame(3, $written);
        $this->assertSame(3, CompetitorBacklink::query()->where('competitor_domain', 'example.com')->count());
    }

    public function test_refresh_is_a_noop_when_api_key_missing(): void
    {
        config(['services.keywords_everywhere.key' => '']);
        Http::fake();

        $written = app(CompetitorBacklinkService::class)->refresh('example.com');

        $this->assertSame(0, $written);
        Http::assertNothingSent();
    }
}
