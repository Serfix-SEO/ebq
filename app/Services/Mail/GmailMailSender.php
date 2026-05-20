<?php

namespace App\Services\Mail;

use App\Models\GoogleAccount;
use App\Models\MailTransport;
use App\Services\Google\GoogleClientFactory;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Symfony\Component\Mime\Email;

/**
 * Sends an already-rendered email via the Gmail API using the
 * `gmail.send` scope. The OAuth client is built from the
 * `GoogleAccount` row pointed to by `mail_transports.oauth_account_id`.
 *
 * The Gmail API takes a base64url-encoded RFC 2822 message — we hand it
 * the raw MIME emitted by Symfony's serializer. Gmail rewrites the
 * `From` header to the connected account's address regardless of what
 * we send, which is the SPF/DKIM correctness story.
 */
class GmailMailSender
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
    ) {}

    public function send(MailTransport $transport, Email $message): void
    {
        if ($transport->provider !== MailTransport::PROVIDER_GMAIL) {
            throw new \InvalidArgumentException('GmailMailSender requires a gmail transport row.');
        }
        $account = $transport->oauthAccount();
        if (! $account instanceof GoogleAccount) {
            throw new \RuntimeException("Gmail transport #{$transport->id} has no linked GoogleAccount.");
        }

        // GoogleClientFactory::make() refreshes the access token if
        // needed. The scope is baked into the token itself — set during
        // the OAuth consent (see GoogleOAuthController::redirectMailScope).
        $client = $this->clients->make($account);

        $service = new Gmail($client);
        $gmailMessage = new Message();
        // toString() emits a fully-formed RFC 2822 message including
        // headers + body parts + boundaries; Gmail accepts that as-is
        // once base64url-encoded.
        $gmailMessage->setRaw(rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '='));

        $service->users_messages->send('me', $gmailMessage);
    }
}
