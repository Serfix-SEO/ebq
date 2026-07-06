<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ops digest for platform admins: permanently-failed queue jobs (buffered in
 * real time by FailedJobAlertBuffer on every box) + crawl sites stuck pending
 * with subscribers. Sent by `ebq:failed-jobs-alert` (scheduled, web box).
 */
class FailedJobsDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $body,
        public int $failureCount,
        public int $stuckSiteCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[Serfix ops] %d failed job(s), %d stuck crawl site(s)',
                $this->failureCount,
                $this->stuckSiteCount,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre style="font-family:monospace">'.e($this->body).'</pre>');
    }
}
