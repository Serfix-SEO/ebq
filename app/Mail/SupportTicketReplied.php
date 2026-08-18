<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Sent to the CUSTOMER when the team replies to their support ticket.
 * Client-facing: the admin's reply is shown verbatim, so it must be written
 * for the customer. Sent synchronously (local Postal relay) and wrapped in
 * try/catch at the call site so mail failure never breaks the reply action.
 */
class SupportTicketReplied extends Mailable
{
    public function __construct(
        public SupportTicket $ticket,
        public SupportTicketMessage $message,
        /** True when WE opened the thread — "replied to your ticket" would be a lie. */
        public bool $isNew = false,
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($ticket->user?->locale));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->isNew ? 'A message from the Serfix team: ' : 'New reply to your support ticket: ')
            .Str::limit($this->ticket->subject, 80));
    }

    public function content(): Content
    {
        $name = e($this->ticket->user?->name ?: 'there');
        $subject = e($this->ticket->subject);
        // Already whitelist-sanitized (HtmlSanitizer) — safe to embed as-is.
        $reply = $this->message->bodyHtml();
        $intro = $this->isNew
            ? 'We\'ve opened a support conversation with you about <strong>"'.$subject.'"</strong>.'
            : 'We\'ve replied to your support ticket <strong>"'.$subject.'"</strong>.';
        $appUrl = rtrim(config('app.public_url', config('app.url')), '/');
        $ticketUrl = $appUrl.'/support/'.$this->ticket->id;

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">Hi {$name},</h2>
    <p style="line-height:1.6;">{$intro}</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px 16px;line-height:1.6;font-size:14px;">{$reply}</div>
    <p style="margin:24px 0;">
        <a href="{$ticketUrl}" style="background:#F26419;color:#ffffff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;">View & respond</a>
    </p>
    <p style="font-size:12px;color:#5A5A5A;line-height:1.6;">You can reply from the Support page in your dashboard — we'll pick it up right away.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
