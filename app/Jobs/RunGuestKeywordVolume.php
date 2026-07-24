<?php

namespace App\Jobs;

use App\Mail\GuestKeywordVolumeLinkMail;
use App\Models\GuestKeywordVolume;
use App\Services\KeywordMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Runs ONE public, no-signup keyword search-volume check.
 *
 * DB-first: if the keyword (for this country) is already fresh in the shared
 * {@see \App\Models\KeywordMetric} cache — populated by ANY user, the GSC
 * import, or a prior guest check — we serve it with NO Keywords Everywhere
 * call (no cost). Only a cache miss/stale row triggers a single KE fetch,
 * which then caches the result for everyone. On the email-gated 2nd check the
 * report link is delivered by {@see GuestKeywordVolumeLinkMail}.
 */
class RunGuestKeywordVolume implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 1800;

    /**
     * The self-hosted keyword provider is ASYNCHRONOUS — `refresh()` only
     * dispatches the lookup and returns; the volume lands in keyword_metrics
     * later, when the node posts back to the webhook. So after kicking the
     * lookup off we re-queue ourselves on a short delay and wait for it,
     * instead of failing on the first cache miss.
     */
    private const MAX_ATTEMPTS = 20;         // ~2 min of waiting

    private const RETRY_SECONDS = 6;

    public function __construct(public readonly string $id, public readonly int $attempt = 0)
    {
        $this->onQueue(\App\Support\Queues::INTERACTIVE);
    }

    /** Attempt is part of the key so a re-dispatch isn't swallowed by the still-held unique lock. */
    public function uniqueId(): string
    {
        return 'guest-keyword-volume:'.$this->id.':'.$this->attempt;
    }

    public function handle(KeywordMetricsService $metrics): void
    {
        $row = GuestKeywordVolume::query()->find($this->id);
        if (! $row instanceof GuestKeywordVolume || $row->isFinished()) {
            return;
        }
        if ($row->status === GuestKeywordVolume::STATUS_QUEUED) {
            $row->markRunning();
        }

        // DB-first: use a fresh cached row as-is; only fetch on a miss/stale.
        $metric = $metrics->metricsFor($row->keyword, $row->country);
        $servedFromCache = $metric !== null && $metric->isFresh();

        if (! $servedFromCache) {
            // Kick the lookup off ONCE (attempt 0). Re-running it on every poll
            // would fire a fresh node lookup every few seconds.
            if ($this->attempt === 0) {
                $metrics->refresh([$row->keyword], $row->country, source: 'guest_keyword_volume');
            }
            $metric = $metrics->metricsFor($row->keyword, $row->country);
        }

        if ($metric === null) {
            // Async provider: the node is still working. Wait for the webhook to
            // land the row rather than declaring failure on the first miss (the
            // old Keywords Everywhere provider was synchronous, which is why a
            // single immediate re-read used to be enough). The row stays
            // `running`, so the page keeps polling.
            if (\App\Support\KeywordProviderConfig::usingKeywordFinder() && $this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($this->id, $this->attempt + 1)
                    ->delay(now()->addSeconds(self::RETRY_SECONDS));

                return;
            }

            $row->markFailed('We couldn’t fetch volume data for that keyword right now. Please try again in a moment.');

            return;
        }

        $row->markCompleted([
            'keyword' => $row->keyword,
            'country' => $row->country,
            'volume' => $metric->search_volume,
            'cpc' => $metric->cpc,
            'currency' => $metric->currency,
            'competition' => $metric->competition,
            'trend' => is_array($metric->trend_12m) ? $metric->trend_12m : [],
            'cached' => $servedFromCache,
            'fetched_at' => optional($metric->fetched_at)->toIso8601String() ?? now()->toIso8601String(),
        ]);

        if ($row->email) {
            try {
                Mail::to($row->email)->send(new GuestKeywordVolumeLinkMail($row));
            } catch (\Throwable $e) {
                Log::warning('RunGuestKeywordVolume: email failed: '.$e->getMessage());
            }
        }
    }

    /** Timeout / exception: still record a failure so the poller stops waiting. */
    public function failed(?\Throwable $e): void
    {
        $row = GuestKeywordVolume::query()->find($this->id);
        if ($row instanceof GuestKeywordVolume && ! $row->isFinished()) {
            $row->markFailed('The volume check timed out. Please try again.');
        }
    }
}
