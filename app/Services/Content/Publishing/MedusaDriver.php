<?php

namespace App\Services\Content\Publishing;

use App\Models\ContentIntegration;

/**
 * Medusa (medusajs.com) destination — a guided receiver, not a vendor API.
 *
 * Medusa v2 ships NO blog/article API (their docs' answer to "blog" is a
 * custom module), so this driver publishes to a fixed route that OUR paste-in
 * receiver kit adds to the client's Medusa project (see
 * resources/snippets/medusa/ + the connect card's step-by-step guide). The
 * wire contract is the exact WebhookDriver payload + HMAC signature — the kit
 * is simply a pre-written receiver for it, so everything (test delivery,
 * update-over-same-id, url echo) is inherited.
 *
 * Credentials shape: {base_url, secret}. The endpoint is always
 * {base_url}/serfix/articles — the path our kit's route file registers.
 */
class MedusaDriver extends WebhookDriver
{
    /** The API route path the receiver kit installs. */
    public const ARTICLES_PATH = '/serfix/articles';

    public function verify(ContentIntegration $integration): PublishResult
    {
        $result = parent::verify($integration);
        if ($result->ok) {
            return $result;
        }

        // Translate raw HTTP outcomes into Medusa-setup guidance — the two
        // dominant first-connect failures are "kit not installed yet" and
        // "SERFIX_SECRET env doesn't match".
        $status = (int) ($result->response['status'] ?? 0);
        if ($status === 404) {
            return PublishResult::failure(
                'Your Medusa server answered, but the Serfix receiver route was not found. '
                .'Install the receiver files from the guide below, restart Medusa, then try again.',
                response: $result->response,
            );
        }
        if (in_array($status, [401, 403], true)) {
            return PublishResult::failure(
                'Your Medusa server rejected the signature — the secret here and the SERFIX_SECRET '
                .'environment variable on your Medusa server must be identical.',
                response: $result->response,
            );
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    protected function connection(ContentIntegration $integration): array
    {
        $creds = $integration->credentials !== null ? $integration->credentials->toArray() : [];
        $base = trim((string) ($creds['base_url'] ?? ''));
        $secret = trim((string) ($creds['secret'] ?? ''));

        if ($base === '' || $secret === '') {
            return ['', '', 'The Medusa connection is missing its server URL or signing secret.'];
        }
        // Same transport rule as the parent (the HMAC stops forgery, not
        // disclosure), phrased for this platform.
        if (! str_starts_with(strtolower($base), 'https://')) {
            return ['', '', 'The Medusa server URL must use https:// — articles are sent over the public internet.'];
        }

        $endpoint = rtrim($base, '/').self::ARTICLES_PATH;
        $check = $this->guard->check($endpoint);
        if (! ($check['ok'] ?? false)) {
            return ['', '', 'Your Medusa server URL is not reachable from our servers.'];
        }

        return [$endpoint, $secret, null];
    }
}
