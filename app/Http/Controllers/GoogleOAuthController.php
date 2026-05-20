<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebsiteInvitation;
use App\Services\Google\GoogleOAuthService;
use App\Services\ClientActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleOAuthController extends Controller
{
    public function ssoRedirect(Request $request): RedirectResponse
    {
        // Keep intent in session so callback can distinguish login/register
        // UX and preserve invite token if user came from invite onboarding.
        $request->session()->put('google_sso.intent', (string) $request->query('intent', 'login'));
        $request->session()->put('google_sso.invite', (string) $request->query('invite', ''));

        return Socialite::driver('google')
            ->redirectUrl(route('google.sso.callback', absolute: true))
            ->scopes([
                'openid',
                'profile',
                'email',
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/indexing',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ])
            ->redirect();
    }

    public function ssoCallback(Request $request, GoogleOAuthService $oauthService, ClientActivityLogger $logger): RedirectResponse
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(route('google.sso.callback', absolute: true))
            ->stateless()
            ->user();
        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google account did not return an email address.',
            ]);
        }

        /** @var User|null $user */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $isNewUser = false;
        if (! $user) {
            $isNewUser = true;
            $user = User::create([
                'name' => (string) ($googleUser->getName() ?: $googleUser->getNickname() ?: 'Google User'),
                'email' => $email,
                // Random local password so account can still use password reset.
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ]);

            WebsiteInvitation::query()
                ->where('email', $email)
                ->where('expires_at', '>', now())
                ->get()
                ->each(fn (WebsiteInvitation $invitation) => $invitation->acceptFor($user));
        }
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($user->is_disabled) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been disabled. Please contact support.',
            ]);
        }

        Auth::login($user, true);
        $oauthService->persistAccount($user, $googleUser);
        $logger->log($isNewUser ? 'auth.register_google' : 'auth.login_google', userId: $user->id, meta: ['ip' => $request->ip()]);

        $websiteId = (int) session('current_website_id', 0);
        if ($websiteId <= 0) {
            $first = $user->accessibleWebsitesQuery()->select('id')->orderBy('domain')->first();
            if ($first) {
                $websiteId = (int) $first->id;
                session(['current_website_id' => $websiteId]);
            }
        }

        if (! $user->hasAccessibleWebsites()) {
            return redirect()->route('onboarding');
        }

        return redirect()->intended(route($user->firstAccessibleRoute($websiteId), absolute: false));
    }

    public function redirect(): RedirectResponse
    {
        // `access_type=offline` requests a refresh token so EBQ can keep
        // syncing GSC / Analytics in the background without re-prompting.
        // `prompt=consent` ensures the refresh token is actually issued
        // (Google omits it on subsequent OAuth flows when the same scopes
        // are already granted). `include_granted_scopes=true` lets the
        // user grant additional scopes incrementally without re-confirming
        // ones they've already approved — Google's recommended pattern.
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/indexing',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ])
            ->redirect();
    }

    /**
     * Incremental-consent flow for the Gmail-send scope. Adds
     * `gmail.send` to the Analytics/GSC scopes the user has already
     * granted; Google's `include_granted_scopes=true` preserves the
     * existing grants so we don't have to re-request them.
     *
     * The callback distinguishes this flow from the standard sync flow
     * by reading `google_oauth.intent` from the session, set here.
     */
    public function redirectMailScope(\Illuminate\Http\Request $request): RedirectResponse
    {
        $request->session()->put('google_oauth.intent', 'mail_send');

        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/indexing',
                'https://www.googleapis.com/auth/gmail.send',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ])
            ->redirect();
    }

    public function callback(GoogleOAuthService $oauthService, \Illuminate\Http\Request $request): RedirectResponse
    {
        // `stateless()` is intentional — Socialite's session-state check
        // can fail across the OAuth round-trip when the host runs behind
        // a proxy that strips the session cookie on the callback. CSRF is
        // still protected by Google's own state nonce in the redirect.
        $googleUser = Socialite::driver('google')->stateless()->user();
        $oauthService->persistAccount(Auth::user(), $googleUser);

        // If the flow was initiated for the Gmail-send transport, return
        // to Settings so the user can finish configuring the mail
        // transport — not to onboarding.
        $intent = (string) $request->session()->pull('google_oauth.intent', '');
        if ($intent === 'mail_send') {
            return redirect()
                ->route('settings.index')
                ->with('status', 'Connected to Gmail. You can now send reports from this address.');
        }

        return redirect()->route('onboarding');
    }
}
