<?php

namespace App\Livewire\Pages;

use App\Jobs\RunCustomPageAudit;
use App\Models\CustomPageAudit;
use App\Models\Website;
use App\Services\PageAuditService;
use App\Support\Audit\SerpGlCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class CustomAudit extends Component
{
    public ?string $websiteId = null;

    #[Url(as: 'pageUrl')]
    public string $pageUrl = '';

    #[Url(as: 'targetKeyword')]
    public string $targetKeyword = '';

    public ?string $message = null;

    public string $messageKind = 'info';

    public bool $awaitingSerpCountryChoice = false;

    public string $serpCountryGl = 'us';

    public ?string $serpCountryRecommendationHint = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->awaitingSerpCountryChoice = false;
        $this->serpCountryRecommendationHint = null;
    }

    public function updatedPageUrl(): void
    {
        $this->awaitingSerpCountryChoice = false;
        $this->serpCountryRecommendationHint = null;
    }

    public function updatedTargetKeyword(): void
    {
        $this->awaitingSerpCountryChoice = false;
        $this->serpCountryRecommendationHint = null;
    }

    public function cancelSerpCountryStep(): void
    {
        $this->awaitingSerpCountryChoice = false;
        $this->serpCountryRecommendationHint = null;
        $this->message = null;
    }

    /**
     * Queue a background audit. Returns immediately — the user sees a new row
     * in "Recent custom audits" with status=Queued that polls itself to completion.
     */
    public function queueAudit(PageAuditService $pageAuditService): void
    {
        $this->message = null;
        $this->messageKind = 'info';

        $user = Auth::user();
        if (! $user || ! $user->canViewWebsiteId($this->websiteId)) {
            $this->setMessage('You do not have permission to run an audit for this website.', 'error');

            return;
        }

        $normalizedUrl = $this->normalizePageUrl($this->pageUrl);
        $keyword = trim($this->targetKeyword);

        Validator::make(
            [
                'pageUrl' => $normalizedUrl,
                'targetKeyword' => $keyword,
            ],
            [
                'pageUrl' => ['required', 'string', 'max:2000'],
                'targetKeyword' => ['required', 'string', 'min:2', 'max:200'],
            ],
            [
                'pageUrl.required' => 'Enter a page URL.',
                'targetKeyword.required' => 'Enter the keyword to use for the SERP benchmark.',
            ],
            [],
            [
                'pageUrl' => 'page URL',
                'targetKeyword' => 'target keyword',
            ]
        )->validate();

        $website = Website::query()->find($this->websiteId);
        if (! $website instanceof Website) {
            $this->setMessage('Select a website first.', 'error');

            return;
        }

        if (! $website->isAuditUrlForThisSite($normalizedUrl)) {
            $this->addError(
                'pageUrl',
                'The URL must use your website domain ('.$website->domain.') or a subdomain of it.'
            );

            return;
        }

        // Dedupe: don't let paid APIs run twice for the same URL if one is already queued/running.
        $active = CustomPageAudit::findActiveFor($this->websiteId, $normalizedUrl, $user->id);
        if ($active instanceof CustomPageAudit) {
            $this->setMessage('Already queued — see the row at the top of the list. It will update itself.', 'info');
            $this->resetForm();

            return;
        }

        $serpGlOverride = null;
        if ($this->awaitingSerpCountryChoice) {
            if (! SerpGlCatalog::isAllowedGl($this->serpCountryGl)) {
                $this->setMessage('Pick a valid country for the SERP sample.', 'error');

                return;
            }
            $serpGlOverride = strtolower(trim($this->serpCountryGl));
            $this->awaitingSerpCountryChoice = false;
            $this->serpCountryRecommendationHint = null;
        } else {
            // Lightweight metadata-only fetch to suggest the SERP country — stays synchronous
            // because it's fast (single HEAD/GET for <head>) and the user needs to pick before we queue.
            $peek = $pageAuditService->peekSerpCountryChoiceNeeded($this->websiteId, $normalizedUrl, true);
            if (! ($peek['ok'] ?? false)) {
                $this->setMessage($peek['error'] ?? 'Could not read the page to detect locale.', 'error');

                return;
            }
            $this->serpCountryGl = (string) ($peek['recommended_gl'] ?? 'us');
            if (! SerpGlCatalog::isAllowedGl($this->serpCountryGl)) {
                $this->serpCountryGl = 'us';
            }
            $this->serpCountryRecommendationHint = (string) ($peek['recommendation_hint'] ?? '');
            $this->awaitingSerpCountryChoice = true;
            $this->setMessage('Confirm the Google SERP country below (pre-selected from your page), then click “Run audit” again.', 'info');

            return;
        }

        $rateKey = 'custom-audit:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $this->setMessage("Too many audits. Try again in {$seconds}s.", 'error');

            return;
        }
        RateLimiter::hit($rateKey, 120);

        $audit = CustomPageAudit::queue(
            websiteId: $this->websiteId,
            userId: $user->id,
            pageUrl: $normalizedUrl,
            targetKeyword: $keyword,
            serpSampleGl: $serpGlOverride,
            source: CustomPageAudit::SOURCE_CUSTOM,
        );

        RunCustomPageAudit::dispatch($audit->id);

        $this->resetForm();
        $this->setMessage(
            'Audit queued — it will update automatically in the list below. You can close this tab and come back.',
            'success'
        );
    }

    /**
     * Re-queue a failed audit. Resets status + drops the previous error message.
     */
    public function retryAudit(string $auditId): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $audit = CustomPageAudit::query()->find($auditId);
        if (! $audit instanceof CustomPageAudit) {
            return;
        }
        if ($audit->user_id !== $user->id && ! $user->canViewWebsiteId($audit->website_id)) {
            return;
        }
        if (! $audit->canRetry()) {
            return;
        }

        $active = CustomPageAudit::findActiveFor($audit->website_id, $audit->page_url, $user->id);
        if ($active instanceof CustomPageAudit) {
            $this->setMessage('An audit for that URL is already queued.', 'info');

            return;
        }

        $rateKey = 'custom-audit:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $this->setMessage("Too many audits. Try again in {$seconds}s.", 'error');

            return;
        }
        RateLimiter::hit($rateKey, 120);

        $audit->forceFill([
            'status' => CustomPageAudit::STATUS_QUEUED,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        RunCustomPageAudit::dispatch($audit->id);

        $this->setMessage('Audit re-queued.', 'info');
    }

    private function resetForm(): void
    {
        $this->pageUrl = '';
        $this->targetKeyword = '';
        $this->awaitingSerpCountryChoice = false;
        $this->serpCountryRecommendationHint = null;
        $this->resetValidation();
    }

    private function normalizePageUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        return $raw;
    }

    private function setMessage(string $text, string $kind): void
    {
        $this->message = $text;
        $this->messageKind = $kind;
    }

    public function render()
    {
        $recentAudits = collect();
        $website = null;
        $hasPending = false;

        if (($this->websiteId !== null && $this->websiteId !== '') && Auth::check() && Auth::user()->canViewWebsiteId($this->websiteId)) {
            $website = Website::query()->find($this->websiteId);
            $recentAudits = CustomPageAudit::query()
                ->where('website_id', $this->websiteId)
                ->portalHistory()
                ->with(['user:id,name', 'pageAuditReport'])
                ->latest()
                ->limit(50)
                ->get();
            $hasPending = $recentAudits->contains(fn (CustomPageAudit $a) => $a->isPending());
        }

        return view('livewire.pages.custom-audit', [
            'recentAudits' => $recentAudits,
            'website' => $website,
            'hasPending' => $hasPending,
        ]);
    }
}
