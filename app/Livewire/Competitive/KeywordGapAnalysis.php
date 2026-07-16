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
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
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
     * Target website — defaults to the current site, editable to any URL.
     * `#[Url]` so deep-links (e.g. "Compare in Keyword Gap" from Competitor
     * Discovery) prefill it via ?url=.
     */
    #[Url(as: 'url')]
    public string $targetUrl = '';

    /** True when $targetUrl is NOT one of the user's own websites. */
    public bool $targetIsForeign = false;

    /** Inline "Find competitors" discovery state for a foreign target. */
    public string $findStatus = ''; // '' | finding | done

    public ?string $findRequestId = null;

    public ?string $findCacheKey = null;

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
        // Default to the current website ONLY when no ?url= was deep-linked in
        // (the #[Url] binding already populated $targetUrl in that case).
        if (trim($this->targetUrl) === '') {
            $this->targetUrl = (string) ($this->website()?->domain ?? '');
        }
        $this->loadTargetContext($discovery);
        // Saved reports are the cheap path: reopen the target's most recent
        // completed analysis instantly — zero keyword-fleet / SERP spend.
        $this->restoreLatestAnalysis();
    }

    /** Re-resolve competitors when the user changes the target website URL. */
    public function updatedTargetUrl(): void
    {
        $this->analysisId = null;
        $this->status = '';
        $this->loadTargetContext(app(CompetitorDiscoveryService::class));
        $this->restoreLatestAnalysis();
    }

    /**
     * Saved analyses for the current target this user may read: the target
     * website's runs when it's an owned site (already access-gated by
     * resolveTarget), else ONLY this user's own foreign-URL runs.
     */
    private function analysesForTarget(): ?\Illuminate\Database\Eloquent\Builder
    {
        [$website, $domain] = $this->resolveTarget();
        if ($domain === '') {
            return null;
        }

        $q = GapAnalysis::query()->where('status', GapAnalysis::STATUS_COMPLETED);

        return $website !== null
            ? $q->where('website_id', $website->id)
            : $q->whereNull('website_id')->where('user_id', Auth::id())->where('our_url', $domain);
    }

    /** Reopen the newest saved report for the target (stored rows — free). */
    private function restoreLatestAnalysis(): void
    {
        $latest = $this->analysesForTarget()?->latest('id')->first();
        if ($latest === null) {
            return;
        }

        $this->applySavedAnalysis($latest);
    }

    /** Open a specific saved analysis from the history picker. */
    public function loadAnalysis(string $analysisId): void
    {
        $analysis = GapAnalysis::find($analysisId);
        if ($analysis === null) {
            return;
        }
        // Access: an owned website's run (viewer gate) or the user's own run.
        $ownedOk = $analysis->website_id !== null && Auth::user()?->canViewWebsiteId($analysis->website_id);
        if (! $ownedOk && $analysis->user_id !== Auth::id()) {
            return;
        }

        // Point the target at the run's own URL so the report + picker match.
        $this->targetUrl = (string) $analysis->our_url;
        $this->loadTargetContext(app(CompetitorDiscoveryService::class));
        $this->applySavedAnalysis($analysis);
    }

    private function applySavedAnalysis(GapAnalysis $analysis): void
    {
        $this->analysisId = $analysis->id;
        $this->status = $analysis->status;
        $this->country = $analysis->country ?: $this->country;
        $this->competitors = is_array($analysis->competitor_urls) ? $analysis->competitor_urls : [];
        $this->editingCompetitors = false;
        $this->tab = 'missing';
        $this->page = 1;
        // A revisited report shouldn't re-shout its old verification banner;
        // a fresh verify pass resets this.
        $this->verifyBannerDismissed = true;
    }

    /**
     * Force a fresh run with the SAME competitor set — for a saved report
     * that has gone stale. Bypasses the latestFresh cache deliberately.
     */
    public function refreshAnalysis(KeywordGapService $service): void
    {
        [$website, $domain] = $this->resolveTarget();
        $urls = array_values(array_filter(array_map('trim', $this->competitors)));
        if ($domain === '' || $urls === []) {
            return;
        }

        $this->reset(['errorMessage', 'analysisId', 'status', 'page', 'editingCompetitors', 'verifyBannerDismissed']);
        $analysis = $service->startForTarget($website, $domain, $urls, $this->country, Auth::id());
        $this->analysisId = $analysis->id;
        $this->status = $analysis->status;
        if ($analysis->status === GapAnalysis::STATUS_FAILED) {
            $this->errorMessage = $analysis->error;
        }
    }

    /**
     * Resolve $targetUrl to an owned Website (load its Site Explorer
     * competitors) or a foreign URL (competitors come from manual add / the
     * cached Competitor Discovery result — never auto-billed here).
     */
    private function loadTargetContext(CompetitorDiscoveryService $discovery): void
    {
        $this->reset(['suggested', 'competitors', 'errorMessage']);
        [$website, $domain] = $this->resolveTarget();
        $this->targetIsForeign = $domain !== '' && $website === null;

        if ($domain === '') {
            return;
        }

        // Owned OR foreign: the shared Site Explorer snapshot for the domain
        // already holds organic competitors (read directly — never via a billed
        // generation). For a foreign target it's whatever was discovered before
        // (report enrichment / the Competitor Discovery page); empty otherwise.
        $snapshot = \App\Models\WebsiteReportSnapshot::forDomain($domain);
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

        // Foreign, no cached competitors → also try the standalone Competitor
        // Discovery cache (the user may have run it for this URL).
        if ($this->suggested === [] && $this->targetIsForeign) {
            $cached = \Illuminate\Support\Facades\Cache::get(\App\Jobs\DiscoverCompetitorsJob::cacheKey($domain));
            foreach (array_slice(is_array($cached['competitors'] ?? null) ? $cached['competitors'] : [], 0, 12) as $row) {
                $d = trim((string) ($row['domain'] ?? ''));
                if ($d !== '') {
                    $this->suggested[] = ['domain' => $d, 'shared_keywords' => $row['shared_keywords'] ?? null, 'avg_position' => $row['avg_position'] ?? null, 'opr_score' => $row['opr_score'] ?? null];
                }
            }
        }

        $max = $this->maxCompetitors();
        if ($this->suggested !== []) {
            $this->competitors = array_column(array_slice($this->suggested, 0, $max), 'domain');
        } elseif ($website !== null) {
            // Owned site with no snapshot competitors → SERP auto-discovery.
            $this->competitors = $discovery->resultsFor($website->id)
                ->take($max)->pluck('competitor_domain')->all();
        }
        // Foreign with nothing found → manual add only (competitors stays []).
    }

    /**
     * @return array{0: ?Website, 1: string}  [ownedWebsite|null, normalizedDomain]
     */
    private function resolveTarget(): array
    {
        $domain = \App\Models\WebsiteReportSnapshot::normalizeDomain($this->targetUrl);
        if ($domain === '') {
            return [null, ''];
        }
        $website = Website::query()->where('normalized_domain', $domain)->get()
            ->first(fn (Website $w) => Auth::user()?->canViewWebsiteId($w->id));

        return [$website, $domain];
    }

    /**
     * Discover competitors for the target INLINE (foreign sites with no known
     * competitors) — same pipeline as the Competitor Discovery page, reusing
     * its shared result cache, so results land as suggestions right here.
     */
    public function findCompetitors(\App\Services\Reports\ReportEnrichmentService $service): void
    {
        [, $domain] = $this->resolveTarget();
        if ($domain === '') {
            $this->errorMessage = __('Enter a website URL first.');

            return;
        }
        $this->errorMessage = null;

        // Already discovered (this page ran it before, or the standalone tool did).
        $cached = Cache::get(\App\Jobs\DiscoverCompetitorsJob::cacheKey($domain));
        if (is_array($cached) && in_array($cached['status'] ?? '', ['done', 'no_keywords'], true)) {
            $this->applyDiscovery($cached);

            return;
        }

        $req = $service->keywordIdeasFor($domain, (string) Auth::id());
        if ($req['rows'] !== null) {
            \App\Jobs\DiscoverCompetitorsJob::dispatch($domain, ['id' => null, 'cache_key' => $req['cache_key']], (string) Auth::id());
            $this->findCacheKey = $req['cache_key'];
            $this->findStatus = 'finding';

            return;
        }
        if ($req['id'] === null) {
            $this->errorMessage = __('Keyword discovery is unavailable right now. Please try again shortly.');

            return;
        }
        $this->findRequestId = $req['id'];
        $this->findCacheKey = $req['cache_key'];
        $this->findStatus = 'finding';
    }

    /** Poll the inline discovery (called from poll() while findStatus=finding). */
    private function pollFind(): void
    {
        [, $domain] = $this->resolveTarget();
        if ($domain === '') {
            $this->findStatus = '';

            return;
        }

        if ($this->findRequestId !== null) {
            $request = \App\Models\KeywordApiRequest::query()->where('request_id', $this->findRequestId)->first();
            if ($request === null) {
                return;
            }
            if ($request->status === \App\Models\KeywordApiRequest::STATUS_FAILED) {
                $this->findStatus = '';
                $this->errorMessage = __('Competitor discovery failed. Please try again.');

                return;
            }
            if ($request->status === \App\Models\KeywordApiRequest::STATUS_COMPLETED) {
                \App\Jobs\DiscoverCompetitorsJob::dispatch($domain, ['id' => $this->findRequestId, 'cache_key' => $this->findCacheKey], (string) Auth::id());
                $this->findRequestId = null;
            }

            return;
        }

        $cached = Cache::get(\App\Jobs\DiscoverCompetitorsJob::cacheKey($domain));
        if (is_array($cached) && in_array($cached['status'] ?? '', ['done', 'no_keywords'], true)) {
            $this->applyDiscovery($cached);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyDiscovery(array $result): void
    {
        $rows = is_array($result['competitors'] ?? null) ? $result['competitors'] : [];
        $this->suggested = [];
        foreach (array_slice($rows, 0, 12) as $row) {
            $d = trim((string) ($row['domain'] ?? ''));
            if ($d !== '') {
                $this->suggested[] = ['domain' => $d, 'shared_keywords' => $row['shared_keywords'] ?? null, 'avg_position' => $row['avg_position'] ?? null, 'opr_score' => $row['opr_score'] ?? null];
            }
        }
        $max = $this->maxCompetitors();
        if ($this->suggested !== []) {
            $this->competitors = array_column(array_slice($this->suggested, 0, $max), 'domain');
        }
        $this->findStatus = 'done';
        $this->findRequestId = null;
    }

    public function isFinding(): bool
    {
        return $this->findStatus === 'finding';
    }

    /** Track keywords FOR the analyzed target (owned or competitor), not the session site. */
    protected function resolveTrackDomain(?Website $website): string
    {
        [, $domain] = $this->resolveTarget();

        return $domain !== '' ? $domain : (string) ($website?->domain ?? '');
    }

    protected function resolveTrackCountry(): string
    {
        return $this->country ?: 'us';
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

        [$website, $domain] = $this->resolveTarget();
        if ($domain === '') {
            $this->errorMessage = __('Enter a valid website URL to analyze.');

            return;
        }

        $urls = array_values(array_filter(array_map('trim', $this->competitors), fn ($u) => $u !== ''));
        if ($urls === []) {
            $this->errorMessage = 'Enter at least one competitor domain.';

            return;
        }

        // Serve a fresh cached run if one matches — owned targets only (the
        // cache keys on website_id, which a foreign target doesn't have).
        if ($website !== null) {
            $cached = $service->latestFresh($website->id, $urls, $this->country);
            if ($cached !== null) {
                $this->analysisId = $cached->id;
                $this->status = $cached->status;

                return;
            }
        }

        // Owned site → full run (GSC-aware). Foreign URL → keyword-only run
        // (Missing/Shared buckets; no GSC to build Weak/Strength from).
        $analysis = $service->startForTarget($website, $domain, $urls, $this->country, Auth::id());
        $this->analysisId = $analysis->id;
        $this->status = $analysis->status;
        if ($analysis->status === GapAnalysis::STATUS_FAILED) {
            $this->errorMessage = $analysis->error;
        }
    }

    /** Polled while a run is collecting async discovery results. */
    public function poll(KeywordGapService $service): void
    {
        // Advance an inline competitor discovery (independent of a gap run).
        if ($this->findStatus === 'finding') {
            $this->pollFind();
        }

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
    /** Hides the post-verification results banner once acknowledged. */
    public bool $verifyBannerDismissed = false;

    public function dismissVerifyBanner(): void
    {
        $this->verifyBannerDismissed = true;
    }

    public function verifyRankings(KeywordGapService $service): void
    {
        $this->startVerifyPass($service, $this->tab);
    }

    /** Banner "Check N more" — always continues the Missing bucket. */
    public function verifyMissingMore(KeywordGapService $service): void
    {
        // How many are still unchecked BEFORE this pass, so the confirmation can
        // say "checking N of M now" and set the right expectation.
        $before = max(0, (int) ($this->summary['missing'] ?? 0) - (int) ($this->verifiedCounts['missing'] ?? 0));
        $this->startVerifyPass($service, GapAnalysis::BUCKET_MISSING, $before);
    }

    private function startVerifyPass(KeywordGapService $service, string $bucket, ?int $remainingBefore = null): void
    {
        $this->verifyNotice = null;
        $this->errorMessage = null;
        $this->verifyBannerDismissed = false;
        $analysis = $this->analysisId ? GapAnalysis::find($this->analysisId) : null;
        if ($analysis === null) {
            return;
        }

        try {
            $queued = $service->startVerification($analysis, $bucket);
        } catch (\App\Exceptions\QuotaExceededException $e) {
            // Monthly live-check quota is spent and nothing is cached —
            // rendered as the prominent plan-limit banner.
            $this->errorMessage = $e->userMessage;

            return;
        }
        if ($queued === 0) {
            $this->verifyNotice = __('Nothing left to verify here — every keyword in this view has been checked.');

            return;
        }

        // A pass is capped (per-pass budget + remaining monthly quota), so a
        // click on "Check 741 more" rarely runs all 741 at once. Tell the user
        // exactly how many THIS batch covers and that clicking again continues.
        if ($remainingBefore !== null && $queued < $remainingBefore) {
            $this->verifyNotice = __('Checking :n of :total now — click “Check more” again once it finishes to continue through the rest.', [
                'n' => number_format($queued),
                'total' => number_format($remainingBefore),
            ]);
        } else {
            $this->verifyNotice = __('Checking :n keywords against Google’s live results now.', ['n' => number_format($queued)]);
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
                // WHILE verifying, hold a STABLE order (volume + id) so rows
                // don't reshuffle every poll as they flip verified / get
                // re-scored — verify checks the top-volume slice, so positions
                // fill in on the top rows in place. Once verification finishes,
                // surface the live-verified rows first and re-rank by
                // opportunity so the real SERP positions lead.
                ->when(! $this->isVerifying(), fn ($q) => $q
                    ->orderByRaw('verified_at is null')
                    ->orderByDesc('opportunity_score'))
                ->orderByDesc('search_volume')
                ->orderBy('id')
            : null;

        $total = $query ? (clone $query)->count() : 0;
        $totalPages = max(1, (int) ceil($total / max(1, $this->perPage)));
        $this->page = min(max(1, $this->page), $totalPages);
        $rows = $query
            ? $query->forPage($this->page, $this->perPage)->get()
            : collect();

        [$targetWebsite, $targetDomain] = $this->resolveTarget();

        // Post-verification value summary: how many keywords the live check
        // placed in each bucket — powers the "here's what we found" banner.
        $verifiedCounts = [];
        if ($analysis && $analysis->verify_status === GapAnalysis::VERIFY_STATUS_COMPLETED && ! $this->verifyBannerDismissed) {
            $verifiedCounts = KeywordGapRow::query()
                ->where('keyword_gap_analysis_id', $analysis->id)
                ->whereNotNull('verified_at')
                ->selectRaw('bucket, COUNT(*) as c')
                ->groupBy('bucket')
                ->pluck('c', 'bucket')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        // Saved-report history for this target (newest first) — free DB read.
        $pastAnalyses = $this->analysesForTarget()
            ?->latest('id')->limit(8)
            ->get(['id', 'our_url', 'competitor_urls', 'country', 'summary', 'completed_at', 'verified_at', 'expires_at'])
            ?? collect();

        $meterUser = current_website()?->owner ?? Auth::user();
        $serpRemaining = $meterUser ? app(\App\Services\Usage\UsageMeter::class)->remaining($meterUser, 'serp_api') : null;

        return view('livewire.competitive.keyword-gap-analysis', [
            'serpRemaining' => $serpRemaining,
            'website' => $targetWebsite,
            'targetDomain' => $targetDomain,
            'verifiedCounts' => $verifiedCounts,
            'pastAnalyses' => $pastAnalyses,
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
