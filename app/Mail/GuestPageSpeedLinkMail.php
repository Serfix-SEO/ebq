<?php

namespace App\Mail;

use App\Models\GuestPageSpeed;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a guest who supplied their email on their second free PageSpeed
 * test. Delivers the report link + a nudge to sign up for the full,
 * continuous, GSC/GA-powered tooling.
 */
class GuestPageSpeedLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GuestPageSpeed $report)
    {
        $this->locale($report->locale ?? app()->getLocale());
    }

    public function envelope(): Envelope
    {
        $host = parse_url($this->report->url, PHP_URL_HOST) ?: $this->report->url;
        $host = mb_substr(preg_replace('/[\r\n\t]+/', '', (string) $host), 0, 100);

        return new Envelope(subject: "Your free PageSpeed report — {$host}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-pagespeed-link',
            with: [
                'report' => $this->report,
                'resultsUrl' => route('guest-pagespeed.show', $this->report),
                'registerUrl' => route('register'),
            ],
        );
    }
}
