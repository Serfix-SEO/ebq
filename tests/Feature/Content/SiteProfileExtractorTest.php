<?php

namespace Tests\Feature\Content;

use App\Models\User;
use App\Models\Website;
use App\Services\Content\SiteProfileExtractor;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteProfileExtractorTest extends TestCase
{
    use RefreshDatabase;

    /** Stub the SSRF guard green/blocked so the live-fetch fallback is deterministic. */
    private function stubGuard(bool $ok): void
    {
        $this->instance(SafeHttpGuard::class, new class($ok) extends SafeHttpGuard
        {
            public function __construct(private bool $ok)
            {
            }

            public function check(string $url): array
            {
                return ['ok' => $this->ok];
            }
        });
    }

    public function test_extracts_profile_from_crawl_pages(): void
    {
        config(['services.mistral.key' => 'test-key']);
        $website = Website::factory()->for(User::factory())->create();

        // Minimal crawl inventory the extractor reads (factory may have
        // already linked a crawl_site — reuse it).
        $crawlSiteId = $website->crawl_site_id;
        if (! $crawlSiteId) {
            $crawlSiteId = \Illuminate\Support\Str::ulid()->toBase32();
            \Illuminate\Support\Facades\DB::table('crawl_sites')->insert([
                'id' => $crawlSiteId,
                'normalized_domain' => 'profile-test.example',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $website->forceFill(['crawl_site_id' => $crawlSiteId])->save();
        }
        \Illuminate\Support\Facades\DB::table('website_pages')->insert([
            'id' => \Illuminate\Support\Str::ulid()->toBase32(),
            'website_id' => $website->id,
            'crawl_site_id' => $crawlSiteId,
            'url' => 'https://'.$website->domain.'/',
            'url_hash' => sha1('https://'.$website->domain.'/'),
            'title' => 'Handmade Wooden Furniture',
            'meta_description' => 'Custom tables and chairs for small apartments.',
            'http_status' => 200,
            'inbound_link_count' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'description' => 'Sells handmade wooden furniture for small apartments.',
                    'sell' => ['Custom tables', 'Chairs'],
                    'dont_sell' => ['Furniture repair'],
                ])]]],
                'usage' => ['total_tokens' => 100],
            ]),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('Sells handmade wooden furniture for small apartments.', $profile['description']);
        $this->assertSame(['Custom tables', 'Chairs'], $profile['sell']);
        $this->assertSame(['Furniture repair'], $profile['dont_sell']);

        // Cached: second call must not re-hit the LLM.
        Http::fake(['api.mistral.ai/*' => Http::response([], 500)]);
        $again = app(SiteProfileExtractor::class)->extract($website);
        $this->assertSame($profile, $again);
    }

    public function test_fails_soft_without_crawl_data(): void
    {
        // No crawl AND homepage unreachable (guard blocks) → null.
        $this->stubGuard(false);
        $website = Website::factory()->for(User::factory())->create();

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertNull($profile['description']);
        $this->assertSame([], $profile['sell']);
    }

    public function test_live_homepage_fallback_seeds_description_without_llm(): void
    {
        // Fresh site (no crawl), no LLM key configured → the extractor fetches
        // the homepage and seeds a plain description from title/meta.
        $this->stubGuard(true);
        config(['services.mistral.key' => null]);
        $website = Website::factory()->for(User::factory())->create();

        Http::fake(['*' => Http::response(
            '<html><head><title>Acme Widgets</title>'
            .'<meta name="description" content="We build durable widgets for makers and small factories."></head>'
            .'<body><h1>Widgets that last</h1></body></html>'
        )]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('We build durable widgets for makers and small factories.', $profile['description']);
    }
}
