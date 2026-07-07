<?php

namespace App\Mail;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin-sent crawl-issues summary for a client website: the headline numbers
 * (health score + issue counts) plus the top few example errors. Built and
 * dispatched from the admin Marketing panel (MarketingController::send).
 */
class CrawlReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $report  numbers + examples snapshot
     */
    public function __construct(
        public Website $website,
        public array $report,
        public ?string $recipientName = null,
    ) {
        $this->locale($website->owner->locale ?? app()->getLocale());
    }

    public function envelope(): Envelope
    {
        $count = (int) ($this->report['counts']['total'] ?? 0);

        return new Envelope(
            subject: "Your {$this->website->domain} SEO crawl found {$count} ".\Illuminate\Support\Str::plural('issue', $count),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.crawl-report',
            with: [
                'website' => $this->website,
                'report' => $this->report,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}
