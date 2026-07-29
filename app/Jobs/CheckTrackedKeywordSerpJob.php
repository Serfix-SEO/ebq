<?php

namespace App\Jobs;

use App\Mail\ContentRankGainsMail;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Services\Content\ContentSerpChecker;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use App\Support\ShardContext;
use App\Support\ShardLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $gains = [];
        foreach ($rows as $kw) {
            try {
                $gain = $checker->check($kw);
                if ($gain !== null) {
                    $gains[] = $gain;
                }
            } catch (\Throwable $e) {
                Log::warning('content_tracker.serp_check_error', [
                    'tracked_keyword_id' => $kw->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $this->notifyRankGains($gains);
    }

    /**
     * One "your rankings moved up" digest per website per day — never one email
     * per keyword, since a weekly check moves many at once. The day-scoped
     * Cache::add is the throttle: the daily schedule AND the tracker's manual
     * "Check rank now" button both dispatch this job, so without it a client
     * could be mailed twice for the same run. Best-effort — a mail failure must
     * never fail the (already billed) SERP check.
     *
     * @param  list<array<string,mixed>>  $gains
     */
    private function notifyRankGains(array $gains): void
    {
        if ($gains === [] || ! ContentAutopilotConfig::rankAlertsEnabled()) {
            return;
        }

        $website = Website::find($this->websiteId);
        $owner = $website?->owner;
        if ($website === null || $owner === null || ! $owner->email) {
            return;
        }

        $throttleKey = 'content:rank_gains_mail:'.$website->id.':'.now()->toDateString();
        if (! Cache::add($throttleKey, true, now()->endOfDay())) {
            return;
        }

        try {
            Mail::to($owner->email)->queue(new ContentRankGainsMail($owner, $website, $gains));
            Log::info('content_tracker.rank_gains_notified', [
                'website_id' => $website->id,
                'movements' => count($gains),
            ]);
        } catch (\Throwable $e) {
            Cache::forget($throttleKey); // let the next run try again
            Log::warning('content_tracker.rank_gains_mail_error', [
                'website_id' => $website->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
