<?php

namespace App\Livewire\Competitive;

use App\Exceptions\QuotaExceededException;
use App\Livewire\Keywords\Concerns\TracksKeyword;
use App\Models\KeywordGapAnalysis as GapAnalysis;
use App\Models\KeywordGapRow;
use App\Models\Website;
use App\Services\Competitive\CompetitorDiscoveryService;
use App\Services\Competitive\KeywordGapService;
use App\Services\Competitive\OpportunityScoreService;
use App\Support\KeywordFinderLocations;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Keyword Gap Analysis UI. Pre-fills competitor inputs from auto-discovery,
 * dispatches the run, polls until the async discovery aggregates, then shows
 * the Missing / Weak / Strength (or Shared) buckets with opportunity scores.
 */
class KeywordGapAnalysis extends Component
{
    use TracksKeyword;

    /** @var array<int, string> */
    public array $competitors = ['', '', ''];

    public string $country = 'us';

    public ?string $analysisId = null;

    public string $status = '';

    public ?string $errorMessage = null;

    /** Upgrade CTA shown alongside a quota-limit error. */
    public ?string $upgradeUrl = null;

    /** rowId => 'ok' once a row's score has been refined this session. */
    public array $refinedRows = [];

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
        // Pre-fill from auto-discovered competitors.
        $top = $discovery->resultsFor($website->id)->take(3)->pluck('competitor_domain')->all();
        foreach ($top as $i => $domain) {
            $this->competitors[$i] = $domain;
        }
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
        $this->reset(['errorMessage', 'analysisId', 'status', 'page']);

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

    /** Refine one row's opportunity score with a live SERP lookup (cost-gated). */
    public function computeLive(string $rowId, OpportunityScoreService $opportunity): void
    {
        $this->errorMessage = null;
        $this->upgradeUrl = null;

        $website = $this->website();
        $analysis = $this->analysisId ? GapAnalysis::find($this->analysisId) : null;
        $row = KeywordGapRow::find($rowId);
        if ($website === null || $analysis === null || $row === null || $row->keyword_gap_analysis_id !== $analysis->id) {
            return;
        }

        try {
            $result = $opportunity->liveScore(
                $row->keyword,
                KeywordFinderLocations::serperGl($analysis->country),
                $row->search_volume,
                $row->our_position,
                $website->id,
                Auth::id(),
            );
        } catch (QuotaExceededException $e) {
            $this->errorMessage = $e->userMessage;
            $this->upgradeUrl = $e->upgradeUrl;

            return;
        }

        if ($result === null) {
            $this->errorMessage = 'Couldn’t fetch live data for that keyword. Please try again shortly.';

            return;
        }

        $row->forceFill([
            'opportunity_score' => $result['score'],
            'score_components' => $result['components'],
        ])->save();
        $this->refinedRows[$rowId] = 'ok';
    }

    /** Send a gap-row keyword to the Volume tab (research hub). */
    public function sendToVolume(string $rowId): void
    {
        $this->handoffRow($rowId, 'volume');
    }

    /** Send a gap-row keyword to the Ideas tab as a seed (research hub). */
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
        $this->dispatch(
            'research-handoff',
            target: $target,
            mode: $target === 'ideas' ? 'seeds' : null,
            keywords: [$row->keyword],
        );
    }

    /** Verify the Missing bucket against the live SERP (batch, cost-gated). */
    public function verifyRankings(KeywordGapService $service): void
    {
        $this->verifyNotice = null;
        $analysis = $this->analysisId ? GapAnalysis::find($this->analysisId) : null;
        if ($analysis === null) {
            return;
        }

        $queued = $service->startVerification($analysis);
        if ($queued === 0) {
            $this->verifyNotice = 'Nothing left to verify in this bucket.';
        }
    }

    public function isVerifying(): bool
    {
        return $this->analysisId !== null
            && GapAnalysis::query()->where('id', $this->analysisId)->value('verify_status') === GapAnalysis::VERIFY_STATUS_VERIFYING;
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
        ]);
    }
}
