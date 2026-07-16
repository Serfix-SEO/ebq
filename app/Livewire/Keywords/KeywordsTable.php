<?php

namespace App\Livewire\Keywords;

use App\Models\KeywordMetric;
use App\Models\RankTrackingKeyword;
use App\Models\SearchConsoleData;
use App\Services\KeywordMetricsService;
use App\Services\ReportDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class KeywordsTable extends Component
{
    use WithPagination;

    public ?string $websiteId = null;
    public string $search = '';
    public string $view = 'aggregated';
    public string $sortBy = 'clicks';
    public string $sortDir = 'desc';
    public ?string $device = null;
    public ?string $from = null;
    public ?string $to = null;

    #[Url(as: 'country', history: true)]
    public string $country = '';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->country = '';
        $this->resetPage();
    }

    #[On('country-changed')]
    public function onCountryChanged(string $country): void
    {
        $this->country = $country;
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedView(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $rows = collect();

        $allowedAggregated = ['query', 'clicks', 'impressions', 'ctr', 'position'];
        $allowedDaily = ['date', 'query', 'clicks', 'impressions', 'ctr', 'position'];
        $allowed = $this->view === 'daily' ? $allowedDaily : $allowedAggregated;
        $sortBy = in_array($this->sortBy, $allowed) ? $this->sortBy : 'clicks';

        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $base = SearchConsoleData::query()
                ->where('website_id', $this->websiteId)
                ->forDateRange($this->from, $this->to)
                ->when($this->search, fn ($q) => $q->where('query', 'like', "%{$this->search}%"))
                ->when($this->device, fn ($q) => $q->where('device', $this->device))
                ->when($this->country !== '', fn ($q) => $q->where('country', strtoupper($this->country)));

            if ($this->view === 'daily') {
                $rows = (clone $base)
                    ->select('date', 'query', 'clicks', 'impressions', 'ctr', 'position')
                    ->orderBy($sortBy, $this->sortDir)
                    ->simplePaginate(25);
            } else {
                // simplePaginate (no total COUNT): a COUNT over GROUP BY query is a
                // full aggregation (~17s on high-volume sites) and would time the
                // page out — next/prev is enough for a 30k-keyword list.
                $rows = (clone $base)
                    ->select(
                        'query',
                        DB::raw('SUM(clicks) as clicks'),
                        DB::raw('SUM(impressions) as impressions'),
                        DB::raw('AVG(ctr) as ctr'),
                        DB::raw('AVG(position) as position'),
                    )
                    ->groupBy('query')
                    ->orderBy($sortBy, $this->sortDir)
                    ->simplePaginate(25);
            }
        }

        $cannibalized = [];
        $tracked = [];
        if ($this->websiteId && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $cannibalized = array_flip(
                array_map(
                    fn (array $r) => mb_strtolower((string) $r['query']),
                    app(ReportDataService::class)->cannibalizationReport($this->websiteId, null, null, 500, $this->country !== '' ? strtoupper($this->country) : null, cacheOnly: true),
                )
            );
            $tracked = array_flip(
                RankTrackingKeyword::query()
                    ->where('website_id', $this->websiteId)
                    ->pluck('keyword')
                    ->map(fn ($k) => mb_strtolower((string) $k))
                    ->all()
            );
        }

        $keMetrics = [];
        $languages = [];
        if ($rows instanceof \Illuminate\Contracts\Pagination\Paginator && $rows->isNotEmpty()) {
            $queries = $rows->getCollection()
                ->pluck('query')
                ->map(fn ($q) => (string) $q)
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($queries !== []) {
                $keMetrics = app(KeywordMetricsService::class)->metricsOrQueue(
                    $queries,
                    'global',
                    websiteId: $this->websiteId ?: null,
                    ownerUserId: Auth::id(),
                );
                $languages = app(\App\Services\LanguageDetectorService::class)->detectMany($queries);
            }
        }

        // Fallback keyword suggestions (Site Explorer enrichment: keyword-server
        // + competitor opportunities) when this site has no usable GSC data —
        // not connected, no rows, or only scrap auth/nav queries. Mirrors the
        // report so /keywords is never a dead end. Only on the unfiltered first
        // page, so filtering/sorting a real list never triggers it.
        $suggestions = null;
        if ($this->websiteId
            && $this->search === ''
            && ($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->currentPage() === 1 : true)
            && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $website = \App\Models\Website::find($this->websiteId);
            if ($website !== null) {
                $topQueries = $rows instanceof \Illuminate\Contracts\Pagination\Paginator
                    ? $rows->getCollection()->pluck('query')->map(fn ($q) => (string) $q)->all()
                    : [];
                $needsFallback = $topQueries === []
                    || \App\Support\KeywordJunkHeuristic::mostlyJunk($topQueries, (string) $website->domain);
                if ($needsFallback) {
                    $suggestions = app(\App\Services\Keywords\WebsiteKeywordSuggestions::class)->for($website);
                }
            }
        }

        return view('livewire.keywords.keywords-table', compact('rows', 'cannibalized', 'tracked', 'keMetrics', 'languages', 'suggestions'));
    }
}
