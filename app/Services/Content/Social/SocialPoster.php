<?php

namespace App\Services\Content\Social;

use App\Models\ContentSocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts article links to a website's connected social accounts. NEVER throws —
 * always returns a typed result so the publish flow stays best-effort:
 *   array{ok: bool, status: 'posted'|'reconnect'|'skipped'|'error', message: string}
 *
 * 'reconnect' means the token is expired/revoked — the caller flips the
 * account to STATUS_ERROR so the Integrations card shows a reconnect prompt.
 * Client-copy invariant: messages may reach the card's last_error; keep them
 * plain ("Facebook declined the post"), never token/tier internals.
 */
class SocialPoster
{
    public static function facebookConfigured(): bool
    {
        return (string) config('services.facebook.client_id') !== '';
    }

    public static function xConfigured(): bool
    {
        return (string) config('services.x.client_id') !== '';
    }

    /**
     * Compose the post text for a network. Facebook gets title + summary (the
     * link is a separate param and renders as an OG card). X gets title + URL,
     * truncated so the total stays ≤ 275 (t.co wraps any URL to 23 chars).
     */
    public static function compose(string $provider, string $title, string $summary, string $url): string
    {
        $title = trim($title);
        $summary = trim($summary);
        if ($provider === ContentSocialAccount::PROVIDER_X) {
            $budget = 275 - 24; // 23 for the t.co link + newline
            if (mb_strlen($title) > $budget) {
                $title = rtrim(mb_substr($title, 0, $budget - 1)).'…';
            }

            return $title."\n".$url;
        }

        $text = $title;
        if ($summary !== '') {
            $text .= "\n\n".mb_substr($summary, 0, 400);
        }

        return $text;
    }

    /** @return array{ok: bool, status: string, message: string} */
    public function post(ContentSocialAccount $account, string $text, string $url): array
    {
        try {
            return match ($account->provider) {
                ContentSocialAccount::PROVIDER_FACEBOOK => $this->postToFacebook($account, $text, $url),
                ContentSocialAccount::PROVIDER_X => $this->postToX($account, $text),
                default => ['ok' => false, 'status' => 'skipped', 'message' => 'Unknown provider'],
            };
        } catch (\Throwable $e) {
            Log::warning('content_social.post_threw', [
                'account_id' => $account->id, 'provider' => $account->provider,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['ok' => false, 'status' => 'error', 'message' => 'Could not reach '.$account->providerLabel().'.'];
        }
    }

    /** @return array{ok: bool, status: string, message: string} */
    private function postToFacebook(ContentSocialAccount $account, string $text, string $url): array
    {
        $creds = (array) $account->credentials;
        $pageId = (string) ($creds['page_id'] ?? '');
        $token = (string) ($creds['page_token'] ?? '');
        if ($pageId === '' || $token === '') {
            return ['ok' => false, 'status' => 'reconnect', 'message' => 'Facebook connection needs to be renewed.'];
        }

        $version = (string) config('services.facebook.graph_version', 'v21.0');
        $response = Http::timeout(20)->connectTimeout(8)
            ->asForm()
            ->post("https://graph.facebook.com/{$version}/{$pageId}/feed", [
                'message' => $text,
                'link' => $url,
                'access_token' => $token,
            ]);

        if ($response->successful() && $response->json('id')) {
            return ['ok' => true, 'status' => 'posted', 'message' => (string) $response->json('id')];
        }
        // Graph error 190 = invalid/expired token; 200-299 = permission errors.
        $code = (int) $response->json('error.code', 0);
        if (in_array($response->status(), [401, 403], true) || $code === 190 || ($code >= 200 && $code < 300)) {
            return ['ok' => false, 'status' => 'reconnect', 'message' => 'Facebook connection needs to be renewed.'];
        }
        Log::warning('content_social.facebook_error', [
            'account_id' => $account->id, 'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 300),
        ]);

        return ['ok' => false, 'status' => 'error', 'message' => 'Facebook declined the post.'];
    }

    /** @return array{ok: bool, status: string, message: string} */
    private function postToX(ContentSocialAccount $account, string $text): array
    {
        $token = $this->freshXToken($account);
        if ($token === null) {
            return ['ok' => false, 'status' => 'reconnect', 'message' => 'X connection needs to be renewed.'];
        }

        $response = Http::timeout(20)->connectTimeout(8)
            ->withToken($token)
            ->post('https://api.x.com/2/tweets', ['text' => $text]);

        if ($response->successful() && $response->json('data.id')) {
            return ['ok' => true, 'status' => 'posted', 'message' => (string) $response->json('data.id')];
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'status' => 'reconnect', 'message' => 'X connection needs to be renewed.'];
        }
        Log::warning('content_social.x_error', [
            'account_id' => $account->id, 'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 300),
        ]);

        return ['ok' => false, 'status' => 'error', 'message' => 'X declined the post.'];
    }

    /**
     * X access tokens live ~2h. Refresh (and ROTATE — X invalidates the old
     * refresh token) when within 5 minutes of expiry. Null = reconnect needed.
     */
    private function freshXToken(ContentSocialAccount $account): ?string
    {
        $creds = (array) $account->credentials;
        $access = (string) ($creds['access_token'] ?? '');
        $refresh = (string) ($creds['refresh_token'] ?? '');
        $expiresAt = (int) ($creds['expires_at'] ?? 0);
        if ($access === '') {
            return null;
        }
        if ($expiresAt === 0 || $expiresAt > now()->addMinutes(5)->timestamp) {
            return $access;
        }
        if ($refresh === '') {
            return null;
        }

        $response = Http::timeout(20)->connectTimeout(8)
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
            ->asForm()
            ->post('https://api.x.com/2/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
            ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            return null;
        }

        $creds['access_token'] = (string) $response->json('access_token');
        $creds['refresh_token'] = (string) ($response->json('refresh_token') ?: $refresh);
        $creds['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 7200))->timestamp;
        $account->forceFill(['credentials' => $creds])->save();

        return $creds['access_token'];
    }
}
