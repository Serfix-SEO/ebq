<?php

namespace Tests\Feature;

use App\Jobs\Ops\DeployVerifyJob;
use App\Support\CodeFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The probe that answers "are the workers running what I just deployed?".
 * Its whole value is being trustworthy, so the failure paths are pinned:
 * a silent pass on a stale worker would be worse than no probe at all.
 */
class DeployVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_reports_a_match_when_the_worker_shares_our_code(): void
    {
        $fingerprint = CodeFingerprint::current();

        (new DeployVerifyJob('tok', 'crawl', $fingerprint))->handle();

        $hit = Cache::get(DeployVerifyJob::cacheKey('tok', 'crawl'));

        $this->assertNotNull($hit);
        $this->assertTrue($hit['matches']);
        $this->assertSame($fingerprint, $hit['fingerprint']);
        $this->assertSame(gethostname(), $hit['host']);
        $this->assertArrayHasKey('redis_host', $hit);
    }

    public function test_the_job_reports_a_mismatch_when_the_worker_is_on_old_code(): void
    {
        // The 2026-08-14→16 shape: worker still holds pre-deploy classes.
        (new DeployVerifyJob('tok', 'crawl', 'deadbeef0000'))->handle();

        $hit = Cache::get(DeployVerifyJob::cacheKey('tok', 'crawl'));

        $this->assertFalse($hit['matches']);
        $this->assertNotSame('deadbeef0000', $hit['fingerprint']);
    }

    public function test_the_command_fails_loudly_when_a_queue_has_no_worker(): void
    {
        // Nothing consumes the queue, so no probe ever answers.
        Queue::fake();

        $this->artisan('ebq:deploy-verify --queues=crawl --wait=5')
            ->expectsOutputToContain('NO WORKER')
            ->assertFailed();
    }

    public function test_the_command_dispatches_one_probe_per_queue(): void
    {
        Queue::fake();

        $this->artisan('ebq:deploy-verify --queues=crawl,sync --wait=5')->assertFailed();

        Queue::assertPushed(DeployVerifyJob::class, 2);
        Queue::assertPushed(fn (DeployVerifyJob $j) => $j->queueName === 'crawl');
        Queue::assertPushed(fn (DeployVerifyJob $j) => $j->queueName === 'sync');
    }

    public function test_the_fingerprint_is_stable_and_short(): void
    {
        $a = CodeFingerprint::current();
        $b = CodeFingerprint::current();

        $this->assertSame($a, $b);
        $this->assertSame(12, strlen($a));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $a);
    }
}
