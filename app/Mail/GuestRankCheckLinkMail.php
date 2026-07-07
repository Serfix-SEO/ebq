<?php

namespace App\Mail;

use App\Models\GuestRankCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a guest who supplied their email on their second free rank check.
 * Delivers the report link + a nudge to sign up for continuous, multi-keyword
 * rank tracking.
 */
class GuestRankCheckLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GuestRankCheck $report)
    {
        $this->locale($report->locale ?? app()->getLocale());
    }

    public function envelope(): Envelope
    {
        $kw = mb_substr(preg_replace('/[\r\n\t]+/', ' ', (string) $this->report->keyword), 0, 80);

        return new Envelope(subject: "Your free rank report — “{$kw}”");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-rank-link',
            with: [
                'report' => $this->report,
                'resultsUrl' => route('guest-rank.show', $this->report),
                'registerUrl' => route('register'),
            ],
        );
    }
}
