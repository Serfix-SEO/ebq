<?php

namespace App\Mail;

use App\Models\GuestKeywordVolume;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a guest who supplied their email on their second free keyword
 * volume check. Delivers the report link + a nudge to sign up for bulk volume
 * research.
 */
class GuestKeywordVolumeLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GuestKeywordVolume $report)
    {
        $this->locale(\App\Support\LocaleConfig::resolve($report->locale));
    }

    public function envelope(): Envelope
    {
        $kw = mb_substr(preg_replace('/[\r\n\t]+/', ' ', (string) $this->report->keyword), 0, 80);

        return new Envelope(subject: "Your free keyword volume report — “{$kw}”");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-volume-link',
            with: [
                'report' => $this->report,
                'resultsUrl' => route('guest-volume.show', $this->report),
                'registerUrl' => route('register'),
            ],
        );
    }
}
