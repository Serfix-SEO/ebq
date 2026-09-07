<?php

namespace Tests\Feature\Content;

use App\Services\Content\LinkVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pre-flight link verification: only a definitive 404/410 is dead; anything
 * transient keeps the link. phpunit.xml pins the feature OFF suite-wide so
 * pipelines never issue real HTTP — these tests enable it explicitly.
 */
class LinkVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.article_link_verify' => true]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_kill_switch_short_circuits_with_zero_http(): void
    {
        config(['features.article_link_verify' => false]);
        Http::fake();

        $urls = ['https://a.example/x', 'https://b.example/y'];
        $this->assertSame($urls, app(LinkVerifier::class)->filterAlive($urls));
        Http::assertNothingSent();
    }

    public function test_only_definitive_404_and_410_count_as_dead(): void
    {
        Http::fake([
            'https://dead.example/*' => Http::response('', 404),
            'https://gone.example/*' => Http::response('', 410),
            'https://ok.example/*' => Http::response('ok', 200),
            'https://err.example/*' => Http::response('', 500),
            'https://wall.example/*' => Http::response('', 403),
            'https://flaky.example/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $alive = app(LinkVerifier::class)->filterAlive([
            'https://ok.example/page',
            'https://dead.example/page',
            'https://gone.example/page',
            'https://err.example/page',
            'https://wall.example/page',
            'https://flaky.example/page',
        ]);

        $this->assertSame([
            'https://ok.example/page',
            'https://err.example/page',
            'https://wall.example/page',
            'https://flaky.example/page',
        ], $alive);
    }

    public function test_verdicts_are_cached_across_calls(): void
    {
        Http::fake(['https://dead.example/*' => Http::response('', 404)]);

        $v = app(LinkVerifier::class);
        $this->assertTrue($v->isDead('https://dead.example/page'));
        $this->assertTrue($v->isDead('https://dead.example/page'));

        Http::assertSentCount(1);
    }

    public function test_non_http_urls_are_ignored(): void
    {
        Http::fake();
        $this->assertSame([], app(LinkVerifier::class)->deadSet(['#anchor', 'mailto:x@y.z', '/relative']));
        Http::assertNothingSent();
    }
}
