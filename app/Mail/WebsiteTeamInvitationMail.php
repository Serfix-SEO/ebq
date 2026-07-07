<?php

namespace App\Mail;

use App\Models\WebsiteInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebsiteTeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WebsiteInvitation $invitation,
        public string $plainToken,
    ) {
        $this->locale(app()->getLocale());
    }

    public function envelope(): Envelope
    {
        $domain = $this->invitation->website->domain;

        return new Envelope(
            subject: "You're invited to collaborate on {$domain}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.website-team-invitation',
        );
    }
}
