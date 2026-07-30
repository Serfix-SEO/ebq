<?php

namespace App\Http\Controllers;

use App\Models\ContentSocialAccount;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\TwitterProvider;

/**
 * OAuth connect flows for the Content Autopilot social auto-share
 * (Facebook Page + X). Mirrors {@see MicrosoftOAuthController}.
 *
 * The website being connected is pinned in the session at redirect time
 * (`content_social.website_id`) and re-authorized through
 * accessibleWebsitesQuery() at callback — the session id is never trusted
 * as an authorization token.
 *
 * Facebook: user OAuth → exchange for a long-lived user token → list managed
 * Pages. Exactly one Page auto-connects; multiple Pages are stashed in the
 * session for the Integrations card's page picker (page tokens derived from a
 * long-lived user token do not expire). X: OAuth2 + PKCE (session-based state
 * — PKCE requires the session verifier, so no stateless() here); access
 * tokens live ~2h and are refreshed at post time by SocialPoster.
 */
class SocialShareOAuthController extends Controller
{
    public function facebookRedirect(): RedirectResponse
    {
        $this->rememberWebsite();

        return Socialite::driver('facebook')
            ->scopes(['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'])
            ->redirect();
    }

    public function facebookCallback(): RedirectResponse
    {
        $website = $this->websiteOrAbort();
        try {
            $fbUser = Socialite::driver('facebook')->stateless()->user();
            $longLived = $this->longLivedFacebookToken((string) $fbUser->token) ?? (string) $fbUser->token;
            $pages = $this->facebookPages($longLived);
        } catch (\Throwable $e) {
            Log::warning('content_social.facebook_oauth_error', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return $this->back()->with('social-error', __('Facebook connection failed — please try again.'));
        }

        if ($pages === []) {
            return $this->back()->with('social-error', __('No Facebook Page found on that account. Auto-share posts to a Page — create one on Facebook first.'));
        }

        if (count($pages) === 1) {
            $this->storeFacebookPage($website, $pages[0]);

            return $this->back()->with('social-status', __('Facebook Page ":name" connected.', ['name' => $pages[0]['name']]));
        }

        // Multiple pages → the Integrations card shows a picker from session.
        session(['content_social.fb_pages' => $pages, 'content_social.fb_website_id' => $website->id]);

        return $this->back()->with('social-status', __('Choose which Facebook Page to post to.'));
    }

    public function xRedirect(): RedirectResponse
    {
        $this->rememberWebsite();

        return $this->xProvider()
            ->scopes(['tweet.read', 'tweet.write', 'users.read', 'offline.access'])
            ->redirect();
    }

    public function xCallback(): RedirectResponse
    {
        $website = $this->websiteOrAbort();
        try {
            $xUser = $this->xProvider()->user();
        } catch (\Throwable $e) {
            Log::warning('content_social.x_oauth_error', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return $this->back()->with('social-error', __('X connection failed — please try again.'));
        }

        ContentSocialAccount::query()->updateOrCreate(
            ['website_id' => $website->id, 'provider' => ContentSocialAccount::PROVIDER_X],
            [
                'credentials' => [
                    'access_token' => (string) $xUser->token,
                    'refresh_token' => (string) ($xUser->refreshToken ?? ''),
                    'expires_at' => now()->addSeconds((int) ($xUser->expiresIn ?? 7200))->timestamp,
                    'username' => (string) ($xUser->nickname ?? ''),
                ],
                'status' => ContentSocialAccount::STATUS_CONNECTED,
                'display_name' => $xUser->nickname ? '@'.$xUser->nickname : 'X',
                'last_error' => null,
            ],
        );

        return $this->back()->with('social-status', __('X account :name connected.', ['name' => $xUser->nickname ? '@'.$xUser->nickname : '']));
    }

    /** Store the chosen page (called by the Livewire picker via this shared helper). */
    public static function storeFacebookPage(Website $website, array $page): void
    {
        ContentSocialAccount::query()->updateOrCreate(
            ['website_id' => $website->id, 'provider' => ContentSocialAccount::PROVIDER_FACEBOOK],
            [
                'credentials' => [
                    'page_id' => (string) $page['id'],
                    'page_token' => (string) $page['access_token'],
                    'page_name' => (string) $page['name'],
                ],
                'status' => ContentSocialAccount::STATUS_CONNECTED,
                'display_name' => (string) $page['name'],
                'last_error' => null,
            ],
        );
    }

    private function xProvider(): TwitterProvider
    {
        // Socialite resolves config by driver name ("twitter-oauth-2"); our
        // config lives under services.x, so build the provider explicitly.
        /** @var TwitterProvider */
        return Socialite::buildProvider(TwitterProvider::class, (array) config('services.x'));
    }

    private function longLivedFacebookToken(string $shortToken): ?string
    {
        $version = (string) config('services.facebook.graph_version', 'v21.0');
        $response = Http::timeout(20)->connectTimeout(8)->get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        return $response->successful() ? ($response->json('access_token') ?: null) : null;
    }

    /** @return list<array{id: string, name: string, access_token: string}> */
    private function facebookPages(string $userToken): array
    {
        $version = (string) config('services.facebook.graph_version', 'v21.0');
        $response = Http::timeout(20)->connectTimeout(8)->get("https://graph.facebook.com/{$version}/me/accounts", [
            'fields' => 'id,name,access_token',
            'access_token' => $userToken,
        ]);
        if (! $response->successful()) {
            return [];
        }

        return collect((array) $response->json('data', []))
            ->filter(fn ($p) => is_array($p) && ! empty($p['id']) && ! empty($p['access_token']))
            ->map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? 'Page'), 'access_token' => (string) $p['access_token']])
            ->values()
            ->all();
    }

    private function rememberWebsite(): void
    {
        session(['content_social.website_id' => (string) session('current_website_id')]);
    }

    private function websiteOrAbort(): Website
    {
        $id = (string) session('content_social.website_id');
        $website = $id !== ''
            ? Auth::user()?->accessibleWebsitesQuery()->whereKey($id)->first()
            : null;
        abort_if($website === null, 403);

        return $website;
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('content.integrations');
    }
}
