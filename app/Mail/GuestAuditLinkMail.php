<?php

namespace App\Mail;

use App\Models\GuestPageAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent (from the configured noreply@serfix.io) to a guest who supplied their email
 * on their second free audit. Delivers the link to the report + a nudge to sign
 * up for the full GSC/GA-powered audit.
 */
class GuestAuditLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GuestPageAudit $audit) {}

    public function envelope(): Envelope
    {
        $host = parse_url($this->audit->url, PHP_URL_HOST) ?: $this->audit->url;
        $host = mb_substr(preg_replace('/[\r\n\t]+/', '', (string) $host), 0, 100);

        return new Envelope(
            subject: "Your free SEO audit — {$host}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-audit-link',
            with: [
                'audit' => $this->audit,
                'resultsUrl' => route('guest-audit.show', $this->audit),
                'registerUrl' => route('register'),
            ],
        );
    }
}
