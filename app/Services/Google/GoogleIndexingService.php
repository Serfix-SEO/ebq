<?php

namespace App\Services\Google;

use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Submits a single URL to the Google Indexing API
 * (indexing.googleapis.com/v3/urlNotifications:publish, URL_UPDATED) using the
 * website's connected Search Console Google account.
 *
 * One source of truth for "ask Google to (re)index this URL" — the same call
 * PageDetail::requestReindex and the plugin HQ endpoint make. Gated on
 * Website::hasGsc(); a site without Search Console gets ['status' => 'not_connected']
 * and the caller shows the connect notice instead. NEVER throws — always returns
 * a typed result so publish flows can stay best-effort.
 */
class GoogleIndexingService
{
    public function __construct(private readonly GoogleClientFactory $clientFactory) {}

    /**
     * @return array{ok: bool, status: string, message: ?string}
     *                                                           status ∈ submitted | not_connected | reconnect | invalid | error
     */
    public function submitUrl(Website $website, ?string $url, string $type = 'URL_UPDATED'): array
    {
        $url = trim((string) $url);
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return ['ok' => false, 'status' => 'invalid', 'message' => 'No live URL to submit.'];
        }
        if (! $website->hasGsc()) {
            return ['ok' => false, 'status' => 'not_connected', 'message' => 'Search Console is not connected.'];
        }
        $account = $website->gscAccountResolved();
        if ($account === null) {
            return ['ok' => false, 'status' => 'not_connected', 'message' => 'No connected Google account.'];
        }

        try {
            $token = (string) ($this->clientFactory->make($account)->getAccessToken()['access_token'] ?? '');
            if ($token === '') {
                return ['ok' => false, 'status' => 'reconnect', 'message' => 'Reconnect Google to grant indexing access.'];
            }

            $response = Http::withToken($token)->acceptJson()->timeout(20)
                ->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
                    'url' => $url,
                    'type' => $type,
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'status' => 'submitted', 'message' => null];
            }

            $message = (string) data_get($response->json(), 'error.message', 'Google rejected the request.');
            $reconnect = str_contains(strtolower($message), 'insufficient') || in_array($response->status(), [401, 403], true);
            Log::warning('google_indexing.submit_failed', [
                'website_id' => $website->id,
                'url' => $url,
                'http' => $response->status(),
                'message' => mb_substr($message, 0, 300),
            ]);

            return ['ok' => false, 'status' => $reconnect ? 'reconnect' : 'error', 'message' => $message];
        } catch (\Throwable $e) {
            $reconnect = str_contains(strtolower($e->getMessage()), 'insufficient');
            Log::warning('google_indexing.submit_exception', [
                'website_id' => $website->id,
                'url' => $url,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['ok' => false, 'status' => $reconnect ? 'reconnect' : 'error', 'message' => $e->getMessage()];
        }
    }
}
