<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebsiteAccessGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Website $website,
        public User $member,
    ) {
        $this->locale($member->locale ?? app()->getLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You now have access to {$this->website->domain}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.website-access-granted',
        );
    }
}
