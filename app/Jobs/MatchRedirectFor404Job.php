<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\AiRedirectMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Async wrapper around AiRedirectMatcherService::matchFor404 so the WP
 * plugin's heartbeat (which posts a batch of 404s) returns immediately.
 *
 * Constraints:
 * - tries=1        : LLM costs tokens. Never auto-retry without a human.
 * - timeout=120    : LLM call typically <20s; generous ceiling.
 * - uniqueFor=3600 : One pending match per (website × source path) per
 *                    hour. Prevents the same heartbeat ping queueing
 *                    duplicates if the cron fires twice.
 */
class MatchRedirectFor404Job implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $websiteId,
        public readonly string $sourcePath,
        public readonly int $hits = 1,
    ) {
        $this->onQueue(\App\Support\Queues::INTERACTIVE);
    }

    public function uniqueId(): string
    {
        return 'match_404:' . $this->websiteId . ':' . hash('xxh3', $this->sourcePath);
    }

    public function handle(AiRedirectMatcherService $service): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::query()->find($this->websiteId);
        if (! $website instanceof Website) {
            return;
        }
        try {
            $service->matchFor404($website, $this->sourcePath, $this->hits);
        } catch (Throwable $e) {
            Log::warning('MatchRedirectFor404Job: failed', [
                'website_id' => $this->websiteId,
                'source_path' => $this->sourcePath,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
