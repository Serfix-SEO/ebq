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
                    'site_type' => 'brand',
                    'audience' => 'Design-conscious apartment dwellers.',
                ])]]],
                'usage' => ['total_tokens' => 100],
            ]),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('Sells handmade wooden furniture for small apartments.', $profile['description']);
        $this->assertSame(['Custom tables', 'Chairs'], $profile['sell']);
        $this->assertSame(['Furniture repair'], $profile['dont_sell']);
        $this->assertSame('brand', $profile['site_type']);
        $this->assertSame('Design-conscious apartment dwellers.', $profile['audience']);

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
        $this->assertNull($profile['site_type']);
        $this->assertNull($profile['audience']);
    }

    public function test_invalid_site_type_from_the_llm_becomes_null(): void
    {
        // A hallucinated enum value must degrade to "unclassified" (type-blind
        // pipeline), never leak into the plan.
        config(['services.mistral.key' => 'test-key']);
        $this->stubGuard(true);
        $website = Website::factory()->for(User::factory())->create();

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'description' => 'A site about things.',
                    'sell' => [],
                    'dont_sell' => [],
                    'site_type' => 'megacorp_hybrid',
                    'audience' => 'Everyone.',
                ])]]],
                'usage' => ['total_tokens' => 50],
            ]),
            '*' => Http::response('<html><head><title>Things</title><meta name="description" content="All about things."></head></html>'),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertNull($profile['site_type']);
        $this->assertSame('Everyone.', $profile['audience']);
    }

    public function test_classify_stored_profile_backfills_from_plan_text_without_fetches(): void
    {
        config(['services.mistral.key' => 'test-key']);
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = \App\Models\ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => \App\Models\ContentPlan::STATUS_ACTIVE,
            'business_description' => 'Premium home and office cleaning services in Dubai.',
            'offerings' => ['sell' => ['Deep cleaning', 'Office cleaning'], 'dont_sell' => []],
        ]);

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'site_type' => 'local_service',
                    'audience' => 'Households and offices in Dubai.',
                ])]]],
                'usage' => ['total_tokens' => 40],
            ]),
        ]);

        $result = app(SiteProfileExtractor::class)->classifyStoredProfile($plan);

        $this->assertSame('local_service', $result['site_type']);
        $this->assertSame('Households and offices in Dubai.', $result['audience']);
        // Only the LLM endpoint may be hit — no homepage fetches on backfill.
        Http::assertSentCount(1);
    }

    public function test_classify_stored_profile_returns_null_for_profile_less_stub(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = \App\Models\ContentPlan::query()->create([
            'website_id' => $website->id,
            'status' => \App\Models\ContentPlan::STATUS_DRAFT,
        ]);

        $this->assertNull(app(SiteProfileExtractor::class)->classifyStoredProfile($plan));
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

    // ── 2026-07-22 (kayali.com/en-ae): entered-path preference, redirect
    // following, browser-UA retry ────────────────────────────────────────

    public function test_live_fallback_prefers_the_url_the_visitor_typed(): void
    {
        $this->stubGuard(true);
        config(['services.mistral.key' => null]);
        $website = Website::factory()->for(User::factory())->create(['normalized_domain' => 'kayali.test', 'domain' => 'kayali.test']);
        \Illuminate\Support\Facades\Cache::put('content:entered-url:'.$website->id, 'https://kayali.test/en-ae', now()->addDay());

        Http::fake([
            'kayali.test/en-ae' => Http::response(
                '<html><head><title>Kayali Fragrances</title>'
                .'<meta name="description" content="Luxury perfumes layered your way."></head></html>'
            ),
            // The bare root would 302 — it must not even be needed.
            'kayali.test' => Http::response('', 302, ['Location' => 'https://kayali.test/en-ae']),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('Luxury perfumes layered your way.', $profile['description']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/en-ae'));
    }

    public function test_live_fallback_follows_redirects_with_per_hop_guarding(): void
    {
        $this->stubGuard(true);
        config(['services.mistral.key' => null]);
        $website = Website::factory()->for(User::factory())->create(['normalized_domain' => 'redir.test', 'domain' => 'redir.test']);

        Http::fake([
            'redir.test/en-ae' => Http::response(
                '<html><head><title>Redir Landed</title>'
                .'<meta name="description" content="Content lives behind the region redirect."></head></html>'
            ),
            'redir.test' => Http::response('', 302, ['Location' => '/en-ae']),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('Content lives behind the region redirect.', $profile['description']);
    }

    public function test_live_fallback_retries_with_a_browser_user_agent_when_the_bot_is_blocked(): void
    {
        $this->stubGuard(true);
        config(['services.mistral.key' => null]);
        $website = Website::factory()->for(User::factory())->create(['normalized_domain' => 'hostile.test', 'domain' => 'hostile.test']);

        Http::fake(function ($request) {
            $ua = (string) $request->header('User-Agent')[0];

            return str_contains($ua, 'SerfixBot')
                ? Http::response('Access denied', 403)
                : Http::response(
                    '<html><head><title>Hostile Shop</title>'
                    .'<meta name="description" content="We only serve browsers."></head></html>'
                );
        });

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('We only serve browsers.', $profile['description']);
    }
}
