<?php

namespace App\Livewire\Keywords;

use App\Jobs\TrackKeywordRankJob;
use App\Models\RankTrackingKeyword;
use App\Models\Website;
use App\Services\KeywordDetailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Deep-dive view for a single query. Pulls together every data signal we have
 * on this keyword — scoped to the currently-selected website:
 *
 *  - Global keyword-intelligence layer (volume, CPC, competition, 12-mo trend)
 *  - This site's Search Console performance (28d + 90d, daily series)
 *  - Pages of ours that rank for it + top-ranking URL
 *  - Per-country + per-device breakdown
 *  - Rank-tracker status + latest SERP snapshot
 *  - Related / PAA questions captured from the rank tracker
 *  - Opportunity flags: striking distance, cannibalized, quick win
 *
 * All signal-gathering lives in KeywordDetailService (shared with the WP
 * plugin's /api/v1/hq/keyword-detail endpoint); this component only handles
 * session/auth concerns and the add-to-tracker action.
 */
class KeywordDetail extends Component
{
    public ?string $websiteId = null;
    public string $query = '';

    public function mount(string $query): void
    {
        $this->websiteId = session('current_website_id');
        $this->query = trim($query);
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    public function addToRankTracker(): void
    {
        $user = Auth::user();
        if (! $user || ($this->websiteId === null || $this->websiteId === '') || ! $user->canViewWebsiteId($this->websiteId)) {
            session()->flash('keyword_detail_status', 'Permission denied.');

            return;
        }
        if (trim($this->query) === '') {
            return;
        }

        $website = Website::find($this->websiteId);
        $domain = $website && (string) $website->domain !== '' ? (string) $website->domain : '';
        if ($domain === '') {
            session()->flash('keyword_detail_status', 'Set a target domain on the website first.');

            return;
        }

        $row = RankTrackingKeyword::updateOrCreate(
            [
                'website_id' => $this->websiteId,
                'keyword_hash' => RankTrackingKeyword::hashKeyword($this->query),
                'search_engine' => 'google',
                'search_type' => 'organic',
                'country' => 'us',
                'language' => 'en',
                'device' => 'desktop',
                'location' => null,
            ],
            [
                'user_id' => $user->id,
                'keyword' => $this->query,
                'target_domain' => $domain,
                'depth' => \App\Support\RankTrackerConfig::DEFAULT_DEPTH,
                'autocorrect' => true,
                'safe_search' => false,
                'check_interval_hours' => \App\Support\RankTrackerConfig::checkIntervalHours(),
                'is_active' => true,
                'next_check_at' => Carbon::now(),
            ]
        );

        if ($row->wasRecentlyCreated) {
            TrackKeywordRankJob::dispatch($row->id, $row->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);
            session()->flash('keyword_detail_status', 'Added to rank tracker — first SERP check queued.');
        } else {
            session()->flash('keyword_detail_status', 'Already tracking this keyword.');
        }
    }

    public function render()
    {
        $user = Auth::user();
        $hasAccess = ($this->websiteId !== null && $this->websiteId !== '') && $user?->canViewWebsiteId($this->websiteId);

        $data = [
            'has_access' => $hasAccess,
            'website' => $hasAccess ? Website::find($this->websiteId) : null,
            'metric' => null,
            'gsc_totals' => null,
            'gsc_daily' => [],
            'top_pages' => [],
            'countries' => [],
            'devices' => [],
            'tracker' => null,
            'tracker_latest_snapshot' => null,
            'related_searches' => [],
            'paa' => [],
            'flags' => [
                'striking_distance' => false,
                'cannibalized' => false,
                'quick_win' => false,
            ],
            'projections' => [
                'projected_clicks' => null,
            ],
            'language' => null,
        ];

        if ($hasAccess && $this->query !== '') {
            $data['language'] = app(\App\Services\LanguageDetectorService::class)->detect($this->query);
            $data = array_merge($data, app(KeywordDetailService::class)->signals($this->websiteId, $this->query));
        }

        return view('livewire.keywords.keyword-detail', $data);
    }
}
