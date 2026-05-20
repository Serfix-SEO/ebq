<?php

namespace App\Services\Reports;

use App\Mail\GrowthReportMail;
use App\Models\MailTransport;
use App\Models\User;
use App\Models\Website;
use App\Services\Mail\DynamicMailerFactory;
use App\Services\Mail\GmailMailSender;
use App\Services\Mail\MailTransportResolver;
use App\Services\Mail\OutlookMailSender;
use Illuminate\Support\Facades\Mail;

/**
 * Single entry point used by every site that dispatches a growth
 * report email. Handles branding resolution and transport routing so
 * callers don't have to think about it.
 *
 * Routing matrix:
 *   transport == null         → Mail::to()->queue() (default mailer)
 *   transport == smtp         → render mailable, send via custom EsmtpTransport
 *   transport == gmail        → render mailable, GmailMailSender (Gmail API)
 *   transport == outlook      → render mailable, OutlookMailSender (Graph API)
 *
 * OAuth + custom-SMTP sends are NOT queued via Laravel's mailer queue
 * because the queue worker would need access to per-tenant OAuth/SMTP
 * config — instead the dispatcher itself can be queued, or callers can
 * call ::send() inline (the SendGrowthReports command processes one
 * site at a time and is rate-limited by chunkById).
 */
class ReportMailDispatcher
{
    public function __construct(
        private readonly ReportBrandingResolver $brandingResolver,
        private readonly MailTransportResolver $transportResolver,
        private readonly DynamicMailerFactory $mailers,
        private readonly GmailMailSender $gmail,
        private readonly OutlookMailSender $outlook,
    ) {}

    /**
     * Build a configured {@see GrowthReportMail} with the right
     * branding applied. Useful when a caller wants to render the
     * mailable for preview without sending.
     */
    public function build(User $recipient, Website $website, string $startDate, string $endDate, string $reportType = 'daily'): GrowthReportMail
    {
        $branding = $this->brandingResolver->for($website->owner, $website);
        return new GrowthReportMail($recipient, $website, $startDate, $endDate, $reportType, $branding);
    }

    /**
     * Send (or queue) the growth report to a single recipient. Returns
     * the transport that was used (null = global default) for logging.
     */
    public function send(User $recipient, Website $website, string $startDate, string $endDate, string $reportType = 'daily'): ?MailTransport
    {
        $mailable = $this->build($recipient, $website, $startDate, $endDate, $reportType);
        $transport = $this->transportResolver->for($website->owner, $website);

        if ($transport === null) {
            // Existing behavior — let Laravel handle queuing through the
            // global mailer. The mailable still carries the branding.
            Mail::to($recipient->email)->queue($mailable);
            return null;
        }

        $mailable->to($recipient->email);

        if ($transport->provider === MailTransport::PROVIDER_SMTP) {
            $this->mailers->buildSmtpMailer($transport)->send($mailable);
            $this->markVerified($transport);
            return $transport;
        }

        // OAuth providers (Gmail / Outlook) — render the mailable to a
        // raw Symfony Email and hand it to the provider-specific sender.
        // We don't go through Laravel's mailer pipeline because the
        // OAuth send APIs accept fully-formed MIME and there's no need
        // to construct a Symfony Transport for them.
        $symfonyEmail = $this->buildSymfonyEmailFor($mailable, $transport, $recipient->email);

        try {
            if ($transport->provider === MailTransport::PROVIDER_GMAIL) {
                $this->gmail->send($transport, $symfonyEmail);
            } else {
                $this->outlook->send($transport, $symfonyEmail);
            }
            $this->markVerified($transport);
        } catch (\Throwable $e) {
            $transport->forceFill([
                'last_error' => substr($e->getMessage(), 0, 1000),
            ])->save();
            throw $e;
        }

        return $transport;
    }

    /**
     * Render the mailable into a raw Symfony Email for the OAuth send
     * path. We mirror what Laravel's mailer would normally do: pull the
     * subject + reply-to from `envelope()`, the body from `render()`,
     * and any attachments from `attachments()`.
     */
    private function buildSymfonyEmailFor(
        \App\Mail\GrowthReportMail $mailable,
        MailTransport $transport,
        string $recipientEmail,
    ): \Symfony\Component\Mime\Email {
        $envelope = $mailable->envelope();
        $html = $mailable->render();

        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address(
                $transport->from_address,
                $transport->display_name ?? '',
            ))
            ->to(new \Symfony\Component\Mime\Address($recipientEmail))
            ->subject((string) $envelope->subject)
            ->html($html);

        // Reply-To from branding takes precedence; envelope's replyTo
        // is the same value, surfaced for clarity.
        if ($mailable->branding->reply_to_email) {
            $email->replyTo(new \Symfony\Component\Mime\Address($mailable->branding->reply_to_email));
        }

        foreach ($mailable->attachments() as $att) {
            // Laravel's Attachment value object dispatches via two
            // strategies — one for path-backed attachments, one for
            // data-backed (closure) attachments. We bind both into
            // direct Symfony Email::attach() calls. The PDF path uses
            // the data strategy; the path strategy is included for
            // completeness so a future on-disk attachment doesn't
            // silently drop.
            $att->attachWith(
                pathStrategy: fn ($path) => $email->attachFromPath($path, $att->as, $att->mime),
                dataStrategy: fn ($data) => $email->attach($data(), $att->as, $att->mime),
            );
        }

        return $email;
    }

    private function markVerified(MailTransport $transport): void
    {
        $transport->forceFill([
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();
    }
}
