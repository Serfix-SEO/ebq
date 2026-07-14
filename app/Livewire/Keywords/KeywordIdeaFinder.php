<?php

namespace App\Livewire\Keywords;

use App\Livewire\Keywords\Concerns\TracksKeyword;
use App\Models\KeywordApiRequest;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\KeywordFinder\KeywordFinderPool;
use App\Services\KeywordFinder\KeywordIdeasMonthlyCache;
use App\Services\KeywordResearch\AiKeywordClusterService;
use App\Services\KeywordResearch\KeywordIntentClassifier;
use App\Services\KeywordResearch\KeywordTermGrouper;
use App\Support\KeywordFinderLocations;
use App\Support\KeywordProviderConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * In-portal keyword discovery, powered by the self-hosted Keyword Planner API.
 * Two modes: expand seed keywords, or derive keywords from a website/page URL.
 *
 * The provider is asynchronous: {@see run} dispatches via {@see KeywordFinderPool}
 * (which creates a {@see KeywordApiRequest}), then the view polls {@see poll}
 * until the server posts results back to the webhook and the row completes.
 */
class KeywordIdeaFinder extends Component
{
    use TracksKeyword;

    /** Handoff payload from the research hub: {keywords: string[], mode?: string}. */
    public ?array $preset = null;

    /** 'seeds' | 'website' */
    public string $mode = 'seeds';

    /** Newline/comma-separated seed keywords. */
    public string $seedsInput = '';

    public string $url = '';

    /** 'site' | 'page' */
    public string $scope = 'site';

    public string $location = 'United States';

    public string $language = 'English';

    public ?string $requestId = null;

    public string $status = '';

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public ?string $errorMessage = null;

    public bool $hasRun = false;

    /** True when the current results came straight from the monthly shared cache. */
    public bool $fromCache = false;

    /**
     * Set by run() right before dispatching, consumed by poll() once the
     * server's result lands — the monthly cache key this lookup should warm
     * on success. Null whenever this run was itself served from cache (no
     * dispatch happened, nothing new to write back).
     */
    public ?string $pendingCacheKey = null;

    // ── Results table state (sort / filter / paginate) ──────────────────────
    /** Sort column: keyword | volume | competitionIndex | cpc. */
    public string $sortField = 'volume';

    /** asc | desc */
    public string $sortDir = 'desc';

    /** Free-text "keyword contains" filter. */
    public string $filterText = '';

    public ?int $minVolume = null;

    public ?int $maxVolume = null;

    /** Competition filter: all | low | medium | high. */
    public string $comp = 'all';

    /** Free-text "keyword does NOT contain" filter. */
    public string $excludeText = '';

    /** Only question-style keywords (how/what/why/…). */
    public bool $questionsOnly = false;

    /** Intent filter: all | informational | commercial | transactional | navigational | other. */
    public string $intent = 'all';

    /** Active term-group filter from the Groups rail ('' = all keywords). */
    public string $groupTerm = '';

    public int $perPage = 25;

    public int $page = 1;

    // ── Selection & bulk actions ─────────────────────────────────────────────
    /** @var list<string> selected keywords (lowercased) */
    public array $selected = [];

    // ── AI clustering ────────────────────────────────────────────────────────
    /** 'list' | 'clusters' */
    public string $viewMode = 'list';

    /** keyword(lowercased) => cluster label, set by clusterWithAi(). */
    public ?array $clusterMap = null;

    public ?string $clusterError = null;

    /** Stable identity of the current result set (the monthly-cache key). */
    public ?string $resultSetKey = null;

    private const MAX_SEEDS = 20;

    private const MAX_TRACK_BATCH = 50;

    /**
     * Memoized normalizeRow() output for the current $results, keyed on the
     * results array itself (spl_object-free — array identity via count+hash
     * would be overkill; a single-request instance property is enough since
     * results only change via run()/poll(), which reset this cache below).
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $normalizedCache = null;

    /** Prefill + auto-run from a research-hub handoff (seed keywords). */
    public function mount(): void
    {
        $seeds = $this->preset['keywords'] ?? [];
        if (is_array($seeds) && $seeds !== []) {
            $this->mode = 'seeds';
            $this->seedsInput = implode("\n", $seeds);
            $this->run(app(KeywordFinderPool::class));
        }
    }

    /** Reset to the first page whenever a filter/page-size changes. */
    public function updated(string $name): void
    {
        if (in_array($name, ['filterText', 'excludeText', 'minVolume', 'maxVolume', 'comp', 'questionsOnly', 'intent', 'perPage'], true)) {
            $this->page = 1;
        }
    }

    /** Pick (or clear) a term group from the Groups rail. */
    public function setGroup(string $term): void
    {
        $this->groupTerm = $this->groupTerm === $term ? '' : $term;
        $this->page = 1;
    }

    public function setViewMode(string $mode): void
    {
        if (in_array($mode, ['list', 'clusters'], true)) {
            $this->viewMode = $mode === 'clusters' && $this->clusterMap === null ? 'list' : $mode;
        }
    }

    /** Toggle/choose the sort column (numeric columns default to descending). */
    public function sortBy(string $field): void
    {
        if (! in_array($field, ['keyword', 'volume', 'competitionIndex', 'cpc'], true)) {
            return;
        }
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = $field === 'keyword' ? 'asc' : 'desc';
        }
        $this->page = 1;
    }

    public function setPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function run(KeywordFinderPool $pool): void
    {
        $this->reset([
            'requestId', 'status', 'results', 'errorMessage', 'page', 'pendingCacheKey',
            'selected', 'clusterMap', 'clusterError', 'viewMode', 'groupTerm', 'resultSetKey',
        ]);
        $this->hasRun = false;
        $this->fromCache = false;

        if (! KeywordProviderConfig::usingKeywordFinder()) {
            $this->errorMessage = 'Keyword discovery requires the self-hosted Keyword Planner provider, which is not currently enabled.';

            return;
        }

        $opts = [
            'location' => $this->location,
            'language' => $this->language,
        ];

        if ($this->mode === 'website') {
            $url = trim($this->url);
            if ($url === '') {
                $this->errorMessage = 'Enter a website or page URL.';

                return;
            }
            $opts['url'] = $url;
            $opts['scope'] = $this->scope === 'page' ? 'page' : 'site';
        } else {
            $seeds = $this->parseSeeds($this->seedsInput);
            if ($seeds === []) {
                $this->errorMessage = 'Enter at least one seed keyword.';

                return;
            }
            if (count($seeds) > self::MAX_SEEDS) {
                $this->errorMessage = 'Please enter at most '.self::MAX_SEEDS.' seed keywords.';

                return;
            }
            $opts['seeds'] = $seeds;
        }

        // Same seeds/URL + location/language, looked up by anyone this
        // calendar month, get the same answer instantly — no queue, no node
        // load, no wait. The key embeds Y-m, so it's automatically a miss
        // again once the month turns over.
        [$mode, $normalizedPayload] = $pool->buildIdeasPayload($opts);
        $cacheKey = KeywordIdeasMonthlyCache::key($mode, $normalizedPayload);
        $this->resultSetKey = $cacheKey;
        $cached = KeywordIdeasMonthlyCache::get($cacheKey);
        if ($cached !== null) {
            $this->results = $cached;
            $this->hasRun = true;
            $this->fromCache = true;
            $this->status = KeywordApiRequest::STATUS_COMPLETED;

            return;
        }

        $request = $pool->dispatchIdeas($opts, userId: Auth::id());
        $this->hasRun = true;
        $this->requestId = $request->request_id;
        $this->status = $request->status;
        $this->pendingCacheKey = $cacheKey;

        if ($request->status === KeywordApiRequest::STATUS_FAILED) {
            $this->errorMessage = $request->error;
        }
    }

    /** Polled by the view while a request is in flight. */
    public function poll(): void
    {
        if ($this->requestId === null) {
            return;
        }

        $request = KeywordApiRequest::query()->where('request_id', $this->requestId)->first();
        if ($request === null) {
            return;
        }

        $this->status = $request->status;

        if (! $request->isFinished()) {
            return;
        }

        if ($request->status === KeywordApiRequest::STATUS_FAILED) {
            $this->errorMessage = $request->error ?: 'The lookup failed. Please try again.';
            $this->requestId = null;

            return;
        }

        $rows = $request->result['results'] ?? [];
        $this->results = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $this->requestId = null;

        // Warm the shared monthly cache so the next person who searches the
        // same seeds/URL this month gets this exact result, instantly.
        if ($this->pendingCacheKey !== null) {
            KeywordIdeasMonthlyCache::put($this->pendingCacheKey, $this->results);
            $this->pendingCacheKey = null;
        }
    }

    public function isPolling(): bool
    {
        return $this->requestId !== null
            && in_array($this->status, [KeywordApiRequest::STATUS_QUEUED, KeywordApiRequest::STATUS_RUNNING], true);
    }

    /** @return list<string> */
    private function parseSeeds(string $raw): array
    {
        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $seen = [];
        $out = [];
        foreach ($parts as $p) {
            $s = trim($p);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * normalizeRow() over the whole raw result set, memoized per request.
     * processedResults() calls this up to 3x per render (main rows + the
     * group-rail pass with groupTerm cleared + AI clustering) — without the
     * cache, that's the same intent-classification regex work over the full
     * set repeated 3x on every group click, which is what made it lag.
     *
     * @return list<array<string, mixed>>
     */
    private function allNormalizedRows(): array
    {
        if ($this->normalizedCache === null) {
            $this->normalizedCache = array_map(fn ($r) => $this->normalizeRow($r), array_filter($this->results, 'is_array'));
        }

        return $this->normalizedCache;
    }

    /**
     * Normalise a raw API row into a stable, sortable/filterable shape.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function normalizeRow(array $r): array
    {
        $vol = isset($r['avgMonthlySearches']) && is_numeric($r['avgMonthlySearches']) ? (int) $r['avgMonthlySearches'] : null;
        $idx = isset($r['competitionIndex']) && is_numeric($r['competitionIndex']) ? (int) $r['competitionIndex'] : null;
        $low = isset($r['lowTopOfPageBid']) && is_numeric($r['lowTopOfPageBid']) ? (float) $r['lowTopOfPageBid'] : null;
        $high = isset($r['highTopOfPageBid']) && is_numeric($r['highTopOfPageBid']) ? (float) $r['highTopOfPageBid'] : null;
        $compStr = is_string($r['competition'] ?? null) && $r['competition'] !== '' ? $r['competition'] : null;
        $level = $compStr !== null
            ? strtolower($compStr)
            : ($idx === null ? 'unknown' : ($idx < 34 ? 'low' : ($idx < 67 ? 'medium' : 'high')));

        $keyword = (string) ($r['keyword'] ?? '');

        return [
            'keyword' => $keyword,
            'volume' => $vol,
            'competitionIndex' => $idx,
            'competition' => $compStr ?? ucfirst($level),
            'comp_level' => $level,
            'low' => $low,
            'high' => $high,
            'cpc' => $high, // high top-of-page bid drives the "CPC" sort
            'intent' => KeywordIntentClassifier::classify($keyword),
            'is_question' => KeywordIntentClassifier::isQuestion($keyword),
            'cluster' => $this->clusterMap[mb_strtolower(trim($keyword))] ?? null,
        ];
    }

    /** Whole-word term match ("shoe" must not match "snowshoeing"). */
    private function keywordHasTerm(string $keyword, string $term): bool
    {
        $kw = ' '.implode(' ', preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: []).' ';

        return str_contains($kw, ' '.mb_strtolower($term).' ');
    }

    /**
     * Apply the active filters + sort to the full result set.
     *
     * @return list<array<string, mixed>>
     */
    private function processedResults(): array
    {
        $rows = $this->allNormalizedRows();

        $text = mb_strtolower(trim($this->filterText));
        $exclude = mb_strtolower(trim($this->excludeText));
        $rows = array_values(array_filter($rows, function (array $r) use ($text, $exclude): bool {
            if ($r['keyword'] === '') {
                return false;
            }
            if ($text !== '' && ! str_contains(mb_strtolower($r['keyword']), $text)) {
                return false;
            }
            if ($exclude !== '' && str_contains(mb_strtolower($r['keyword']), $exclude)) {
                return false;
            }
            if ($this->minVolume !== null && ($r['volume'] ?? 0) < $this->minVolume) {
                return false;
            }
            if ($this->maxVolume !== null && ($r['volume'] ?? 0) > $this->maxVolume) {
                return false;
            }
            if ($this->comp !== 'all' && $r['comp_level'] !== $this->comp) {
                return false;
            }
            if ($this->questionsOnly && ! $r['is_question']) {
                return false;
            }
            if ($this->intent !== 'all' && $r['intent'] !== $this->intent) {
                return false;
            }
            if ($this->groupTerm !== '' && ! $this->keywordHasTerm($r['keyword'], $this->groupTerm)) {
                return false;
            }

            return true;
        }));

        $field = $this->sortField;
        $dir = $this->sortDir === 'asc' ? 1 : -1;
        usort($rows, function (array $a, array $b) use ($field, $dir): int {
            if ($field === 'keyword') {
                return $dir * strcasecmp((string) $a['keyword'], (string) $b['keyword']);
            }

            return $dir * (($a[$field] ?? -1) <=> ($b[$field] ?? -1));
        });

        return $rows;
    }

    // ── Selection & bulk actions ─────────────────────────────────────────────

    public function toggleSelected(string $keyword): void
    {
        $key = mb_strtolower(trim($keyword));
        if ($key === '') {
            return;
        }
        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));
        } else {
            $this->selected[] = $key;
        }
    }

    /** Select/deselect every row on the current page. */
    public function toggleSelectPage(): void
    {
        $pageKeys = array_map(
            fn ($r) => mb_strtolower($r['keyword']),
            array_slice($this->processedResults(), ($this->page - 1) * $this->perPage, $this->perPage),
        );
        $allSelected = $pageKeys !== [] && array_diff($pageKeys, $this->selected) === [];
        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $pageKeys))
            : array_values(array_unique(array_merge($this->selected, $pageKeys)));
    }

    public function clearSelected(): void
    {
        $this->selected = [];
    }

    /** Add every selected keyword to the rank tracker (capped per batch). */
    public function trackSelected(): void
    {
        $this->trackNotice = null;
        $batch = array_slice($this->selected, 0, self::MAX_TRACK_BATCH);
        $created = 0;
        $existing = 0;

        foreach ($batch as $keyword) {
            $result = $this->trackOne($keyword);
            if ($result === null) {
                return; // trackOne set the error notice — stop early.
            }
            $result ? $created++ : $existing++;
        }

        $skipped = count($this->selected) - count($batch);
        $this->trackNotice = sprintf(
            'Rank tracker: %d added, %d already tracked.%s',
            $created,
            $existing,
            $skipped > 0 ? ' '.$skipped.' skipped (max '.self::MAX_TRACK_BATCH.' per batch).' : '',
        );
        $this->selected = [];
    }

    /** Copy the selected keywords to the clipboard (via a browser event). */
    public function copySelected(): void
    {
        if ($this->selected !== []) {
            $this->dispatch('copy-to-clipboard', text: implode("\n", $this->selected));
        }
    }

    // ── AI clustering ────────────────────────────────────────────────────────

    /**
     * One batched LLM call → named topic clusters (month-cached, no re-bill
     * on repeat views). $force bypasses the cache — the user's escape hatch
     * to retry a clustering they're unhappy with, rate-limited since each
     * attempt is a real LLM call.
     */
    public function clusterWithAi(AiKeywordClusterService $service, bool $force = false): void
    {
        $this->clusterError = null;

        if (! $service->isAvailable()) {
            $this->clusterError = __('AI clustering is temporarily unavailable.');

            return;
        }

        if (count(array_filter($this->results, 'is_array')) < 6) {
            $this->clusterError = __('Not enough keywords to cluster meaningfully — try broader seeds for more results.');

            return;
        }

        if ($force) {
            $rateKey = 'kw-recluster:'.Auth::id();
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, 5)) {
                $this->clusterError = __('Too many reclustering attempts. Try again in a few minutes.');

                return;
            }
            \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 600);
        }

        $rows = array_map(fn ($r) => $this->normalizeRow($r), array_filter($this->results, 'is_array'));
        $map = $service->cluster($rows, $this->resultSetKey ?? 'adhoc', $force);

        if ($map === null) {
            $this->clusterError = __('Could not cluster these keywords — try again in a moment.');

            return;
        }

        $this->clusterMap = $map;
        $this->viewMode = 'clusters';
    }

    /** Stream the filtered+sorted results as a CSV download. */
    public function export()
    {
        $rows = $this->processedResults();
        $filename = 'keyword-ideas-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Keyword', 'Avg monthly searches', 'Competition', 'Competition index', 'Low top-of-page bid', 'High top-of-page bid', 'Intent', 'Cluster']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['keyword'], $r['volume'], $r['competition'], $r['competitionIndex'], $r['low'], $r['high'], $r['intent'], $r['cluster'] ?? '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function currentWebsite(): ?Website
    {
        $websiteId = session('current_website_id');
        if ($websiteId === null || $websiteId === '' || ! Auth::user()?->canViewWebsiteId($websiteId)) {
            return null;
        }

        return Website::find($websiteId);
    }

    /**
     * Search Console clicks/impressions/position for the current website, for
     * exactly the given keywords, over the last 28 full days (lag-aware —
     * ends yesterday, mirrors the dashboard's stats-window convention).
     * Matched case-insensitively against GSC's own query text.
     *
     * @param  list<string>  $keywords
     * @return array<string, array{clicks:int, impressions:int, position:float}>  lowercased keyword => metrics
     */
    private function gscMetricsFor(array $keywords): array
    {
        $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));
        $website = $this->currentWebsite();
        if ($keywords === [] || $website === null) {
            return [];
        }

        $end = Carbon::yesterday();
        $start = $end->copy()->subDays(27);

        $rows = SearchConsoleData::query()
            ->where('website_id', $website->id)
            ->forDateRange($start->toDateString(), $end->toDateString())
            ->whereIn(DB::raw('LOWER(query)'), array_map('mb_strtolower', $keywords))
            ->select(
                DB::raw('LOWER(query) as q'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('AVG(position) as position'),
            )
            ->groupBy('q')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->q] = [
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => round((float) $row->position, 1),
            ];
        }

        return $out;
    }

    public function render()
    {
        $processed = $this->processedResults();
        $total = count($processed);
        $totalVolume = array_sum(array_map(fn ($r) => (int) ($r['volume'] ?? 0), $processed));
        $totalPages = max(1, (int) ceil($total / max(1, $this->perPage)));
        $this->page = min(max(1, $this->page), $totalPages);
        $rows = array_slice($processed, ($this->page - 1) * $this->perPage, $this->perPage);

        // Groups rail reflects every filter EXCEPT the active group itself, so
        // switching groups never dead-ends (mirror of how Keyword Magic works).
        $savedGroup = $this->groupTerm;
        $this->groupTerm = '';
        $groupSource = $this->processedResults();
        $this->groupTerm = $savedGroup;
        $termGroups = KeywordTermGrouper::groups($groupSource);

        // Clusters view: group the filtered rows by AI cluster label.
        $clusters = [];
        if ($this->viewMode === 'clusters' && $this->clusterMap !== null) {
            foreach ($processed as $r) {
                $label = $r['cluster'] ?? __('Other');
                $clusters[$label] ??= ['label' => $label, 'rows' => [], 'volume' => 0];
                $clusters[$label]['rows'][] = $r;
                $clusters[$label]['volume'] += (int) ($r['volume'] ?? 0);
            }
            // "Other" is explicitly the noise bucket — always last regardless
            // of its accumulated volume, so real topic clusters lead.
            uasort($clusters, function ($a, $b) {
                $aOther = $a['label'] === __('Other');
                $bOther = $b['label'] === __('Other');
                if ($aOther !== $bOther) {
                    return $aOther ? 1 : -1;
                }

                return $b['volume'] <=> $a['volume'];
            });
        }

        $pageKeys = array_map(fn ($r) => mb_strtolower($r['keyword']), $rows);
        $pageAllSelected = $pageKeys !== [] && array_diff($pageKeys, $this->selected) === [];

        // GSC metrics replace the old per-row Volume/Track/Brief action links —
        // "does the site already show up for this idea, and how" is far more
        // actionable than static buttons (Track is now covered by bulk-select,
        // Brief required a target page these rows never have).
        $displayedKeywords = $this->viewMode === 'clusters'
            ? array_column($processed, 'keyword')
            : array_column($rows, 'keyword');
        $hasGsc = $this->currentWebsite()?->hasGsc() ?? false;
        $gscMetrics = $hasGsc ? $this->gscMetricsFor($displayedKeywords) : [];

        return view('livewire.keywords.keyword-idea-finder', [
            'hasGsc' => $hasGsc,
            'gscMetrics' => $gscMetrics,
            'languageOptions' => KeywordFinderLocations::languageOptions(),
            'locationNames' => KeywordFinderLocations::locationNames(),
            'rows' => $rows,
            'totalResults' => $total,
            'totalVolume' => $totalVolume,
            'totalPages' => $totalPages,
            'termGroups' => $termGroups,
            'clusters' => array_values($clusters),
            'pageAllSelected' => $pageAllSelected,
        ]);
    }
}
