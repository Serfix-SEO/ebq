<?php

namespace App\Livewire\Pages;

use App\Mail\PageAuditReportMail;
use App\Models\PageAuditReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class PageAuditDetail extends Component
{
    public PageAuditReport $pageAuditReport;

    public string $auditEmail = '';

    public ?string $auditMessage = null;

    public string $auditMessageKind = 'info';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->canViewWebsiteId($this->pageAuditReport->website_id), 403);
    }

    public function emailAuditReport(): void
    {
        $this->auditMessage = null;

        $user = Auth::user();
        if (! $user || ! $user->canViewWebsiteId($this->pageAuditReport->website_id)) {
            $this->setAuditMessage('You do not have permission to email this report.', 'error');

            return;
        }

        $rateKey = 'email-audit:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $this->setAuditMessage("Too many emails. Try again in {$seconds}s.", 'error');

            return;
        }

        $validator = Validator::make(['email' => $this->auditEmail], ['email' => ['required', 'email']]);
        if ($validator->fails()) {
            $this->setAuditMessage('Please enter a valid email address.', 'error');

            return;
        }

        try {
            RateLimiter::hit($rateKey, 300);
            Mail::to($this->auditEmail)->send(new PageAuditReportMail($this->pageAuditReport, $user));
            $this->setAuditMessage("Audit report emailed to {$this->auditEmail}.", 'success');
            $this->auditEmail = '';
        } catch (\Throwable $e) {
            $this->setAuditMessage('Failed to send email: '.$e->getMessage(), 'error');
        }
    }

    public function render()
    {
        // NOTE: the competitor-backlink lazy refresh that lived here was
        // removed 2026-07-14 with the paid-KE suppression — page audits no
        // longer show a competitor backlink table at all.

        return view('livewire.pages.page-audit-detail');
    }

    private function setAuditMessage(string $message, string $kind = 'info'): void
    {
        $this->auditMessage = $message;
        $this->auditMessageKind = in_array($kind, ['success', 'info', 'error'], true) ? $kind : 'info';
    }
}
