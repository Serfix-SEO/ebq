<?php

namespace App\Livewire\Keywords;

use App\Jobs\TrackKeywordRankJob;
use App\Models\KeywordMetric;
use App\Models\RankTrackingKeyword;
use App\Models\RankTrackingSnapshot;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\KeywordMetricsService;
use App\Services\KeywordValueCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 * No data from other tenants is ever surfaced — every source is either
 * scoped to $websiteId or user-owned (rank tracker keywords).
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
            $data['metric'] = app(KeywordMetricsService::class)->metricsFor($this->query, 'global');
            $data['gsc_totals'] = $this->gscTotals();
            $data['gsc_daily'] = $this->gscDaily();
            $data['top_pages'] = $this->topPages();
            $data['countries'] = $this->countries();
            $data['devices'] = $this->devices();
            $data['tracker'] = $this->trackedKeyword();
            if ($data['tracker']) {
                $data['tracker_latest_snapshot'] = $this->latestSnapshot($data['tracker']->id);
                if ($data['tracker_latest_snapshot']) {
                    $data['related_searches'] = $this->extractSnapshotList($data['tracker_latest_snapshot'], 'related_searches');
                    $data['paa'] = $this->extractSnapshotList($data['tracker_latest_snapshot'], 'people_also_ask');
                }
            }

            $data['flags'] = $this->opportunityFlags($data['gsc_totals'], $data['top_pages']);
            $data['projections'] = $this->projections($data['metric'], $data['gsc_totals']);
        }

        return view('livewire.keywords.keyword-detail', $data);
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float, position: float, window_days: int}|null
     */
    private function gscTotals(): ?array
    {
        $row = SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('query', $this->query)
            ->whereDate('date', '>=', Carbon::now()->subDays(27)->toDateString())
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
            ->first();

        if (! $row || $row->impressions === null) {
            return null;
        }

        $impr = (int) $row->impressions;

        return [
            'clicks' => (int) $row->clicks,
            'impressions' => $impr,
            'ctr' => $impr > 0 ? round(((int) $row->clicks) / $impr * 100, 2) : 0.0,
            'position' => round((float) $row->position, 1),
            'window_days' => 28,
        ];
    }

    /**
     * Daily clicks + impressions + position for the last 90 days.
     *
     * @return list<array{date: string, clicks: int, impressions: int, position: float}>
     */
    private function gscDaily(): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('query', $this->query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date instanceof \Carbon\CarbonInterface ? $r->date->toDateString() : (string) $r->date,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    /**
     * @return list<array{page: string, clicks: int, impressions: int, position: float, ctr: float}>
     */
    private function topPages(): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('query', $this->query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('page', '!=', '')
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
            ->groupBy('page')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'page' => (string) $r->page,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
                'ctr' => round((float) $r->ctr * 100, 2),
            ])
            ->all();
    }

    /**
     * @return list<array{country: string, clicks: int, impressions: int, position: float}>
     */
    private function countries(): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('query', $this->query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('country', '!=', '')
            ->selectRaw('country, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('country')
            ->orderByDesc('impressions')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'country' => (string) $r->country,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    /**
     * @return list<array{device: string, clicks: int, impressions: int, position: float}>
     */
    private function devices(): array
    {
        return SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('query', $this->query)
            ->whereDate('date', '>=', Carbon::now()->subDays(89)->toDateString())
            ->where('device', '!=', '')
            ->selectRaw('device, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->groupBy('device')
            ->orderByDesc('impressions')
            ->get()
            ->map(fn ($r) => [
                'device' => (string) $r->device,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => round((float) $r->position, 1),
            ])
            ->all();
    }

    private function trackedKeyword(): ?RankTrackingKeyword
    {
        return RankTrackingKeyword::query()
            ->where('website_id', $this->websiteId)
            ->where('keyword_hash', RankTrackingKeyword::hashKeyword($this->query))
            ->orderByDesc('id')
            ->first();
    }

    private function latestSnapshot(string $keywordId): ?RankTrackingSnapshot
    {
        return RankTrackingSnapshot::query()
            ->where('rank_tracking_keyword_id', $keywordId)
            ->orderByDesc('checked_at')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractSnapshotList(RankTrackingSnapshot $snapshot, string $attr): array
    {
        $list = $snapshot->{$attr} ?? [];
        if (! is_array($list)) {
            return [];
        }

        return array_slice(array_values(array_filter($list, 'is_array')), 0, 10);
    }

    /**
     * @param  array<string, mixed>|null  $totals
     * @param  list<array<string, mixed>>  $topPages
     * @return array{striking_distance: bool, cannibalized: bool, quick_win: bool}
     */
    private function opportunityFlags(?array $totals, array $topPages): array
    {
        $flags = ['striking_distance' => false, 'cannibalized' => false, 'quick_win' => false];

        if ($totals && $totals['impressions'] >= 200 && $totals['position'] >= 5 && $totals['position'] <= 20) {
            $flags['striking_distance'] = true;
        }

        // Cannibalized: 2+ pages with non-negligible share.
        if (count(array_filter($topPages, fn ($p) => $p['impressions'] >= 20)) >= 2) {
            $flags['cannibalized'] = true;
        }

        if ($totals && $totals['position'] > 10) {
            // Matches the quick-wins gate.
            $flags['quick_win'] = true;
        }

        return $flags;
    }

    /**
     * Dollar projections (volume x CTR x CPC) were removed from the UI
     * 2026-07-07 — bucketed volumes on generic head terms produced absurd
     * "$5.8M/mo" figures. Only the CTR-curve click projection remains.
     *
     * @param  array<string, mixed>|null  $totals
     * @return array{projected_clicks: ?int}
     */
    private function projections(?KeywordMetric $metric, ?array $totals): array
    {
        if (! $metric) {
            return ['projected_clicks' => null];
        }

        $position = $totals['position'] ?? null;

        return [
            'projected_clicks' => KeywordValueCalculator::projectedMonthlyClicks(
                $metric->search_volume,
                $position !== null ? (float) $position : null,
            ),
        ];
    }
}
