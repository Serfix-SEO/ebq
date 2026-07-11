<?php

namespace App\Mail;

use App\Models\BugReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the REPORTER when an admin marks their bug report resolved,
 * including the admin's resolution note. Client-facing: neutral wording
 * only — the note the admin writes is shown verbatim, so it must be
 * written for the customer, not as an internal changelog entry.
 */
class BugReportResolved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BugReport $report,
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($report->user?->locale));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your report is resolved — thanks for helping us improve Serfix');
    }

    public function content(): Content
    {
        $r = $this->report;
        $name = e($r->user?->name ?: 'there');
        $reported = nl2br(e(\Illuminate\Support\Str::limit($r->description, 400)));
        $note = nl2br(e((string) $r->resolution_note));
        $pageUrl = e($r->url);
        $appUrl = rtrim(config('app.public_url', config('app.url')), '/');

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">Hi {$name},</h2>
    <p style="line-height:1.6;">Good news — the issue you reported on Serfix has been resolved.</p>
    <p style="margin:16px 0 6px;font-size:12px;font-weight:600;color:#5A5A5A;text-transform:uppercase;letter-spacing:0.08em;">You reported</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 16px;line-height:1.6;font-size:14px;">
        {$reported}
        <div style="margin-top:6px;font-size:12px;color:#5A5A5A;">on <a href="{$pageUrl}" style="color:#C44E0E;">{$pageUrl}</a></div>
    </div>
    <p style="margin:16px 0 6px;font-size:12px;font-weight:600;color:#5A5A5A;text-transform:uppercase;letter-spacing:0.08em;">What changed</p>
    <div style="background:#FFF3EA;border:1px solid #F26419;border-radius:8px;padding:12px 16px;line-height:1.6;font-size:14px;">{$note}</div>
    <p style="line-height:1.6;margin-top:16px;">Thank you for taking the time to report it — feedback like yours makes Serfix better for everyone.</p>
    <p style="margin:24px 0;">
        <a href="{$appUrl}/dashboard" style="background:#F26419;color:#ffffff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Back to Serfix</a>
    </p>
    <p style="font-size:12px;color:#5A5A5A;line-height:1.6;">If anything still looks off, just reply to this email or send a new report from the app.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
