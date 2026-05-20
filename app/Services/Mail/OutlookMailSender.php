<?php

namespace App\Services\Mail;

use App\Models\MailTransport;
use App\Models\MicrosoftAccount;
use App\Services\Microsoft\MicrosoftClientFactory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/**
 * Sends a rendered email via Microsoft Graph's
 * `POST /me/sendMail` endpoint, which accepts a raw MIME body when the
 * `Content-Type: text/plain` header is set on the request (Graph
 * detects the MIME boundary and routes the message as the connected
 * user). Scope required: `Mail.Send` (delegated).
 */
class OutlookMailSender
{
    public function __construct(
        private readonly MicrosoftClientFactory $clients,
    ) {}

    public function send(MailTransport $transport, Email $message): void
    {
        if ($transport->provider !== MailTransport::PROVIDER_OUTLOOK) {
            throw new \InvalidArgumentException('OutlookMailSender requires an outlook transport row.');
        }
        $account = $transport->oauthAccount();
        if (! $account instanceof MicrosoftAccount) {
            throw new \RuntimeException("Outlook transport #{$transport->id} has no linked MicrosoftAccount.");
        }

        $accessToken = $this->clients->validAccessTokenFor($account);

        // Graph's MIME-mode `sendMail` takes a base64-encoded RFC 2822
        // message; Content-Type MUST be `text/plain` despite the body
        // being binary — that's how Graph distinguishes raw MIME from
        // the JSON Message resource it normally accepts.
        $rawMime = base64_encode($message->toString());
        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'text/plain'])
            ->timeout(30)
            ->withBody($rawMime, 'text/plain')
            ->post('https://graph.microsoft.com/v1.0/me/sendMail');

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Outlook send failed: ' . $response->status() . ' ' . $response->body(),
            );
        }
    }
}
