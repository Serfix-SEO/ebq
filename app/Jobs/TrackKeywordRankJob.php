<?php

namespace App\Jobs;

use App\Models\RankTrackingKeyword;
use App\Services\RankTrackingService;
use App\Services\ReportCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TrackKeywordRankJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        public int $keywordId,
        public bool $forced = false,
    ) {}

    public function handle(RankTrackingService $service): void
    {
        $keyword = RankTrackingKeyword::find($this->keywordId);
        if (! $keyword) {
            return;
        }

        try {
            $service->check($keyword, $this->forced);
            // A successful check writes current_position on the keyword,
            // which feeds Overview's tracker_distribution + tracked_keywords.
            // Bump the website's data version so the next read rebuilds.
            ReportCache::flushWebsite((int) $keyword->website_id);
        } catch (\Throwable $e) {
            Log::error('TrackKeywordRankJob failed: '.$e->getMessage(), [
                'keyword_id' => $this->keywordId,
            ]);

            $keyword->forceFill([
                'last_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'last_checked_at' => Carbon::now(),
                'next_check_at' => Carbon::now()->addHours(max(1, (int) $keyword->check_interval_hours)),
            ])->save();

            throw $e;
        }
    }
}
