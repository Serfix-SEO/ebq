<?php

namespace Tests\Unit;

use App\Services\PageAuditService;
use App\Services\SerperSearchClient;
use App\Support\Audit\RecommendationEngine;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerperAuditBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Http::fake();
        Mockery::close();

        parent::tearDown();
    }

    public function test_page_audit_benchmark_includes_competitor_flesch_scores(): void
    {
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        Config::set('services.serper.key', 'test-serper-key');
        Config::set('services.serper.base_url', 'https://google.serper.dev');

        $html = '<!DOCTYPE html><html><head><title>Comp</title></head><body><p>'
            .str_repeat('The quick brown fox jumps over the lazy dog. ', 50)
            .'</p></body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, 'serper.dev/search')) {
                return Http::response([
                    'organic' => [
                        ['link' => 'https://alpha-comp.test/p1', 'title' => 'Alpha', 'position' => 1],
                        ['link' => 'https://beta-comp.test/p2', 'title' => 'Beta', 'position' => 2],
                        ['link' => 'https://gamma-comp.test/p3', 'title' => 'Gamma', 'position' => 3],
                    ],
                ], 200);
            }
            if (in_array($url, [
                'https://alpha-comp.test/p1',
                'https://beta-comp.test/p2',
                'https://gamma-comp.test/p3',
            ], true)) {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected URL: '.$url, 500);
        });

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $keywords = [
            'available' => true,
            'primary' => ['query' => 'sample keyword'],
        ];

        $bench = $method->invoke($svc, 'https://audited-site.test/article', $keywords, 55.0, 1446, 0, null, null, null, null, null);

        $this->assertIsArray($bench);
        $this->assertSame('sample keyword', $bench['keyword']);
        $this->assertSame('gsc_primary', $bench['keyword_source']);
        $this->assertSame('serper', $bench['source']);
        $this->assertSame(55.0, $bench['your_flesch']);
        $this->assertSame(1446, $bench['your_word_count']);
        $this->assertSame(0, $bench['your_image_count']);
        $this->assertCount(3, $bench['competitors']);
        foreach ($bench['competitors'] as $row) {
            $this->assertArrayHasKey('url', $row);
            $this->assertArrayHasKey('flesch', $row);
            $this->assertIsNumeric($row['flesch']);
            $this->assertArrayHasKey('image_count', $row);
        }
        $this->assertArrayHasKey('skipped_reason', $bench);
        $this->assertNull($bench['skipped_reason']);
        $this->assertArrayHasKey('your_serp', $bench);
        $this->assertFalse($bench['your_serp']['found']);
        $this->assertNull($bench['your_serp']['matched_listing_url'] ?? null);
        $this->assertNull($bench['your_serp']['matched_listing_snippet'] ?? null);
        $this->assertNull($bench['your_serp']['matched_listing_display'] ?? null);
        $this->assertSame(3, $bench['your_serp']['organic_sample_size']);
        $this->assertNotEmpty($bench['gap_table']['rows']);
        $gapKeys = array_column($bench['gap_table']['rows'], 'key');
        $this->assertContains('word_count', $gapKeys);
        $this->assertContains('flesch', $gapKeys);
        $this->assertContains('images', $gapKeys);
        $this->assertArrayHasKey('serp_locale', $bench);
        $this->assertSame([], $bench['serp_locale']);
    }

    public function test_your_serp_detected_when_audited_url_appears_in_organic(): void
    {
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        Config::set('services.serper.key', 'test-serper-key');
        Config::set('services.serper.base_url', 'https://google.serper.dev');

        $html = '<!DOCTYPE html><html><head><title>T</title></head><body><p>'
            .str_repeat('Word. ', 200)
            .'</p></body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, 'serper.dev/search')) {
                return Http::response([
                    'organic' => [
                        ['link' => 'https://alpha-comp.test/p1', 'title' => 'A', 'position' => 1],
                        [
                            'link' => 'https://audited-site.test/article',
                            'title' => 'You',
                            'position' => 2,
                            'snippet' => 'Our snippet text.',
                            'displayLink' => 'audited-site.test › article',
                        ],
                        ['link' => 'https://beta-comp.test/p2', 'title' => 'B', 'position' => 3],
                        ['link' => 'https://gamma-comp.test/p3', 'title' => 'G', 'position' => 4],
                    ],
                ], 200);
            }
            if (in_array($url, [
                'https://alpha-comp.test/p1',
                'https://beta-comp.test/p2',
                'https://gamma-comp.test/p3',
            ], true)) {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected URL: '.$url, 500);
        });

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $bench = $method->invoke($svc, 'https://www.audited-site.test/article/', [
            'available' => true,
            'primary' => ['query' => 'my keyword'],
        ], 60.0, 900, 3, null, null, null, null, null);

        $this->assertTrue($bench['your_serp']['found']);
        $this->assertSame(2, $bench['your_serp']['position']);
        $this->assertTrue($bench['your_serp']['on_first_page']);
        $this->assertSame(4, $bench['your_serp']['organic_sample_size']);
        $this->assertSame('https://audited-site.test/article', $bench['your_serp']['matched_listing_url']);
        $this->assertSame('You', $bench['your_serp']['matched_listing_title']);
        $this->assertSame('Our snippet text.', $bench['your_serp']['matched_listing_snippet']);
        $this->assertSame('audited-site.test › article', $bench['your_serp']['matched_listing_display']);
        $this->assertSame('gsc_primary', $bench['keyword_source']);
    }

    public function test_your_serp_matches_organic_homepage_when_audited_path_not_in_sample(): void
    {
        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'resolveYourSerpPosition');
        $method->setAccessible(true);

        $organic = [
            ['link' => 'https://competitor.test/a', 'position' => 1],
            [
                'link' => 'https://www.example.test/',
                'position' => 4,
                'title' => 'Example home',
                'snippet' => 'Welcome to example.',
            ],
            ['link' => 'https://other.test/b', 'position' => 5],
        ];

        $out = $method->invoke($svc, 'https://example.test/products/deep-nested-url', $organic);

        $this->assertTrue($out['found']);
        $this->assertSame(4, $out['position']);
        $this->assertTrue($out['on_first_page']);
        $this->assertSame(3, $out['organic_sample_size']);
        $this->assertSame('https://www.example.test/', $out['matched_listing_url']);
        $this->assertSame('Welcome to example.', $out['matched_listing_snippet']);
        $this->assertSame('www.example.test', $out['matched_listing_display']);
    }

    public function test_your_serp_uses_best_position_when_same_domain_appears_twice(): void
    {
        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'resolveYourSerpPosition');
        $method->setAccessible(true);

        $organic = [
            ['link' => 'https://shop.example.test/old', 'position' => 8],
            ['link' => 'https://example.test/', 'position' => 2],
        ];

        $out = $method->invoke($svc, 'https://example.test/new-page', $organic);

        $this->assertTrue($out['found']);
        $this->assertSame(2, $out['position']);
        $this->assertSame('https://example.test/', $out['matched_listing_url']);
        $this->assertNull($out['matched_listing_snippet']);
        $this->assertSame('example.test', $out['matched_listing_display']);
    }

    public function test_build_benchmark_gap_table_computes_deltas(): void
    {
        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildBenchmarkGapTable');
        $method->setAccessible(true);

        $competitors = [
            ['word_count' => 1842, 'flesch' => 63.3, 'image_count' => 8],
            ['word_count' => 1842, 'flesch' => 63.3, 'image_count' => 8],
        ];

        $out = $method->invoke($svc, 1446, 83.4, 0, $competitors, null);

        $this->assertNotNull($out);
        $wc = collect($out['rows'])->firstWhere('key', 'word_count');
        $this->assertSame(-396.0, $wc['delta']);
        $this->assertSame('Add content', $wc['status']);
        $fl = collect($out['rows'])->firstWhere('key', 'flesch');
        $this->assertSame(20.1, round((float) $fl['delta'], 1));
        // Zone-aware fleschStatus: 83.4 is in the "accessible" band (>=60)
        // with a +5 delta, so it labels 'Accessible · above sample' rather
        // than the mid-band 'Better UX'.
        $this->assertSame('Accessible · above sample', $fl['status']);
        $im = collect($out['rows'])->firstWhere('key', 'images');
        $this->assertSame(-8.0, $im['delta']);
        $this->assertSame('Add visuals', $im['status']);
    }

    public function test_resolve_your_serp_position_outside_first_page(): void
    {
        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'resolveYourSerpPosition');
        $method->setAccessible(true);

        $organic = [];
        for ($i = 1; $i <= 10; $i++) {
            $organic[] = ['link' => "https://other.test/u{$i}", 'position' => $i];
        }
        $organic[] = ['link' => 'https://audited.test/page', 'position' => 11];
        $organic[] = ['link' => 'https://other.test/u12', 'position' => 12];

        $out = $method->invoke($svc, 'https://audited.test/page', $organic);

        $this->assertTrue($out['found']);
        $this->assertSame(11, $out['position']);
        $this->assertFalse($out['on_first_page']);
        $this->assertSame(12, $out['organic_sample_size']);
        $this->assertSame('https://audited.test/page', $out['matched_listing_url']);
        $this->assertNull($out['matched_listing_snippet']);
        $this->assertSame('audited.test › page', $out['matched_listing_display']);
    }

    public function test_readability_benchmark_recommendation_when_flesch_lags_median(): void
    {
        $engine = new RecommendationEngine;
        $method = new ReflectionMethod(RecommendationEngine::class, 'serpBenchmark');
        $method->setAccessible(true);

        $below = $method->invoke($engine, [
            'content' => ['word_count' => 5000],
            'benchmark' => [
                'keyword' => 'widgets',
                'your_flesch' => 40.0,
                'competitors' => [
                    ['flesch' => 70.0, 'word_count' => 800],
                    ['flesch' => 90.0, 'word_count' => 800],
                ],
            ],
        ]);
        $ids = array_column($below, 'id');
        $this->assertContains('bench.readability.below_median', $ids);
        $belowRec = collect($below)->firstWhere('id', 'bench.readability.below_median');
        $this->assertSame(RecommendationEngine::SEV_INFO, $belowRec['severity'] ?? null);

        $ok = $method->invoke($engine, [
            'content' => ['word_count' => 5000],
            'benchmark' => [
                'keyword' => 'widgets',
                'your_flesch' => 72.0,
                'competitors' => [
                    ['flesch' => 70.0, 'word_count' => 800],
                    ['flesch' => 90.0, 'word_count' => 800],
                ],
            ],
        ]);
        $this->assertNotContains('bench.readability.below_median', array_column($ok, 'id'));
    }

    public function test_serp_gap_length_when_word_count_below_competitor_average_band(): void
    {
        $engine = new RecommendationEngine;
        $method = new ReflectionMethod(RecommendationEngine::class, 'serpBenchmark');
        $method->setAccessible(true);

        $recs = $method->invoke($engine, [
            'content' => ['word_count' => 400],
            'benchmark' => [
                'keyword' => 'widgets guide',
                'your_flesch' => 55.0,
                'competitors' => [
                    ['flesch' => 55.0, 'word_count' => 1000],
                    ['flesch' => 55.0, 'word_count' => 1000],
                ],
            ],
        ]);

        $lengthRec = collect($recs)->firstWhere('id', 'bench.serp_gap.length');
        $this->assertNotNull($lengthRec);
        $this->assertSame(RecommendationEngine::SEV_SERP_GAP, $lengthRec['severity']);
    }

    public function test_readability_easier_than_market_emits_info_recommendation(): void
    {
        $engine = new RecommendationEngine;
        $method = new ReflectionMethod(RecommendationEngine::class, 'serpBenchmark');
        $method->setAccessible(true);

        $recs = $method->invoke($engine, [
            'content' => ['word_count' => 5000],
            'benchmark' => [
                'keyword' => 'widgets',
                'your_flesch' => 85.0,
                'competitors' => [
                    ['flesch' => 40.0, 'word_count' => 2000],
                    ['flesch' => 50.0, 'word_count' => 2000],
                ],
            ],
        ]);

        $easy = collect($recs)->firstWhere('id', 'bench.readability.easier_than_market');
        $this->assertNotNull($easy);
        $this->assertSame(RecommendationEngine::SEV_INFO, $easy['severity']);
    }

    public function test_benchmark_returns_error_payload_when_serper_client_throws(): void
    {
        Config::set('services.serper.key', 'test-key');

        $mock = Mockery::mock(SerperSearchClient::class);
        $mock->shouldReceive('search')->once()->andThrow(new RuntimeException('simulated Serper failure'));
        $this->app->instance(SerperSearchClient::class, $mock);

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $bench = $method->invoke($svc, 'https://audited-site.test/page', [
            'available' => true,
            'primary' => ['query' => 'primary query'],
        ], 62.5, 0, 0, null, null, null, null, null);

        $this->assertIsArray($bench);
        $this->assertSame('benchmark_error', $bench['skipped_reason']);
        $this->assertSame('primary query', $bench['keyword']);
        $this->assertNull($bench['keyword_source'] ?? null);
        $this->assertSame([], $bench['competitors']);
        $this->assertSame(62.5, $bench['your_flesch']);
        $this->assertSame([], $bench['serp_locale'] ?? []);
    }

    public function test_manual_serp_keyword_override_is_used_and_tagged(): void
    {
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        Config::set('services.serper.key', 'test-serper-key');
        Config::set('services.serper.base_url', 'https://google.serper.dev');

        $html = '<!DOCTYPE html><html><head><title>C</title></head><body><p>'
            .str_repeat('Word. ', 200)
            .'</p></body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, 'serper.dev/search')) {
                $body = $request->data();
                $this->assertSame('override phrase', $body['q'] ?? null);

                return Http::response([
                    'organic' => [
                        ['link' => 'https://only-comp.test/p', 'title' => 'C', 'position' => 1],
                    ],
                ], 200);
            }
            if ($url === 'https://only-comp.test/p') {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected URL: '.$url, 500);
        });

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $bench = $method->invoke($svc, 'https://audited-site.test/page', [
            'available' => true,
            'primary' => ['query' => 'ignored from gsc'],
        ], 50.0, 100, 1, null, '  override phrase  ', null, null, null);

        $this->assertSame('override phrase', $bench['keyword']);
        $this->assertSame('manual', $bench['keyword_source']);
    }

    public function test_serper_request_includes_gl_and_hl_when_provided(): void
    {
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        Config::set('services.serper.key', 'test-serper-key');
        Config::set('services.serper.base_url', 'https://google.serper.dev');

        $html = '<!DOCTYPE html><html><head><title>C</title></head><body><p>'
            .str_repeat('Word. ', 200)
            .'</p></body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, 'serper.dev/search')) {
                $body = $request->data();
                $this->assertSame('fr', $body['gl'] ?? null);
                $this->assertSame('fr', $body['hl'] ?? null);

                return Http::response([
                    'organic' => [
                        ['link' => 'https://only-comp.test/p', 'title' => 'C', 'position' => 1],
                    ],
                ], 200);
            }
            if ($url === 'https://only-comp.test/p') {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected URL: '.$url, 500);
        });

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $bench = $method->invoke($svc, 'https://audited-site.test/page', [
            'available' => true,
            'primary' => ['query' => 'test query'],
        ], 50.0, 100, 1, null, null, 'fr', 'fr', null);

        $this->assertSame(['gl' => 'fr', 'hl' => 'fr'], $bench['serp_locale']);
        $this->assertSame('test query', $bench['keyword']);
    }

    public function test_serper_request_infers_gl_when_only_hl_provided(): void
    {
        $guard = Mockery::mock(SafeHttpGuard::class);
        $guard->shouldReceive('check')->andReturn(['ok' => true]);
        $this->app->instance(SafeHttpGuard::class, $guard);

        Config::set('services.serper.key', 'test-serper-key');
        Config::set('services.serper.base_url', 'https://google.serper.dev');

        $html = '<!DOCTYPE html><html><head><title>C</title></head><body><p>'
            .str_repeat('Word. ', 200)
            .'</p></body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, 'serper.dev/search')) {
                $body = $request->data();
                $this->assertSame('jp', $body['gl'] ?? null);
                $this->assertSame('ja', $body['hl'] ?? null);

                return Http::response([
                    'organic' => [
                        ['link' => 'https://only-comp.test/p', 'title' => 'C', 'position' => 1],
                    ],
                ], 200);
            }
            if ($url === 'https://only-comp.test/p') {
                return Http::response($html, 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected URL: '.$url, 500);
        });

        $svc = $this->app->make(PageAuditService::class);
        $method = new ReflectionMethod(PageAuditService::class, 'buildSerperReadabilityBenchmark');
        $method->setAccessible(true);

        $bench = $method->invoke($svc, 'https://audited-site.test/page', [
            'available' => true,
            'primary' => ['query' => 'test query'],
        ], 50.0, 100, 1, null, null, null, 'ja', null);

        $this->assertSame(['gl' => 'jp', 'hl' => 'ja'], $bench['serp_locale']);
    }
}
