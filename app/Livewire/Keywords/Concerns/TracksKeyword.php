<?php

namespace App\Livewire\Keywords\Concerns;

use App\Jobs\TrackKeywordRankJob;
use App\Models\RankTrackingKeyword;
use App\Models\Website;
use App\Support\RankTrackerConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Adds a "Track this keyword" action to any keyword-research Livewire component.
 * Models the canonical single-keyword path from {@see \App\Livewire\Keywords\KeywordDetail::addToRankTracker()}
 * so every research surface creates rank-tracking rows identically. Feedback is
 * surfaced via the shared {@see $trackNotice} property.
 */
trait TracksKeyword
{
    public ?string $trackNotice = null;

    public function track(string $keyword): void
    {
        $this->trackNotice = null;
        $keyword = trim($keyword);
        $user = Auth::user();
        $websiteId = session('current_website_id');

        if ($keyword === '' || $user === null || ($websiteId === null || $websiteId === '') || ! $user->canViewWebsiteId($websiteId)) {
            $this->trackNotice = 'Could not add to rank tracker.';

            return;
        }

        $website = Website::find($websiteId);
        $domain = $website && (string) $website->domain !== '' ? (string) $website->domain : '';
        if ($domain === '') {
            $this->trackNotice = 'Set a target domain on the website first.';

            return;
        }

        $row = RankTrackingKeyword::updateOrCreate(
            [
                'website_id' => $websiteId,
                'keyword_hash' => RankTrackingKeyword::hashKeyword($keyword),
                'search_engine' => 'google',
                'search_type' => 'organic',
                'country' => 'us',
                'language' => 'en',
                'device' => 'desktop',
                'location' => null,
            ],
            [
                'user_id' => $user->id,
                'keyword' => $keyword,
                'target_domain' => $domain,
                'depth' => RankTrackerConfig::DEFAULT_DEPTH,
                'autocorrect' => true,
                'safe_search' => false,
                'check_interval_hours' => RankTrackerConfig::checkIntervalHours(),
                'is_active' => true,
                'next_check_at' => Carbon::now(),
            ]
        );

        if ($row->wasRecentlyCreated) {
            TrackKeywordRankJob::dispatch($row->id, true)->onQueue(\App\Support\Queues::INTERACTIVE);
            $this->trackNotice = 'Added “'.$keyword.'” to rank tracker — first check queued.';
        } else {
            $this->trackNotice = 'Already tracking “'.$keyword.'”.';
        }
    }
}
