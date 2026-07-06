<?php

namespace Tests\Feature;

use App\Models\CrawlSite;
use App\Models\User;
use App\Mail\FailedJobsDigestMail;
use App\Support\FailedJobAlertBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Coverage for the failed-job alert pipeline (built after the 2026-07-06
 * incident: crawl jobs died on the worker box for 3 days, visible only in
 * failed_jobs). Queue::failing() buffers into shared Redis on every box;
 * ebq:failed-jobs-alert drains + mails admins from the web box.
 */
class FailedJobsAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The buffer talks to Redis directly; tests run against the array
        // cache but Redis facade still needs flushing between tests when a
        // real redis is configured. Guard: clear our one key.
        try {
            Redis::connection()->del(FailedJobAlertBuffer::KEY);
        } catch (\Throwable) {
            $this->markTestSkipped('Redis unavailable in this environment.');
        }
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->del(FailedJobAlertBuffer::KEY);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function pushFailure(string $job = 'App\\Jobs\\CrawlWebsitePagesJob'): void
    {
        Redis::connection()->lpush(FailedJobAlertBuffer::KEY, json_encode([
            'job' => $job,
            'queue' => 'crawl',
            'connection' => 'redis',
            'exception' => 'PDOException: Connection refused',
            'box' => 'test-box',
            'failed_at' => now()->toIso8601String(),
        ]));
    }

    public function test_no_failures_and_no_stuck_sites_sends_nothing(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);

        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_buffered_failure_mails_admins_and_drains_the_buffer(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'ops-admin@serfix.io']);
        User::factory()->create(['is_admin' => false, 'email' => 'not-admin@serfix.io']);
        $this->pushFailure();

        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        Mail::assertSent(FailedJobsDigestMail::class, function (FailedJobsDigestMail $mail) use ($admin) {
            return $mail->hasTo($admin->email) && ! $mail->hasTo('not-admin@serfix.io') && $mail->failureCount === 1;
        });
        $this->assertSame(0, (int) Redis::connection()->llen(FailedJobAlertBuffer::KEY));

        // Second run with an empty buffer: silent (no repeat spam).
        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_stuck_pending_crawl_site_with_subscribers_is_reported(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        $site = CrawlSite::create([
            'normalized_domain' => 'never-crawled.test',
            'status' => 'pending',
            'subscriber_count' => 1,
            'effective_cap' => 100,
        ]);
        // created_at isn't fillable — backdate directly.
        $site->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        Mail::assertSent(FailedJobsDigestMail::class, fn (FailedJobsDigestMail $m) => $m->stuckSiteCount === 1);
    }

    public function test_fresh_pending_site_is_not_flagged(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        CrawlSite::create([
            'normalized_domain' => 'just-created.test',
            'status' => 'pending',
            'subscriber_count' => 1,
            'effective_cap' => 100,
        ]);

        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
