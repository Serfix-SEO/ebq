<?php

namespace App\Jobs;

use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\Content\ContentSerpChecker;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekly live-SERP refresh for a website's tracked keywords (Serper). Only rows
 * whose SERP position is unchecked or older than ContentTrackedKeyword::SERP_STALE_DAYS
 * are queried, so a daily/burst dispatch is a cheap no-op and each keyword costs
 * ~1 Serper credit per week. ShouldBeUnique collapses the burst of dispatches
 * that a publish (auto-add of several keywords) fires.
 */
class CheckTrackedKeywordSerpJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return $this->websiteId;
    }

    public function handle(ContentSerpChecker $checker): void
    {
        if (ShardLock::websiteLocked($this->websiteId)) {
            $this->release(30);

            return;
        }
        app(ShardContext::class)->forWebsite($this->websiteId);

        if (Website::find($this->websiteId) === null) {
            return;
        }

        $stale = now()->subDays(ContentTrackedKeyword::SERP_STALE_DAYS);
        $rows = ContentTrackedKeyword::query()
            ->where('website_id', $this->websiteId)
            ->where(fn ($q) => $q->whereNull('serp_checked_at')->orWhere('serp_checked_at', '<', $stale))
            ->orderByRaw('serp_checked_at is null desc') // never-checked first
            ->orderBy('serp_checked_at')
            ->limit(500)
            ->get();

        foreach ($rows as $kw) {
            try {
                $checker->check($kw);
            } catch (\Throwable $e) {
                Log::warning('content_tracker.serp_check_error', [
                    'tracked_keyword_id' => $kw->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }
    }
}
