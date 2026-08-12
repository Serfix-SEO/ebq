<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to ADMINS when a customer opens a ticket or replies to one.
 * Admin-facing, English-only — never localized.
 */
class SupportTicketActivity extends Mailable
{
    public function __construct(
        public SupportTicket $ticket,
        public SupportTicketMessage $message,
        public bool $isNew,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->isNew ? 'New support ticket' : 'Ticket reply';

        return new Envelope(subject: '[Serfix] '.$prefix.' from '.($this->ticket->user?->email ?? 'unknown'));
    }

    public function content(): Content
    {
        $email = e($this->ticket->user?->email ?? 'unknown');
        $subject = e($this->ticket->subject);
        // Already whitelist-sanitized (HtmlSanitizer) — safe to embed as-is.
        $body = $this->message->bodyHtml();
        $domain = e($this->ticket->website?->domain ?? '—');
        $appUrl = rtrim(config('app.public_url', config('app.url')), '/');
        $adminUrl = $appUrl.'/admin/support/'.$this->ticket->id;
        $kind = $this->isNew ? 'opened a new support ticket' : 'replied to a support ticket';

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">{$email} {$kind}</h2>
    <p style="margin:16px 0 6px;font-size:12px;font-weight:600;color:#5A5A5A;text-transform:uppercase;letter-spacing:0.08em;">Subject</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 16px;font-size:14px;">{$subject}<div style="margin-top:6px;font-size:12px;color:#5A5A5A;">website: {$domain}</div></div>
    <p style="margin:16px 0 6px;font-size:12px;font-weight:600;color:#5A5A5A;text-transform:uppercase;letter-spacing:0.08em;">Message</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 16px;line-height:1.6;font-size:14px;">{$body}</div>
    <p style="margin:24px 0;">
        <a href="{$adminUrl}" style="background:#F26419;color:#ffffff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Open in admin</a>
    </p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
