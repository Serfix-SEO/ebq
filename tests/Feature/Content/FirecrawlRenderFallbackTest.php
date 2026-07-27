<?php

namespace Tests\Feature\Content;

use App\Models\User;
use App\Models\Website;
use App\Services\Content\SiteProfileExtractor;
use App\Services\Crawler\FirecrawlClient;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Crawler\RenderGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirecrawlRenderFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function stubGuard(bool $ok): void
    {
        $this->instance(SafeHttpGuard::class, new class($ok) extends SafeHttpGuard
        {
            public function __construct(private bool $ok) {}

            public function check(string $url): array
            {
                return ['ok' => $this->ok];
            }
        });
    }

    private function enableFirecrawl(): void
    {
        config([
            'services.firecrawl.enabled' => true,
            'services.firecrawl.url' => 'http://fc.test:3002',
            'services.firecrawl.key' => 'k',
            'services.firecrawl.timeout_s' => 20,
        ]);
    }

    public function test_render_gate_detects_cloudflare_challenge(): void
    {
        $this->assertTrue(RenderGate::isChallenge(403, ['cf-mitigated' => 'challenge'], '<html>whatever</html>'));
        $this->assertTrue(RenderGate::isChallenge(403, ['server' => 'cloudflare'], '<title>Just a moment...</title>'));
        $this->assertTrue(RenderGate::isChallenge(200, [], '<script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>'));
        // Real content / plain errors must NOT trigger a render.
        $this->assertFalse(RenderGate::isChallenge(200, ['server' => 'nginx'], '<html><body>Welcome to our shop</body></html>'));
        $this->assertFalse(RenderGate::isChallenge(404, ['server' => 'cloudflare'], '<title>Not found</title>'));
    }

    public function test_client_disabled_makes_no_http_call(): void
    {
        config(['services.firecrawl.enabled' => false]);
        Http::fake();
        $this->stubGuard(true);

        $this->assertNull(app(FirecrawlClient::class)->html('https://example.com'));
        Http::assertNothingSent();
    }

    public function test_client_guard_blocked_returns_null(): void
    {
        $this->enableFirecrawl();
        Http::fake();
        $this->stubGuard(false); // SSRF guard rejects the target

        $this->assertNull(app(FirecrawlClient::class)->html('http://169.254.169.254/'));
        Http::assertNothingSent();
    }

    public function test_client_retries_once_on_upstream_5xx(): void
    {
        $this->enableFirecrawl();
        $this->stubGuard(true);
        Http::fake([
            'http://fc.test:3002/*' => Http::sequence()
                ->push(['success' => true, 'data' => ['html' => '', 'metadata' => ['statusCode' => 520]]], 200)
                ->push(['success' => true, 'data' => ['html' => '<h1>rendered</h1>', 'metadata' => ['statusCode' => 200]]], 200),
        ]);

        $this->assertSame('<h1>rendered</h1>', app(FirecrawlClient::class)->html('https://x.test'));
    }

    public function test_wizard_falls_back_to_firecrawl_when_site_is_challenged(): void
    {
        // Fresh site, no crawl data, no LLM → the extractor hits the live homepage.
        // The site serves a Cloudflare "Just a moment" 403 (both UAs blocked), so
        // the render fallback pulls real content from Firecrawl.
        $this->stubGuard(true);
        config(['services.mistral.key' => null]);
        $this->enableFirecrawl();
        $website = Website::factory()->for(User::factory())->create(['domain' => 'cf-site.test', 'normalized_domain' => 'cf-site.test']);

        Http::fake([
            'http://fc.test:3002/*' => Http::response([
                'success' => true,
                'data' => [
                    'html' => '<html><head><title>GPS Marketing Agency</title>'
                        .'<meta name="description" content="Top digital marketing agency in Dubai."></head>'
                        .'<body><h1>Marketing that ranks</h1></body></html>',
                    'metadata' => ['statusCode' => 200],
                ],
            ]),
            '*' => Http::response('<html><head><title>Just a moment...</title></head><body>cf-mitigated</body></html>', 403),
        ]);

        $profile = app(SiteProfileExtractor::class)->extract($website);

        $this->assertSame('Top digital marketing agency in Dubai.', $profile['description']);
    }
}
