<?php

namespace App\Http\Controllers;

use App\Models\ContentSocialAccount;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\TwitterProvider;

/**
 * OAuth connect flows for the Content Autopilot social auto-share
 * (Facebook Page + X + Pinterest board). Mirrors {@see MicrosoftOAuthController}.
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
 * tokens live ~2h and are refreshed at post time by SocialPoster. Pinterest:
 * plain OAuth2 code flow written by hand (no Socialite driver), ending in a
 * BOARD picker — every Pin belongs to a board — with the freshly minted token
 * parked in the session until the client picks one.
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

    /**
     * Pinterest has no Socialite driver, so the OAuth2 code flow is done by
     * hand: authorize → code → token. State is generated here and verified at
     * callback (Socialite would otherwise own that).
     */
    public function pinterestRedirect(): RedirectResponse
    {
        $this->rememberWebsite();
        $state = bin2hex(random_bytes(16));
        session(['content_social.pinterest_state' => $state]);

        return redirect()->away('https://www.pinterest.com/oauth/?'.http_build_query([
            'client_id' => (string) config('services.pinterest.client_id'),
            'redirect_uri' => (string) config('services.pinterest.redirect'),
            'response_type' => 'code',
            // Comma-separated, unlike the other two networks.
            'scope' => 'boards:read,pins:write,user_accounts:read',
            'state' => $state,
        ]));
    }

    public function pinterestCallback(Request $request): RedirectResponse
    {
        $website = $this->websiteOrAbort();

        $expected = (string) session()->pull('content_social.pinterest_state', '');
        $code = (string) $request->query('code', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', '')) || $code === '') {
            return $this->back()->with('social-error', __('Pinterest connection failed — please try again.'));
        }

        try {
            $token = $this->pinterestToken($code);
            if ($token === null) {
                return $this->back()->with('social-error', __('Pinterest connection failed — please try again.'));
            }
            $username = $this->pinterestUsername($token['access_token']);
            $boards = $this->pinterestBoards($token['access_token']);
        } catch (\Throwable $e) {
            Log::warning('content_social.pinterest_oauth_error', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return $this->back()->with('social-error', __('Pinterest connection failed — please try again.'));
        }

        if ($boards === []) {
            return $this->back()->with('social-error', __('No Pinterest board found on that account. Pins are saved to a board — create one on Pinterest first.'));
        }

        // Stash the token so the board picker can finish the connection without
        // a second round-trip to Pinterest.
        session([
            'content_social.pinterest_token' => $token,
            'content_social.pinterest_username' => $username,
            'content_social.pinterest_website_id' => $website->id,
        ]);

        if (count($boards) === 1) {
            self::storePinterestBoard($website, $boards[0]);

            return $this->back()->with('social-status', __('Pinterest board ":name" connected.', ['name' => $boards[0]['name']]));
        }

        session(['content_social.pinterest_boards' => $boards]);

        return $this->back()->with('social-status', __('Choose which Pinterest board to pin to.'));
    }

    /**
     * Finish a Pinterest connection against the board the client picked. The
     * token comes from the session the callback just wrote — it is never
     * accepted from the request.
     */
    public static function storePinterestBoard(Website $website, array $board): void
    {
        $token = (array) session('content_social.pinterest_token', []);
        if (($token['access_token'] ?? '') === '') {
            return;
        }
        $username = (string) session('content_social.pinterest_username', '');

        ContentSocialAccount::query()->updateOrCreate(
            ['website_id' => $website->id, 'provider' => ContentSocialAccount::PROVIDER_PINTEREST],
            [
                'credentials' => [
                    'access_token' => (string) $token['access_token'],
                    'refresh_token' => (string) ($token['refresh_token'] ?? ''),
                    'expires_at' => (int) ($token['expires_at'] ?? 0),
                    'username' => $username,
                    'board_id' => (string) $board['id'],
                    'board_name' => (string) $board['name'],
                ],
                'status' => ContentSocialAccount::STATUS_CONNECTED,
                'display_name' => (string) $board['name'],
                'last_error' => null,
            ],
        );

        session()->forget([
            'content_social.pinterest_token',
            'content_social.pinterest_username',
            'content_social.pinterest_boards',
            'content_social.pinterest_website_id',
        ]);
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: int}|null */
    private function pinterestToken(string $code): ?array
    {
        $response = Http::timeout(20)->connectTimeout(8)
            ->withBasicAuth((string) config('services.pinterest.client_id'), (string) config('services.pinterest.client_secret'))
            ->asForm()
            ->post($this->pinterestBase().'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => (string) config('services.pinterest.redirect'),
            ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            Log::warning('content_social.pinterest_token_error', [
                'status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            return null;
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => (string) ($response->json('refresh_token') ?? ''),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 2592000))->timestamp,
        ];
    }

    private function pinterestUsername(string $token): string
    {
        $response = Http::timeout(20)->connectTimeout(8)->withToken($token)->get($this->pinterestBase().'/user_account');

        return $response->successful() ? (string) ($response->json('username') ?? '') : '';
    }

    /** @return list<array{id: string, name: string}> */
    private function pinterestBoards(string $token): array
    {
        $response = Http::timeout(20)->connectTimeout(8)
            ->withToken($token)
            ->get($this->pinterestBase().'/boards', ['page_size' => 25]);
        if (! $response->successful()) {
            return [];
        }

        return collect((array) $response->json('items', []))
            ->filter(fn ($b) => is_array($b) && ! empty($b['id']))
            ->map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? 'Board')])
            ->values()
            ->all();
    }

    private function pinterestBase(): string
    {
        return rtrim((string) config('services.pinterest.base_url', 'https://api.pinterest.com/v5'), '/');
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
        // The Auto-share card lives on its own page since 2026-07-31; this used
        // to return to Integrations, which would now drop the client on a page
        // that no longer shows the connection they just made.
        return redirect()->route('content.social');
    }
}
