<?php

namespace App\Livewire\Keywords;

use App\Models\CustomPageAudit;
use App\Models\PageAuditReport;
use App\Models\Website;
use App\Services\AiSnippetRewriterService;
use App\Services\StrikingDistanceFixService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Striking-distance "Fix this keyword" playbook (full-page, linkable).
 *
 * Reached from the dashboard Priority Action Queue and the Reports → Insights
 * striking-distance table via /keywords/fix?keyword=…&page=…. Runs (or reuses)
 * a keyword-aware audit, then surfaces four fix levers: on-page recommendations,
 * AI title/meta rewrites, a content brief, and internal-link suggestions.
 *
 * The slow work is staged: the audit polls to completion ({@see pollAudit}),
 * then the two AI calls load lazily and independently via wire:init so neither
 * blocks the other. All AI results are cached 7 days, so re-clicks are cheap.
 */
class KeywordFixPlaybook extends Component
{
    #[Url(as: 'keyword')]
    public string $keyword = '';

    #[Url(as: 'page')]
    public string $pageUrl = '';

    #[Url(as: 'country')]
    public string $country = '';

    public ?string $websiteId = null;

    public ?string $auditId = null;

    public ?string $reportId = null;

    /** idle|queued|running|ready|failed */
    public string $status = 'idle';

    public ?string $error = null;

    public bool $aiAllowed = false;

    public string $intent = 'auto';

    /** @var list<array<string, mixed>> */
    public array $recommendations = [];

    /** @var array<string, mixed> */
    public array $onPageMetrics = [];

    /** @var array<string, mixed>|null */
    public ?array $snippetRewrites = null;

    /** @var array<string, mixed>|null */
    public ?array $brief = null;

    public bool $briefAttempted = false;

    /** @var list<array<string, mixed>> */
    public array $internalLinks = [];

    public function mount(StrikingDistanceFixService $fix): void
    {
        $this->keyword = trim($this->keyword);
        $this->pageUrl = trim($this->pageUrl);
        $this->websiteId = session('current_website_id');

        $user = Auth::user();
        if ($this->keyword === '' || $this->pageUrl === '') {
            $this->fail('Missing keyword or page. Open this from a striking-distance opportunity.');

            return;
        }
        if (($this->websiteId === null || $this->websiteId === '') || ! $user?->canViewWebsiteId($this->websiteId)) {
            $this->fail('You don\'t have access to this website.');

            return;
        }
        if (! $user->hasFeatureAccess('audits', $this->websiteId)) {
            $this->fail('Your plan doesn\'t include page audits.');

            return;
        }

        $this->aiAllowed = $this->website()?->featureGateInfo('ai_inline') === null;

        $this->startOrAttach($fix);
    }

    #[On('website-changed')]
    public function switchWebsite(): void
    {
        // The playbook is scoped to one keyword on one site; a website switch
        // invalidates it entirely. Bounce back to the dashboard.
        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Reuse a fresh (<24h) completed audit synchronously; otherwise queue one
     * and let pollAudit() drive it to completion.
     */
    public function startOrAttach(StrikingDistanceFixService $fix): void
    {
        $report = $fix->findFreshReport($this->websiteId, $this->pageUrl);
        if ($report instanceof PageAuditReport) {
            $this->reportId = $report->id;
            $this->status = 'ready';
            $this->hydrateFromReport($fix, $report);

            return;
        }

        $user = Auth::user();
        $rateKey = 'custom-audit:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            $this->fail('Too many audits queued right now. Try again in '.RateLimiter::availableIn($rateKey).'s.');

            return;
        }
        RateLimiter::hit($rateKey, 120);

        $audit = $fix->queueAudit(
            $this->websiteId,
            (string) $user->id,
            $this->pageUrl,
            $this->keyword,
            $this->country !== '' ? $this->country : null,
        );

        $this->auditId = $audit->id;
        $this->status = $audit->isCompleted() ? 'ready' : 'queued';

        if ($audit->isCompleted() && $audit->page_audit_report_id) {
            $this->reportId = $audit->page_audit_report_id;
            if ($report = PageAuditReport::find($this->reportId)) {
                $this->hydrateFromReport($fix, $report);
            }
        }
    }

    /**
     * Polled by wire:poll while the audit is queued/running.
     */
    public function pollAudit(StrikingDistanceFixService $fix): void
    {
        if (! in_array($this->status, ['queued', 'running'], true) || $this->auditId === null) {
            return;
        }

        $audit = CustomPageAudit::find($this->auditId);
        if (! $audit instanceof CustomPageAudit) {
            $this->fail('The audit could not be found.');

            return;
        }

        if ($audit->isCompleted() && $audit->page_audit_report_id) {
            $this->reportId = $audit->page_audit_report_id;
            $this->status = 'ready';
            if ($report = PageAuditReport::find($this->reportId)) {
                $this->hydrateFromReport($fix, $report);
            }

            return;
        }

        if ($audit->isFailed()) {
            $this->fail($audit->error_message ?: 'The audit failed. Please retry.');

            return;
        }

        $this->status = $audit->status === CustomPageAudit::STATUS_RUNNING ? 'running' : 'queued';
    }

    public function retry(StrikingDistanceFixService $fix): void
    {
        $this->status = 'idle';
        $this->error = null;
        $this->auditId = null;
        $this->reportId = null;
        $this->startOrAttach($fix);
    }

    /**
     * Lazy: AI title + meta rewrites for the current intent. Fired via wire:init
     * once the audit is ready, and on intent change.
     */
    public function loadSnippetRewrites(StrikingDistanceFixService $fix): void
    {
        if (! $this->aiAllowed || $this->reportId === null) {
            return;
        }
        $report = PageAuditReport::find($this->reportId);
        if (! $report instanceof PageAuditReport) {
            return;
        }

        $this->snippetRewrites = $fix->snippetRewrites(
            $this->websiteId,
            $this->pageUrl,
            $this->keyword,
            $report,
            $this->intent,
        );
    }

    public function regenerateIntent(string $intent, StrikingDistanceFixService $fix): void
    {
        $this->intent = $intent === 'auto' || array_key_exists($intent, AiSnippetRewriterService::INTENTS)
            ? $intent
            : 'auto';
        $this->snippetRewrites = null;
        $this->loadSnippetRewrites($fix);
    }

    /**
     * Lazy: content brief. Shows a cached brief for free on init; a fresh
     * generation (which spends a Serper credit) only happens on explicit click.
     */
    public function loadBrief(StrikingDistanceFixService $fix, bool $generate = false): void
    {
        if (! $this->aiAllowed) {
            return;
        }
        $this->briefAttempted = true;
        $this->brief = $fix->brief(
            $this->website(),
            $this->keyword,
            $this->country !== '' ? $this->country : null,
            $generate,
        );
    }

    public function generateBrief(StrikingDistanceFixService $fix): void
    {
        $this->loadBrief($fix, generate: true);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hydrateFromReport(StrikingDistanceFixService $fix, PageAuditReport $report): void
    {
        $this->recommendations = $fix->recommendations($report);
        $this->onPageMetrics = $fix->onPageMetrics($report, $this->keyword);
        $this->internalLinks = $fix->internalLinks($this->website(), $this->keyword, $this->pageUrl);
    }

    private function website(): ?Website
    {
        return Website::find($this->websiteId);
    }

    private function fail(string $message): void
    {
        $this->status = 'failed';
        $this->error = $message;
    }

    public function render()
    {
        return view('livewire.keywords.keyword-fix-playbook', [
            'intents' => AiSnippetRewriterService::INTENTS,
        ]);
    }
}
