<?php

namespace App\Livewire\Competitive;

use App\Jobs\DiscoverCompetitorsJob;
use App\Models\KeywordApiRequest;
use App\Models\WebsiteReportSnapshot;
use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Competitor Discovery — find who competes with ANY website, even when
 * backlink/keyword databases have little on it. Pipeline (SERP-minimal):
 * self-hosted keyword ideas → LLM junk-check → if the keywords are real, they
 * ARE the SERP queries; if they're scrap (login/signup/…), crawl a few pages
 * and let the LLM derive real queries → one capped, cache-shared SERP tally.
 *
 * Async: keyword discovery is webhook-driven and the SERP tally runs in a job
 * (DiscoverCompetitorsJob), so the component dispatches then polls a shared
 * result cache — no long-held web request.
 */
class CompetitorFinder extends Component
{
    /** Target website (defaults to the current site; editable to any URL). */
    #[Url(as: 'url')]
    public string $url = '';

    public string $status = ''; // '' | finding_keywords | discovering | done | no_keywords | error

    public ?string $requestId = null;

    public ?string $cacheKey = null;

    public ?string $domain = null;

    /** @var list<array<string, mixed>> */
    public array $competitors = [];

    public bool $scrap = false;

    public ?string $querySource = null;

    public bool $fromCache = false;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        if ($this->url === '') {
            $this->url = current_website()?->domain ?? '';
        }
    }

    public function run(ReportEnrichmentService $service): void
    {
        $this->reset(['status', 'requestId', 'cacheKey', 'competitors', 'scrap', 'querySource', 'fromCache', 'errorMessage']);

        $domain = WebsiteReportSnapshot::normalizeDomain($this->url);
        if ($domain === '') {
            $this->errorMessage = __('Enter a valid website URL.');

            return;
        }
        $this->domain = $domain;

        // Best source first: competitors from a past Site Explorer report
        // (DataForSEO Labs, or a prior enrichment). If we already have them we
        // skip the whole long discovery — no keyword-fleet / LLM / SERP calls,
        // and we NEVER trigger a new DataForSEO report just to get them.
        $snapshot = WebsiteReportSnapshot::forDomain($domain);
        $reportCompetitors = is_array($snapshot?->payload['competitors'] ?? null) ? $snapshot->payload['competitors'] : [];
        if ($reportCompetitors !== []) {
            $this->applyResult([
                'status' => 'done',
                'competitors' => $reportCompetitors,
                'scrap' => false,
                'query_source' => 'report',
            ], fromCache: true);

            return;
        }

        // Fresh cached result from a previous discovery → show instantly
        // (cross-user, 7-day domain fact).
        $cached = Cache::get(DiscoverCompetitorsJob::cacheKey($domain));
        if (is_array($cached) && in_array($cached['status'] ?? '', ['done', 'no_keywords'], true)) {
            $this->applyResult($cached, fromCache: true);

            return;
        }

        // Kick off keyword discovery (monthly-cache-first).
        $req = $service->keywordIdeasFor($domain, \Illuminate\Support\Facades\Auth::id());
        if ($req['rows'] !== null) {
            // Keyword cache hit → go straight to the SERP-tally job.
            $this->cacheKey = $req['cache_key'];
            $this->dispatchDiscovery(['id' => null, 'cache_key' => $req['cache_key']]);

            return;
        }
        if ($req['id'] === null) {
            $this->errorMessage = __('Keyword discovery is unavailable right now. Please try again shortly.');

            return;
        }

        $this->requestId = $req['id'];
        $this->cacheKey = $req['cache_key'];
        $this->status = 'finding_keywords';
    }

    public function poll(): void
    {
        if ($this->status === 'finding_keywords' && $this->requestId !== null) {
            $request = KeywordApiRequest::query()->where('request_id', $this->requestId)->first();
            if ($request === null) {
                return;
            }
            if ($request->status === KeywordApiRequest::STATUS_FAILED) {
                $this->status = 'error';
                $this->errorMessage = __('Keyword discovery failed. Please try again.');

                return;
            }
            if ($request->status === KeywordApiRequest::STATUS_COMPLETED) {
                $this->dispatchDiscovery(['id' => $this->requestId, 'cache_key' => $this->cacheKey]);
            }

            return;
        }

        if ($this->status === 'discovering' && $this->domain !== null) {
            $cached = Cache::get(DiscoverCompetitorsJob::cacheKey($this->domain));
            if (is_array($cached) && in_array($cached['status'] ?? '', ['done', 'no_keywords'], true)) {
                $this->applyResult($cached, fromCache: false);
            }
        }
    }

    public function isPolling(): bool
    {
        return in_array($this->status, ['finding_keywords', 'discovering'], true);
    }

    /**
     * @param  array{id: ?string, cache_key: ?string}  $ref
     */
    private function dispatchDiscovery(array $ref): void
    {
        DiscoverCompetitorsJob::dispatch($this->domain, $ref, \Illuminate\Support\Facades\Auth::id());
        $this->status = 'discovering';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyResult(array $result, bool $fromCache): void
    {
        $this->competitors = is_array($result['competitors'] ?? null) ? $result['competitors'] : [];
        $this->scrap = (bool) ($result['scrap'] ?? false);
        $this->querySource = $result['query_source'] ?? null;
        $this->fromCache = $fromCache;
        $this->status = ($result['status'] ?? '') === 'no_keywords' && $this->competitors === [] ? 'no_keywords' : 'done';
    }

    public function render()
    {
        $meterUser = current_website()?->owner ?? \Illuminate\Support\Facades\Auth::user();

        return view('livewire.competitive.competitor-finder', [
            'fleetRemaining' => $meterUser ? app(\App\Services\Usage\UsageMeter::class)->remaining($meterUser, 'keyword_finder') : null,
        ]);
    }
}
