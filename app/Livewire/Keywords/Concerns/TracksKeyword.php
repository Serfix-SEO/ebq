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
        $created = $this->trackOne($keyword);

        if ($created === null) {
            // trackOne already set a specific error notice.
            return;
        }

        $this->trackNotice = $created
            ? 'Added “'.trim($keyword).'” to rank tracker — first check queued.'
            : 'Already tracking “'.trim($keyword).'”.';
    }

    /**
     * Track one keyword. Returns true when newly created, false when it was
     * already tracked, null on error (with $trackNotice set to the reason).
     */
    protected function trackOne(string $keyword): ?bool
    {
        $keyword = trim($keyword);
        $user = Auth::user();
        $websiteId = session('current_website_id');

        if ($keyword === '' || $user === null || ($websiteId === null || $websiteId === '') || ! $user->canViewWebsiteId($websiteId)) {
            $this->trackNotice = 'Could not add to rank tracker.';

            return null;
        }

        $website = Website::find($websiteId);
        $domain = $this->resolveTrackDomain($website);
        if ($domain === '') {
            $this->trackNotice = 'Set a target domain on the website first.';

            return null;
        }
        $country = $this->resolveTrackCountry();

        $row = RankTrackingKeyword::updateOrCreate(
            [
                'website_id' => $websiteId,
                'keyword_hash' => RankTrackingKeyword::hashKeyword($keyword),
                'search_engine' => 'google',
                'search_type' => 'organic',
                'country' => $country,
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
            TrackKeywordRankJob::dispatch($row->id, $row->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);

            return true;
        }

        return false;
    }

    /**
     * Domain to track the keyword FOR. Defaults to the account's current
     * website; components analyzing a different target (e.g. the Keyword Gap
     * tool on a competitor URL) override this to track that target instead.
     */
    protected function resolveTrackDomain(?Website $website): string
    {
        return $website && (string) $website->domain !== '' ? (string) $website->domain : '';
    }

    /** Country for the tracked check. Overridable per component. */
    protected function resolveTrackCountry(): string
    {
        return 'us';
    }
}
