<?php

namespace App\Livewire\Competitive;

use App\Livewire\Keywords\Concerns\TracksKeyword;
use App\Models\KeywordGapAnalysis as GapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\Website;
use App\Services\Competitive\CompetitorDiscoveryService;
use App\Services\Competitive\KeywordGapService;
use App\Support\KeywordFinderLocations;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Keyword Gap Analysis UI. Competitors come from the website's Site Explorer
 * snapshot (DataForSEO Labs organic competitors, ranked by shared keywords —
 * already paid for, so the picker is a free cache read) as one-click
 * suggestions, with SERP auto-discovery as the fallback source and a manual
 * input for anything else. Dispatches the run, polls until the async
 * discovery aggregates, then shows the Missing / Weak / Strength (or Shared)
 * buckets with opportunity scores.
 */
class KeywordGapAnalysis extends Component
{
    use TracksKeyword;

    /** Selected competitor domains (canonical run input). @var list<string> */
    public array $competitors = [];

    /**
     * One-click suggestions from the Site Explorer snapshot: each item is
     * {domain, shared_keywords, avg_position, opr_score}. Empty when the
     * site has no snapshot yet.
     *
     * @var array<int, array{domain: string, shared_keywords: ?int, avg_position: ?float, opr_score: ?float}>
     */
    public array $suggested = [];

    /** Manual "add a competitor" input. */
    public string $manualDomain = '';

    /**
     * Completed results collapse the picker into a compact summary bar;
     * "Change competitors" flips this to bring the picker back (results
     * stay visible until the new run replaces them).
     */
    public bool $editingCompetitors = false;

    public function changeCompetitors(): void
    {
        $this->editingCompetitors = true;
    }

    public string $country = 'us';

    public ?string $analysisId = null;

    public string $status = '';

    public ?string $errorMessage = null;

    public ?string $verifyNotice = null;

    /** Show only confirmed gaps (competitor proven to rank) in the table. */
    public bool $confirmedOnly = false;

    /** Active bucket tab: missing | weak | strength | shared. */
    public string $tab = 'missing';

    public string $filterText = '';

    public int $perPage = 25;

    public int $page = 1;

    public function mount(CompetitorDiscoveryService $discovery): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }

        // Primary source: the Site Explorer snapshot's organic competitors
        // (read directly — NEVER via ReportViewController::resolve(), which
        // would dispatch a billed generation as a side effect of opening a
        // keyword-research tab). Top rows are already sorted by shared
        // keywords, exactly the relevance order a gap analysis wants.
        $domain = (string) $website->normalized_domain;
        $snapshot = $domain !== '' ? \App\Models\WebsiteReportSnapshot::forDomain($domain) : null;
        $rows = is_array($snapshot?->payload['competitors'] ?? null) ? $snapshot->payload['competitors'] : [];
        foreach (array_slice($rows, 0, 12) as $row) {
            $d = trim((string) ($row['domain'] ?? ''));
            if ($d === '') {
                continue;
            }
            $this->suggested[] = [
                'domain' => $d,
                'shared_keywords' => isset($row['shared_keywords']) ? (int) $row['shared_keywords'] : null,
                'avg_position' => isset($row['avg_position']) && $row['avg_position'] !== null ? (float) $row['avg_position'] : null,
                'opr_score' => isset($row['opr_score']) && $row['opr_score'] !== null ? (float) $row['opr_score'] : null,
            ];
        }

        // Pre-select the top suggestions; fall back to SERP auto-discovery
        // when the site has no Site Explorer snapshot yet.
        $max = $this->maxCompetitors();
        if ($this->suggested !== []) {
            $this->competitors = array_column(array_slice($this->suggested, 0, $max), 'domain');
        } else {
            $this->competitors = $discovery->resultsFor($website->id)
                ->take($max)->pluck('competitor_domain')->all();
        }
    }

    public function toggleCompetitor(string $domain): void
    {
        $domain = trim($domain);
        if ($domain === '') {
            return;
        }
        $this->errorMessage = null;

        if (in_array($domain, $this->competitors, true)) {
            $this->competitors = array_values(array_diff($this->competitors, [$domain]));

            return;
        }
        if (count($this->competitors) >= $this->maxCompetitors()) {
            $this->errorMessage = __('You can compare up to :max competitors per run — deselect one first.', ['max' => $this->maxCompetitors()]);

            return;
        }
        $this->competitors[] = $domain;
    }

    public function addManualCompetitor(): void
    {
        $domain = \App\Models\CompetitorBacklink::extractDomain(trim($this->manualDomain));
        if ($domain === '') {
            $this->errorMessage = __('Enter a valid competitor domain.');

            return;
        }
        $this->manualDomain = '';
        $this->toggleCompetitor($domain);
    }

    private function maxCompetitors(): int
    {
        return max(1, (int) config('services.competitive.gap_max_competitors', 3));
    }

    private function website(): ?Website
    {
        $id = session('current_website_id');

        // Gate on access — Livewire actions don't re-run the route middleware that
        // validates current_website_id, so trust the session id only if the current
        // user can still view it (mirrors every other website-scoped component).
        if (($id === null || $id === '') || ! Auth::user()?->canViewWebsiteId($id)) {
            return null;
        }

        return Website::find($id);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['filterText', 'perPage', 'tab', 'confirmedOnly'], true)) {
            $this->page = 1;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->page = 1;
    }

    public function setPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function run(KeywordGapService $service): void
    {
        $this->reset(['errorMessage', 'analysisId', 'status', 'page', 'editingCompetitors']);

        $website = $this->website();
        if ($website === null) {
            $this->errorMessage = 'Select a website first.';

            return;
        }

        $urls = array_values(array_filter(array_map('trim', $this->competitors), fn ($u) => $u !== ''));
        if ($urls === []) {
            $this->errorMessage = 'Enter at least one competitor domain.';

            return;
        }

        // Serve a fresh cached run if one matches.
        $cached = $service->latestFresh($website->id, $urls, $this->country);
        if ($cached !== null) {
            $this->analysisId = $cached->id;
            $this->status = $cached->status;

            return;
        }

        $analysis = $service->start($website, $urls, $this->country, Auth::id());
        $this->analysisId = $analysis->id;
        $this->status = $analysis->status;
        if ($analysis->status === GapAnalysis::STATUS_FAILED) {
            $this->errorMessage = $analysis->error;
        }
    }

    /** Polled while a run is collecting async discovery results. */
    public function poll(KeywordGapService $service): void
    {
        if ($this->analysisId === null) {
            return;
        }
        $analysis = GapAnalysis::find($this->analysisId);
        if ($analysis === null) {
            $this->analysisId = null;

            return;
        }

        if ($analysis->status === GapAnalysis::STATUS_COLLECTING) {
            $service->maybeAggregate($analysis);
            $analysis->refresh();
        }

        $this->status = $analysis->status;
        if ($analysis->status === GapAnalysis::STATUS_FAILED) {
            $this->errorMessage = $analysis->error ?: 'The analysis failed. Please try again.';
        }
    }

    public function isPolling(): bool
    {
        return $this->analysisId !== null && $this->status === GapAnalysis::STATUS_COLLECTING;
    }

    // NOTE: a per-row "refine" action (computeLive → OpportunityScoreService::liveScore)
    // was removed 2026-07-14 — it was a strict subset of the batch "Verify"
    // flow (same SERP call, score-only, no position capture / re-bucketing)
    // with a label that explained nothing.

    /**
     * Expand a gap-row keyword into related ideas (research hub, Ideas tab).
     * NOTE: there is deliberately no "send to Volume" — volume is already a
     * column in the gap table, so that handoff was a dead end.
     */
    public function sendToIdeas(string $rowId): void
    {
        $this->handoffRow($rowId, 'ideas');
    }

    private function handoffRow(string $rowId, string $target): void
    {
        $row = KeywordGapRow::find($rowId);
        if ($row === null || $row->keyword_gap_analysis_id !== $this->analysisId) {
            return;
        }
        // Gap lives on its own page now (not inside the research hub), so a
        // Livewire event can't reach the hub component — navigate to it with
        // the keyword as a query param instead (KeywordResearch::mount()
        // turns ?kw= into the same preset the event used to carry).
        $this->redirectRoute('keyword-research.index', ['tab' => $target, 'kw' => $row->keyword]);
    }

    /** Verify the CURRENT bucket tab against the live SERP (batch, cost-gated). */
    public function verifyRankings(KeywordGapService $service): void
    {
        $this->verifyNotice = null;
        $analysis = $this->analysisId ? GapAnalysis::find($this->analysisId) : null;
        if ($analysis === null) {
            return;
        }

        $queued = $service->startVerification($analysis, $this->tab);
        if ($queued === 0) {
            $this->verifyNotice = 'Nothing left to verify in this bucket.';
        }
    }

    public function isVerifying(): bool
    {
        return $this->analysisId !== null
            && GapAnalysis::query()->where('id', $this->analysisId)->value('verify_status') === GapAnalysis::VERIFY_STATUS_VERIFYING;
    }

    /**
     * Per-source progress rows for the collecting teaser: one entry per
     * discovery source with a human state (cached / running / done / failed).
     *
     * @return array<int, array{domain: string, role: string, state: string}>
     */
    private function collectingProgress(?GapAnalysis $analysis): array
    {
        if ($analysis === null || $analysis->status !== GapAnalysis::STATUS_COLLECTING) {
            return [];
        }
        $entries = is_array($analysis->request_ids) ? $analysis->request_ids : [];
        $ids = array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $entries)));
        $statuses = \App\Models\KeywordApiRequest::query()
            ->whereIn('request_id', $ids)
            ->pluck('status', 'request_id');

        return array_map(function ($r) use ($statuses) {
            $state = 'running';
            if (($r['cache_key'] ?? null) !== null) {
                $state = 'cached';
            } elseif (($s = $statuses[$r['id'] ?? ''] ?? null) !== null) {
                $state = match ($s) {
                    \App\Models\KeywordApiRequest::STATUS_COMPLETED => 'done',
                    \App\Models\KeywordApiRequest::STATUS_FAILED => 'failed',
                    default => 'running',
                };
            }

            return [
                'domain' => (string) ($r['domain'] ?? $r['url'] ?? ''),
                'role' => (string) ($r['role'] ?? 'competitor'),
                'state' => $state,
            ];
        }, $entries);
    }

    public function render()
    {
        $analysis = $this->analysisId ? GapAnalysis::find($this->analysisId) : null;

        $query = $analysis
            ? KeywordGapRow::query()
                ->where('keyword_gap_analysis_id', $analysis->id)
                ->where('bucket', $this->tab)
                ->when($this->filterText !== '', fn ($q) => $q->where('keyword', 'like', '%'.trim($this->filterText).'%'))
                ->when($this->confirmedOnly, fn ($q) => $q->whereNotNull('competitor_position'))
                ->orderByDesc('opportunity_score')
                ->orderByDesc('search_volume')
            : null;

        $total = $query ? (clone $query)->count() : 0;
        $totalPages = max(1, (int) ceil($total / max(1, $this->perPage)));
        $this->page = min(max(1, $this->page), $totalPages);
        $rows = $query
            ? $query->forPage($this->page, $this->perPage)->get()
            : collect();

        return view('livewire.competitive.keyword-gap-analysis', [
            'website' => $this->website(),
            'analysis' => $analysis,
            'rows' => $rows,
            'total' => $total,
            'totalPages' => $totalPages,
            'countryOptions' => KeywordFinderLocations::countryOptions(),
            'maxCompetitors' => (int) config('services.competitive.gap_max_competitors', 3),
            'progress' => $this->collectingProgress($analysis),
        ]);
    }
}
