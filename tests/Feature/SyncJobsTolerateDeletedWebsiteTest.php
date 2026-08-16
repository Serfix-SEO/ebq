<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiInsights;
use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Jobs\SyncSitemaps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A website deleted while its sync jobs sit in the queue is an ordinary race,
 * not a failure: findOrFail turned it into permanent failed_jobs rows (6 of
 * them during the 2026-08-16 backlog drain) that no retry could ever clear.
 */
class SyncJobsTolerateDeletedWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public static function jobs(): array
    {
        return [
            'sitemaps' => [SyncSitemaps::class],
            'analytics' => [SyncAnalyticsData::class],
            'search console' => [SyncSearchConsoleData::class],
            'ai insights' => [GenerateAiInsights::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jobs')]
    public function test_job_returns_quietly_when_the_website_is_gone(string $job): void
    {
        $missingId = (string) Str::ulid();

        // Resolving the job's own handle() dependencies out of the container:
        // whatever service it wants, it must never reach the point of using it.
        $instance = new $job($missingId);
        app()->call([$instance, 'handle']);

        $this->assertTrue(true, $job.' handled a deleted website without throwing');
    }
}
