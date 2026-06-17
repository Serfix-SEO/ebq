<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\OwnBacklinkSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background sync of the website's own backlinks from Keywords Everywhere.
 *
 * Constraints:
 * - tries=1        : KE costs credits per row. Never auto-retry without a human.
 * - timeout=180    : The endpoint usually returns in <30s; generous ceiling.
 * - uniqueFor=86400: One pending sync per website per day. Even if the score
 *                    endpoint fires this every keystroke, only one queues —
 *                    and the service itself no-ops for 30 days via Cache.
 *
 * Safe to dispatch on every score request. The service will return 0 if the
 * 30-day window is still fresh.
 */
class SyncOwnBacklinksFromKeywordsEverywhere implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $websiteId,
        public readonly ?string $ownerUserId = null,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return 'sync_own_backlinks:' . $this->websiteId;
    }

    public function handle(OwnBacklinkSyncService $service): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::query()->find($this->websiteId);
        if (! $website instanceof Website) {
            return;
        }

        try {
            $service->syncForWebsite($website, $this->ownerUserId);
        } catch (Throwable $e) {
            Log::warning('SyncOwnBacklinksFromKeywordsEverywhere: failed', [
                'website_id' => $this->websiteId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
