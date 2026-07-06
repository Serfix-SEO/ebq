<?php

namespace Tests\Feature;

use App\Jobs\CrawlWebsitePagesJob;
use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin Ops dashboard (built after the 2026-07-06 silent-worker-failure
 * incident): failed jobs grouped with retry/forget, never-crawled stuck
 * sites with a start-crawl action, live queue depths.
 */
class AdminOpsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedFailedJob(string $job = 'App\\Jobs\\CrawlWebsitePagesJob'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'crawl',
            'payload' => json_encode(['displayName' => $job, 'uuid' => $uuid]),
            'exception' => "PDOException: SQLSTATE[HY000] [2002] Connection refused\n#0 stack...",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_non_admin_cannot_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.ops.index'))->assertForbidden();
    }

    public function test_dashboard_shows_failed_jobs_and_stuck_sites(): void
    {
        $this->seedFailedJob();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'stuck-site.test']);
        CrawlSite::whereKey($website->crawl_site_id)->update([
            'status' => 'pending',
            'subscriber_count' => 1,
            'created_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.ops.index'))
            ->assertOk()
            ->assertSee('CrawlWebsitePagesJob')
            ->assertSee('Connection refused')
            ->assertSee('stuck-site.test')
            ->assertSee('Start crawl');
    }

    public function test_retry_requeues_and_forget_deletes(): void
    {
        $uuid = $this->seedFailedJob();
        $admin = $this->admin();

        // retry: queue:retry pushes the job back and removes the failed row
        $this->actingAs($admin)
            ->post(route('admin.ops.retry'), ['uuids' => [$uuid]])
            ->assertRedirect();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);

        // forget: row deleted without a requeue
        $uuid2 = $this->seedFailedJob('App\\Jobs\\SyncSitemaps');
        $this->actingAs($admin)
            ->post(route('admin.ops.forget'), ['uuids' => [$uuid2]])
            ->assertRedirect();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid2]);
    }

    public function test_start_crawl_dispatches_for_stuck_site(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'kickme.test']);

        $this->actingAs($this->admin())
            ->post(route('admin.ops.start-crawl', $website->crawl_site_id))
            ->assertRedirect();

        Queue::assertPushed(CrawlWebsitePagesJob::class, 1);
    }
}
