<?php

namespace App\Livewire\RankTracking;

use App\Jobs\TrackKeywordRankJob;
use App\Models\RankTrackingKeyword;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\KeywordMetricsService;
use App\Services\SerpFeatureRiskService;
use App\Services\Usage\UsageMeter;
use App\Support\RankTrackerConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class RankTrackingManager extends Component
{
    use WithPagination;

    public ?string $websiteId = null;

    public bool $showForm = false;
    public string $search = '';
    public string $sortBy = 'current_position';
    public string $sortDir = 'asc';
    public string $filterDevice = '';
    public string $filterCountry = '';
    public string $filterType = '';
    public string $filterStatus = '';

    public string $newKeyword = '';
    public string $newTargetDomain = '';
    /** Path on the connected domain (e.g. /blog/post) — combined with target domain on save. */
    public string $newTargetUrlPath = '';
    public string $newSearchEngine = 'google';
    public string $newSearchType = 'organic';
    public string $newCountry = 'us';
    public string $newLanguage = 'en';
    public string $newDevice = 'desktop';
    public bool $newAutocorrect = true;
    public bool $newSafeSearch = false;
    public string $newCompetitors = '';
    public string $newNotes = '';

    // Bulk-add panel state — populated from existing GSC traffic so users can
    // import their best-performing organic queries with one click.
    public bool $showBulkAdd = false;
    public string $bulkCountry = 'US';                  // GSC alpha-3 (uppercased) — set on first open
    public string $bulkLanguage = 'en';
    public string $bulkDevice = 'desktop';
    public int $bulkLookbackDays = 90;
    public int $bulkMinImpressions = 100;
    public int $bulkMaxPosition = 30;                   // 1..100 — only queries we already rank for
    public int $bulkLimit = 50;
    /** @var array<int, string> */
    public array $bulkSelected = [];                    // chosen keyword strings
    public string $bulkStatus = '';                     // success/error message after submit

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->prefillTargetDomain();
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->prefillTargetDomain();
        $this->resetPage();
    }

    private function prefillTargetDomain(): void
    {
        if (($this->websiteId === null || $this->websiteId === '')) {
            return;
        }
        $website = Website::find($this->websiteId);
        if ($website && (string) $website->domain !== '') {
            $this->newTargetDomain = (string) $website->domain;
        }
    }

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;
    }

    /**
     * Open / close the bulk-add panel. On open, seed the country dropdown
     * with the website's top-traffic country (so the typical workflow doesn't
     * need any input).
     */
    public function toggleBulkAdd(): void
    {
        $this->showBulkAdd = ! $this->showBulkAdd;
        $this->bulkStatus = '';

        if ($this->showBulkAdd && ($this->websiteId !== null && $this->websiteId !== '')) {
            $top = SearchConsoleData::query()
                ->where('website_id', $this->websiteId)
                ->where('country', '!=', '')
                ->selectRaw('country, SUM(clicks) as clicks')
                ->groupBy('country')
                ->orderByDesc('clicks')
                ->limit(1)
                ->value('country');
            if (is_string($top) && $top !== '') {
                $this->bulkCountry = strtoupper($top);
            }
        }
    }

    public function updatedBulkCountry(): void { $this->bulkSelected = []; }
    public function updatedBulkMinImpressions(): void { $this->bulkSelected = []; }
    public function updatedBulkMaxPosition(): void { $this->bulkSelected = []; }
    public function updatedBulkLookbackDays(): void { $this->bulkSelected = []; }
    public function updatedBulkLimit(): void { $this->bulkSelected = []; }

    /**
     * Mark every visible candidate as selected (or clear them all).
     */
    public function bulkSelectAll(bool $select): void
    {
        if (! $select) {
            $this->bulkSelected = [];

            return;
        }
        $this->bulkSelected = array_values(array_map(fn (array $r) => (string) $r['query'], $this->bulkCandidates()));
    }

    /**
     * Candidate keywords pulled from this website's own GSC history.
     * Filters: country, ≥ min impressions, best position ≤ maxPosition.
     * Excludes anything already tracked.
     *
     * @return list<array{query: string, impressions: int, clicks: int, position: float, ctr: float}>
     */
    public function bulkCandidates(): array
    {
        if (($this->websiteId === null || $this->websiteId === '') || ! Auth::user()?->canViewWebsiteId($this->websiteId)) {
            return [];
        }

        $country = strtoupper(trim($this->bulkCountry));
        $since = Carbon::now()->subDays(max(7, min(365, $this->bulkLookbackDays)))->toDateString();
        $minImpr = max(1, $this->bulkMinImpressions);
        $maxPos = max(1, min(100, $this->bulkMaxPosition));
        $limit = max(1, min(500, $this->bulkLimit));

        $rows = SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->where('date', '>=', $since)
            ->where('query', '!=', '')
            ->when($country !== '', fn ($q) => $q->where('country', $country))
            ->selectRaw('query, SUM(impressions) as impressions, SUM(clicks) as clicks, AVG(position) as position, AVG(ctr) as ctr')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ?', [$minImpr])
            ->havingRaw('AVG(position) <= ?', [$maxPos])
            ->orderByDesc('impressions')
            ->limit($limit * 2)  // overshoot, then prune already-tracked
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Skip keywords already tracked for this website + country + device.
        $tracked = RankTrackingKeyword::query()
            ->where('website_id', $this->websiteId)
            ->where('country', strtolower($country))
            ->where('device', $this->bulkDevice)
            ->whereIn(DB::raw('LOWER(keyword)'), $rows->pluck('query')->map(fn ($q) => mb_strtolower((string) $q))->all())
            ->pluck('keyword')
            ->map(fn ($k) => mb_strtolower((string) $k))
            ->flip();

        $out = [];
        foreach ($rows as $r) {
            $kw = (string) $r->query;
            if (isset($tracked[mb_strtolower($kw)])) {
                continue;
            }
            $out[] = [
                'query' => $kw,
                'impressions' => (int) $r->impressions,
                'clicks' => (int) $r->clicks,
                'position' => round((float) $r->position, 1),
                'ctr' => round((float) $r->ctr * 100, 2),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Create RankTrackingKeyword rows for every selected candidate. Inherits
     * the bulk-panel locale settings; queues the first SERP check immediately.
     */
    public function bulkAddSelected(): void
    {
        $user = Auth::user();
        if (! $user || ($this->websiteId === null || $this->websiteId === '') || ! $user->canViewWebsiteId($this->websiteId)) {
            $this->bulkStatus = 'Pick a website first.';

            return;
        }

        $picked = array_values(array_filter(array_unique(array_map('strval', $this->bulkSelected)), fn ($s) => trim($s) !== ''));
        if ($picked === []) {
            $this->bulkStatus = 'No keywords selected.';

            return;
        }

        $website = Website::find($this->websiteId);
        $domain = $website && (string) $website->domain !== '' ? (string) $website->domain : $this->newTargetDomain;
        if ($domain === '') {
            $this->bulkStatus = 'Set a target domain on the website first.';

            return;
        }

        $country = strtolower(trim($this->bulkCountry)) ?: 'us';
        $language = strtolower(trim($this->bulkLanguage)) ?: 'en';
        $device = in_array($this->bulkDevice, ['desktop', 'mobile'], true) ? $this->bulkDevice : 'desktop';

        // Plan cap: count slots remaining before partial-accepting. Bill
        // the website owner, not necessarily the actor (team members may
        // add keywords but the owner's plan caps them).
        $billedUser = $website?->owner ?? $user;
        $meter = app(UsageMeter::class);
        $cap = $meter->rankTrackerCap($billedUser);
        $remaining = $cap === null ? PHP_INT_MAX : max(0, $cap - $meter->activeTrackedKeywordCount($billedUser));

        $created = 0;
        $skipped = 0;
        $capBlocked = 0;
        foreach ($picked as $kw) {
            $kw = trim($kw);
            if ($kw === '') {
                continue;
            }

            // If this exact tracking-key already exists we won't burn a
            // slot — updateOrCreate will just refresh it. Otherwise we
            // need a free slot before creating.
            $existing = RankTrackingKeyword::query()
                ->where('website_id', $this->websiteId)
                ->where('keyword_hash', RankTrackingKeyword::hashKeyword($kw))
                ->where('search_engine', 'google')
                ->where('search_type', 'organic')
                ->where('country', $country)
                ->where('language', $language)
                ->where('device', $device)
                ->whereNull('location')
                ->first();
            if ($existing === null) {
                if ($remaining <= 0) {
                    $capBlocked++;
                    continue;
                }
                $remaining--;
            }

            $row = RankTrackingKeyword::updateOrCreate(
                [
                    'website_id' => $this->websiteId,
                    'keyword_hash' => RankTrackingKeyword::hashKeyword($kw),
                    'search_engine' => 'google',
                    'search_type' => 'organic',
                    'country' => $country,
                    'language' => $language,
                    'device' => $device,
                    'location' => null,
                ],
                [
                    'user_id' => $user->id,
                    'keyword' => $kw,
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
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->bulkSelected = [];
        $parts = [sprintf('%d keyword(s) added.', $created)];
        if ($skipped > 0) {
            $parts[] = "{$skipped} were already tracked and skipped.";
        }
        if ($capBlocked > 0) {
            $parts[] = "{$capBlocked} skipped — plan cap of {$cap} active tracked keywords reached. Pause or remove keywords to free slots, or upgrade your plan.";
        }
        $this->bulkStatus = implode(' ', $parts);
        session()->flash('rank_tracking_status', $this->bulkStatus);
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDevice(): void { $this->resetPage(); }
    public function updatedFilterCountry(): void { $this->resetPage(); }
    public function updatedFilterType(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filterDevice', 'filterCountry', 'filterType', 'filterStatus']);
        $this->resetPage();
    }

    public function addKeyword(): void
    {
        $this->validate([
            'newKeyword' => 'required|string|min:1|max:500',
            'newTargetDomain' => 'required|string|max:2048',
            'newSearchEngine' => 'required|in:google',
            'newSearchType' => 'required|in:organic,news,images,videos,shopping,maps,scholar',
            'newCountry' => 'required|string|size:2',
            'newLanguage' => 'required|string|min:2|max:10',
            'newDevice' => 'required|in:desktop,mobile',
        ]);

        $user = Auth::user();
        if (! $user || ($this->websiteId === null || $this->websiteId === '') || ! $user->canViewWebsiteId($this->websiteId)) {
            $this->addError('newKeyword', 'Select a website first.');

            return;
        }

        $competitors = collect(explode(',', $this->newCompetitors))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values()
            ->all();

        // Single URL input → host + optional page path.
        $target = RankTrackerConfig::parseTarget($this->newTargetDomain);
        if ($target['host'] === '') {
            $this->addError('newTargetDomain', 'Enter a valid website URL, e.g. example.com or example.com/page.');

            return;
        }
        $targetHost = $target['host'];
        $targetUrl = $target['url'];

        // Plan cap on active tracked keywords. Only blocks brand-new
        // rows — re-saving an already-tracked combination is treated as
        // an edit and doesn't consume a slot.
        $website = Website::find($this->websiteId);
        $billedUser = $website?->owner ?? $user;
        $meter = app(UsageMeter::class);
        $cap = $meter->rankTrackerCap($billedUser);
        if ($cap !== null) {
            $existing = RankTrackingKeyword::query()
                ->where('website_id', $this->websiteId)
                ->where('keyword_hash', RankTrackingKeyword::hashKeyword($this->newKeyword))
                ->where('search_engine', $this->newSearchEngine)
                ->where('search_type', $this->newSearchType)
                ->where('country', strtolower($this->newCountry))
                ->where('language', strtolower($this->newLanguage))
                ->where('device', $this->newDevice)
                ->whereNull('location')
                ->first();
            if ($existing === null && $meter->activeTrackedKeywordCount($billedUser) >= $cap) {
                $this->addError(
                    'newKeyword',
                    "You're at your plan's limit of {$cap} active tracked keywords. Pause or remove keywords to add new ones, or upgrade your plan."
                );
                return;
            }
        }

        $keyword = RankTrackingKeyword::updateOrCreate(
            [
                'website_id' => $this->websiteId,
                'keyword_hash' => RankTrackingKeyword::hashKeyword($this->newKeyword),
                'search_engine' => $this->newSearchEngine,
                'search_type' => $this->newSearchType,
                'country' => strtolower($this->newCountry),
                'language' => strtolower($this->newLanguage),
                'device' => $this->newDevice,
                'location' => null,
            ],
            [
                'user_id' => $user->id,
                'keyword' => trim($this->newKeyword),
                'target_domain' => $targetHost,
                'target_url' => $targetUrl,
                'depth' => RankTrackerConfig::DEFAULT_DEPTH,
                'tbs' => null,
                'autocorrect' => $this->newAutocorrect,
                'safe_search' => $this->newSafeSearch,
                'competitors' => $competitors,
                'tags' => [],
                'notes' => $this->newNotes ?: null,
                'check_interval_hours' => RankTrackerConfig::checkIntervalHours(),
                'is_active' => true,
                'next_check_at' => Carbon::now(),
            ]
        );

        TrackKeywordRankJob::dispatch($keyword->id, $keyword->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);

        $this->reset([
            'newKeyword',
            'newTargetUrlPath',
            'newCompetitors',
            'newNotes',
            'showForm',
        ]);

        session()->flash('rank_tracking_status', 'Keyword added and initial check queued.');
    }

    public function recheck(string $keywordId): void
    {
        $keyword = RankTrackingKeyword::find($keywordId);
        if (! $keyword || ! $this->authorizes($keyword)) {
            return;
        }

        TrackKeywordRankJob::dispatch($keyword->id, $keyword->website_id, true)->onQueue(\App\Support\Queues::INTERACTIVE);

        $keyword->forceFill([
            'last_status' => 'queued',
            'next_check_at' => Carbon::now(),
        ])->save();

        session()->flash('rank_tracking_status', 'Re-check queued for "'.$keyword->keyword.'".');
    }

    public function togglePause(string $keywordId): void
    {
        $keyword = RankTrackingKeyword::find($keywordId);
        if (! $keyword || ! $this->authorizes($keyword)) {
            return;
        }

        $keyword->is_active = ! $keyword->is_active;
        $keyword->save();
    }

    public function delete(string $keywordId): void
    {
        $keyword = RankTrackingKeyword::find($keywordId);
        if (! $keyword || ! $this->authorizes($keyword)) {
            return;
        }
        $keyword->delete();
    }

    private function authorizes(RankTrackingKeyword $keyword): bool
    {
        $user = Auth::user();

        return $user !== null && $user->canViewWebsiteId((string) $keyword->website_id);
    }

    public function render()
    {
        $rows = collect();
        $stats = [
            'total' => 0,
            'top3' => 0,
            'top10' => 0,
            'top100' => 0,
            'avg' => null,
            'unranked' => 0,
            'active' => 0,
        ];

        $allowed = ['keyword', 'current_position', 'best_position', 'position_change', 'last_checked_at', 'country', 'device'];
        $sortBy = in_array($this->sortBy, $allowed, true) ? $this->sortBy : 'current_position';

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $base = RankTrackingKeyword::query()
                ->where('website_id', $this->websiteId);

            $stats['total'] = (clone $base)->count();
            $stats['active'] = (clone $base)->where('is_active', true)->count();
            $stats['top3'] = (clone $base)->whereBetween('current_position', [1, 3])->count();
            $stats['top10'] = (clone $base)->whereBetween('current_position', [1, 10])->count();
            $stats['top100'] = (clone $base)->whereNotNull('current_position')->count();
            $stats['unranked'] = (clone $base)->whereNull('current_position')->count();
            $avgVal = (clone $base)->whereNotNull('current_position')->avg('current_position');
            $stats['avg'] = $avgVal !== null ? round((float) $avgVal, 1) : null;

            $filtered = (clone $base)
                ->when($this->search, fn ($q) => $q->where('keyword', 'like', '%'.$this->search.'%'))
                ->when($this->filterDevice, fn ($q) => $q->where('device', $this->filterDevice))
                ->when($this->filterCountry, fn ($q) => $q->where('country', $this->filterCountry))
                ->when($this->filterType, fn ($q) => $q->where('search_type', $this->filterType))
                ->when($this->filterStatus === 'top3', fn ($q) => $q->whereBetween('current_position', [1, 3]))
                ->when($this->filterStatus === 'top10', fn ($q) => $q->whereBetween('current_position', [1, 10]))
                ->when($this->filterStatus === 'top100', fn ($q) => $q->whereNotNull('current_position'))
                ->when($this->filterStatus === 'unranked', fn ($q) => $q->whereNull('current_position'))
                ->when($this->filterStatus === 'active', fn ($q) => $q->where('is_active', true))
                ->when($this->filterStatus === 'paused', fn ($q) => $q->where('is_active', false))
                ->when($this->filterStatus === 'failed', fn ($q) => $q->where('last_status', 'failed'));

            $rows = $filtered
                ->orderByRaw("CASE WHEN {$sortBy} IS NULL THEN 1 ELSE 0 END")
                ->orderBy($sortBy, $this->sortDir)
                ->paginate(25);
        }

        $gscByKeyword = [];
        if ($rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $rows->isNotEmpty()) {
            $keywords = $rows->getCollection()->pluck('keyword')->map(fn ($k) => mb_strtolower(trim((string) $k)))->unique()->values()->all();
            $since = Carbon::now()->subDays(30)->toDateString();

            if (! empty($keywords) && $this->websiteId) {
                $gscRows = SearchConsoleData::query()
                    ->where('website_id', $this->websiteId)
                    ->where('date', '>=', $since)
                    ->whereIn(DB::raw('LOWER(`query`)'), $keywords)
                    ->when($this->filterCountry !== '', fn ($q) => $q->where('country', strtoupper($this->filterCountry)))
                    ->selectRaw('LOWER(`query`) as q, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
                    ->groupBy('q')
                    ->get();

                foreach ($gscRows as $g) {
                    $gscByKeyword[(string) $g->q] = [
                        'clicks' => (int) $g->clicks,
                        'impressions' => (int) $g->impressions,
                        'position' => $g->position !== null ? round((float) $g->position, 1) : null,
                    ];
                }
            }
        }

        $serpRisk = [];
        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $serpRisk = app(SerpFeatureRiskService::class)->riskMapForWebsite($this->websiteId);
        }

        $keMetrics = [];
        $detectedLanguages = [];
        if ($rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $rows->isNotEmpty()) {
            $keywordList = $rows->getCollection()->pluck('keyword')->map(fn ($k) => (string) $k)->unique()->values()->all();
            if ($keywordList !== []) {
                $keMetrics = app(KeywordMetricsService::class)->metricsOrQueue(
                    $keywordList,
                    'global',
                    websiteId: $this->websiteId ?: null,
                    ownerUserId: \Illuminate\Support\Facades\Auth::id(),
                );
                $detectedLanguages = app(\App\Services\LanguageDetectorService::class)->detectMany($keywordList);
            }
        }

        return view('livewire.rank-tracking.rank-tracking-manager', [
            'rows' => $rows,
            'stats' => $stats,
            'gscByKeyword' => $gscByKeyword,
            'serpRisk' => $serpRisk,
            'countries' => $this->countries(),
            'languages' => $this->languages(),
            'keMetrics' => $keMetrics,
            'detectedLanguages' => $detectedLanguages,
            'targetUrlPrefix' => RankTrackerConfig::targetUrlPrefix($this->newTargetDomain),
            'defaultCheckIntervalHours' => RankTrackerConfig::checkIntervalHours(),
        ]);
    }

    /** @return array<string, string> */
    private function countries(): array
    {
        return [
            'us' => 'United States', 'gb' => 'United Kingdom', 'ca' => 'Canada', 'au' => 'Australia',
            'de' => 'Germany', 'fr' => 'France', 'es' => 'Spain', 'it' => 'Italy',
            'nl' => 'Netherlands', 'se' => 'Sweden', 'no' => 'Norway', 'dk' => 'Denmark',
            'in' => 'India', 'pk' => 'Pakistan', 'bd' => 'Bangladesh', 'ae' => 'UAE',
            'sa' => 'Saudi Arabia', 'sg' => 'Singapore', 'my' => 'Malaysia', 'id' => 'Indonesia',
            'jp' => 'Japan', 'kr' => 'South Korea', 'cn' => 'China', 'hk' => 'Hong Kong',
            'br' => 'Brazil', 'mx' => 'Mexico', 'ar' => 'Argentina',
            'za' => 'South Africa', 'ng' => 'Nigeria', 'eg' => 'Egypt',
            'tr' => 'Turkey', 'ru' => 'Russia', 'pl' => 'Poland', 'nz' => 'New Zealand',
        ];
    }

    /** @return array<string, string> */
    private function languages(): array
    {
        return [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'sv' => 'Swedish',
            'ar' => 'Arabic', 'ur' => 'Urdu', 'hi' => 'Hindi', 'bn' => 'Bengali',
            'ja' => 'Japanese', 'ko' => 'Korean', 'zh-cn' => 'Chinese (Simplified)', 'zh-tw' => 'Chinese (Traditional)',
            'ru' => 'Russian', 'tr' => 'Turkish', 'pl' => 'Polish', 'id' => 'Indonesian',
            'ms' => 'Malay', 'th' => 'Thai', 'vi' => 'Vietnamese',
        ];
    }
}
