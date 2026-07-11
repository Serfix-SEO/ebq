<?php

namespace App\Mail;

use App\Models\PageAuditReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PageAuditReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PageAuditReport $auditReport,
        public ?User $timezoneContextUser = null,
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($this->timezoneContextUser->locale));
    }

    public function envelope(): Envelope
    {
        $host = parse_url($this->auditReport->page, PHP_URL_HOST) ?? $this->auditReport->page;
        $host = mb_substr(preg_replace('/[\r\n\t]+/', '', (string) $host), 0, 100);

        return new Envelope(
            subject: "Serfix Page Audit — {$host}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'pages.partials.audit-report-export',
            with: [
                'auditReport' => $this->auditReport,
                'timezoneContextUser' => $this->timezoneContextUser,
            ],
        );
    }
}
