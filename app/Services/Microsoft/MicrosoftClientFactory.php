<?php

namespace App\Services\Microsoft;

use App\Models\MicrosoftAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Returns a valid Microsoft Graph access token for a given account,
 * refreshing it via the OAuth refresh-token grant when expired.
 *
 * Mirrors the shape of {@see \App\Services\Google\GoogleClientFactory}
 * but doesn't return a full SDK client — callers use the raw token
 * with {@see \Illuminate\Support\Facades\Http} against Graph REST.
 */
class MicrosoftClientFactory
{
    public function validAccessTokenFor(MicrosoftAccount $account): string
    {
        // 30s buffer so we don't ship a token that expires mid-flight.
        if ($account->expires_at && now()->lt($account->expires_at->subSeconds(30))) {
            return (string) $account->access_token;
        }

        if (! $account->refresh_token) {
            throw new \RuntimeException(
                'Microsoft access token expired and no refresh token available. Please reconnect your Microsoft account in Settings.'
            );
        }

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => (string) config('services.microsoft.client_id'),
            'client_secret' => (string) config('services.microsoft.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            // Re-requesting `offline_access` ensures a refresh token
            // comes back; Microsoft rotates refresh tokens on each use.
            'scope' => 'offline_access Mail.Send User.Read',
        ]);

        if (! $response->successful()) {
            Log::error('Microsoft token refresh failed', [
                'account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException(
                'Failed to refresh Microsoft token (HTTP '.$response->status().'). Please reconnect your Microsoft account in Settings.'
            );
        }

        $payload = $response->json();
        $update = [
            'access_token' => (string) ($payload['access_token'] ?? ''),
            'expires_at' => now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
        ];
        if (! empty($payload['refresh_token'])) {
            $update['refresh_token'] = (string) $payload['refresh_token'];
        }
        $account->update($update);

        return (string) $account->access_token;
    }
}
