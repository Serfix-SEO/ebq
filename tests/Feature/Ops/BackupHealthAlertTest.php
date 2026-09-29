<?php

namespace Tests\Feature\Ops;

use App\Mail\FailedJobsDigestMail;
use App\Models\User;
use App\Support\BackupHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The backup alarm.
 *
 * A backup is the one failure you cannot afford to discover late: you find out
 * it stopped at the moment you need it, which is always the worst moment. The
 * dumps live in /var/backups on the host, invisible to this container, so the
 * script leaves a heartbeat and these tests pin what the digest does with it.
 */
class BackupHealthAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // This box is meant to be backing up — the whole point of these cases.
        config(['features.backups_expected' => true]);
        foreach (['missing', 'stale', 'failed', 'truncated'] as $state) {
            Cache::forget('backup-health-warned:'.now()->utc()->format('Y-m-d').':'.$state);
        }
        User::factory()->create(['is_admin' => true]);
    }

    private function heartbeat(array $overrides = []): void
    {
        Storage::disk('local')->put(BackupHealth::STATUS_FILE, (string) json_encode(array_merge([
            'ok' => true,
            'at' => now()->toIso8601String(),
            'bytes' => 1_000_000_000,
            'median_bytes' => 1_000_000_000,
            'offsite' => false,
        ], $overrides)));
    }

    private function digest(): string
    {
        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        $body = '';
        Mail::assertSent(FailedJobsDigestMail::class, function ($mail) use (&$body) {
            $body = (string) $mail->body;

            return true;
        });

        return $body;
    }

    public function test_a_box_with_no_backup_job_is_silent(): void
    {
        // A dev checkout has no timer and no heartbeat. Shouting at every
        // developer is how an alarm gets ignored on the day it is real.
        config(['features.backups_expected' => false]);

        $this->assertSame('ok', BackupHealth::check()['state']);

        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();
        Mail::assertNotSent(FailedJobsDigestMail::class);
    }

    public function test_a_healthy_backup_says_nothing(): void
    {
        $this->heartbeat();

        $this->assertSame('ok', BackupHealth::check()['state']);

        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();
        Mail::assertNotSent(FailedJobsDigestMail::class);
    }

    public function test_a_backup_that_never_reported_in_is_an_alarm(): void
    {
        $this->assertSame('missing', BackupHealth::check()['state']);
        $this->assertStringContainsString('DATABASE BACKUP IS NOT RUNNING', $this->digest());
    }

    public function test_a_backup_that_stopped_running_goes_stale(): void
    {
        $this->heartbeat(['at' => now()->subHours(30)->toIso8601String()]);

        $this->assertSame('stale', BackupHealth::check()['state']);
        $this->assertStringContainsString('DATABASE BACKUP IS STALE', $this->digest());
    }

    public function test_a_backup_a_few_hours_late_is_not_yet_an_alarm(): void
    {
        // The timer fires daily; a slow dump or a reboot must not page anyone.
        $this->heartbeat(['at' => now()->subHours(25)->toIso8601String()]);

        $this->assertSame('ok', BackupHealth::check()['state']);
    }

    public function test_the_script_reporting_failure_is_an_alarm(): void
    {
        $this->heartbeat(['ok' => false, 'error' => 'backup.sh failed at line 42']);

        $body = $this->digest();
        $this->assertStringContainsString('DATABASE BACKUP FAILED', $body);
        $this->assertStringContainsString('line 42', $body);
    }

    /**
     * The nastiest shape: the dump exits 0 and looks like a backup, but the
     * file is a fraction of its usual size. Nobody notices until the restore.
     */
    public function test_a_dump_that_succeeded_but_is_truncated_is_an_alarm(): void
    {
        $this->heartbeat(['bytes' => 12 * 1048576, 'median_bytes' => 1_000_000_000]);

        $this->assertSame('truncated', BackupHealth::check()['state']);
        $body = $this->digest();
        $this->assertStringContainsString('DATABASE BACKUP LOOKS TRUNCATED', $body);
        $this->assertStringContainsString('12 MB', $body);
    }

    public function test_local_only_backups_are_called_out_in_the_warning(): void
    {
        $this->heartbeat(['ok' => false, 'offsite' => false, 'error' => 'boom']);

        $this->assertStringContainsString('LOCAL-ONLY', $this->digest());
    }

    public function test_an_offsite_backup_does_not_carry_the_local_only_warning(): void
    {
        $this->heartbeat(['ok' => false, 'offsite' => true, 'error' => 'boom']);

        $this->assertStringNotContainsString('LOCAL-ONLY', $this->digest());
    }

    public function test_the_alarm_repeats_at_most_once_a_day(): void
    {
        $this->heartbeat(['at' => now()->subHours(30)->toIso8601String()]);

        $this->assertStringContainsString('DATABASE BACKUP IS STALE', $this->digest());

        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();
        Mail::assertNotSent(FailedJobsDigestMail::class);
    }
}
