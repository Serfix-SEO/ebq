<?php

namespace App\Mail;

use App\Models\BugReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin notification for a new in-app bug report. Sent synchronously via
 * the local Postal relay from BugReportModal::submit() — admin-facing,
 * English-only by convention. Screenshot is linked (admin-gated route),
 * never attached.
 */
class BugReportSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BugReport $report,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Serfix] Bug report from '.($this->report->user?->email ?? 'unknown user'));
    }

    public function content(): Content
    {
        $r = $this->report;
        $user = e(($r->user?->name ? $r->user->name.' — ' : '').($r->user?->email ?? 'unknown'));
        $url = e($r->url);
        $description = nl2br(e($r->description));
        $env = e(trim(($r->viewport ?? '').' · '.($r->user_agent ?? ''), ' ·'));
        $listUrl = route('admin.bug-reports.index');
        $shotHtml = $r->screenshot_path
            ? '<p style="margin:12px 0;"><a href="'.route('admin.bug-reports.screenshot', $r).'" style="color:#C44E0E;font-weight:600;">View screenshot</a></p>'
            : '';

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">New bug report</h2>
    <p style="line-height:1.6;"><strong>From:</strong> {$user}<br>
    <strong>Page:</strong> <a href="{$url}">{$url}</a><br>
    <strong>Env:</strong> {$env}</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:14px 18px;margin:16px 0;line-height:1.6;">{$description}</div>
{$shotHtml}
    <p style="margin:24px 0;">
        <a href="{$listUrl}" style="background:#F26419;color:#ffffff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;">Open bug reports</a>
    </p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
