<?php

namespace Tests\Feature;

use App\Models\Proxy;
use App\Services\Crawler\ProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paid-proxy protection (owner 2026-08-20): the pool is paid residential
 * credentials — NO automatic path may delete or deactivate a proxy row.
 * The old 5-strike markFailure delete + the prune command's
 * one-bad-ping-deletes-it sweep wiped all four paid proxies when a blocked
 * site's captcha walls were mis-charged against proxy health.
 */
class ProxyPoolProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function proxy(): Proxy
    {
        return Proxy::create([
            'url' => 'http://user:pass@10.9.9.9:12323',
            'url_hash' => Proxy::hashUrl('http://user:pass@10.9.9.9:12323'),
            'label' => 'admin',
            'active' => true,
            'fail_count' => 0,
            'success_count' => 0,
        ]);
    }

    public function test_mark_failure_never_deletes_no_matter_how_many_strikes(): void
    {
        $proxy = $this->proxy();
        $pool = app(ProxyPool::class);

        for ($i = 0; $i < 50; $i++) {
            $pool->markFailure($proxy->url);
        }

        $proxy->refresh();
        $this->assertTrue($proxy->exists, 'proxy row must survive any number of failures');
        $this->assertTrue((bool) $proxy->active, 'must stay active too');
        $this->assertSame(50, (int) $proxy->fail_count, 'failures are telemetry only');
    }

    public function test_prune_command_records_health_but_never_deletes(): void
    {
        $proxy = $this->proxy(); // unreachable address → the live test will fail

        $this->artisan('ebq:proxy-pool-prune', ['--timeout' => 1, '--concurrency' => 1])
            ->assertSuccessful();

        $proxy->refresh();
        $this->assertTrue($proxy->exists, 'a failing health check must never delete a paid proxy');
        $this->assertTrue((bool) $proxy->active);
        $this->assertGreaterThan(0, (int) $proxy->fail_count);
    }
}
