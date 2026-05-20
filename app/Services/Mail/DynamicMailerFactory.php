<?php

namespace App\Services\Mail;

use App\Models\MailTransport as MailTransportModel;
use Illuminate\Container\Container;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a per-send Laravel {@see Mailer} instance from a stored
 * {@see MailTransportModel} row. SMTP is the only transport that goes
 * through Symfony's mailer here; Gmail and Outlook are handled by their
 * own sender services that POST raw MIME to the respective APIs (see
 * {@see GmailMailSender} and {@see OutlookMailSender}).
 *
 * Why not register a global "dynamic" mailer? Each tenant's SMTP creds
 * are different — registering one would force serialising tenant config
 * through the global container, which leaks across queue jobs.
 */
class DynamicMailerFactory
{
    public function buildSmtpMailer(MailTransportModel $transport): Mailer
    {
        if ($transport->provider !== MailTransportModel::PROVIDER_SMTP) {
            throw new \InvalidArgumentException('buildSmtpMailer requires an smtp transport row.');
        }

        $symfonyTransport = $this->buildSymfonyTransport($transport);

        // Mirror the constructor signature `app('mailer')` uses, minus
        // the global config — we just need views, events, and the
        // pre-built transport.
        $container = Container::getInstance();
        $mailer = new Mailer(
            name: 'dynamic-smtp-' . $transport->id,
            views: $container->make('view'),
            transport: $symfonyTransport,
            events: $container->make('events'),
        );
        $mailer->alwaysFrom($transport->from_address, $transport->display_name ?? null);

        return $mailer;
    }

    /**
     * Direct EsmtpTransport instance. Exposed separately so the
     * "send test email" flow can use it without paying the Mailer
     * wrapper cost.
     */
    public function buildSymfonyTransport(MailTransportModel $transport): TransportInterface
    {
        // `tls` arg: true = implicit TLS on the wire (SMTPS / port 465),
        // null = STARTTLS upgrade, false = plain connection. STARTTLS is
        // the right default for port 587; explicit SSL is only used when
        // the operator picks it for legacy SMTPS endpoints.
        $symfony = new EsmtpTransport(
            host: (string) $transport->smtp_host,
            port: (int) ($transport->smtp_port ?? 587),
            tls: match ($transport->smtp_encryption) {
                'ssl' => true,
                'tls' => null,
                default => false,
            },
        );
        if ($transport->smtp_username !== null && $transport->smtp_username !== '') {
            $symfony->setUsername($transport->smtp_username);
        }
        if ($transport->smtp_password !== null && $transport->smtp_password !== '') {
            $symfony->setPassword($transport->smtp_password);
        }

        return $symfony;
    }
}
