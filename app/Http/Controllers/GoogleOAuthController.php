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

        // No `include_granted_scopes` here — login is conceptually a
        // fresh flow, and Google's behavior with that flag is to surface
        // every previously-granted scope (including unrelated ones like
        // gmail.send from the report-mail transport) on the consent
        // screen. We explicitly list the scopes login should ask for.
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

        $websiteId = session('current_website_id');
        if (($websiteId === null || $websiteId === '')) {
            $first = $user->accessibleWebsitesQuery()->select('id')->orderBy('domain')->first();
            if ($first) {
                $websiteId = (string) $first->id;
                session(['current_website_id' => $websiteId]);
            }
        }

        if (! $user->hasAccessibleWebsites()) {
            return redirect()->route('onboarding');
        }

        return redirect()->intended(route($user->firstAccessibleRoute($websiteId), absolute: false));
    }

    public function redirect(Request $request): RedirectResponse
    {
        // Remember where to send the user back after the round-trip. Used
        // by the "connect another Google account" buttons so adding a
        // second source-account from Settings returns to Settings rather
        // than dropping the user back into onboarding. Whitelisted to
        // known route names so the param can't be used as an open
        // redirect. Defaults to onboarding (the historical behavior).
        $return = (string) $request->query('return', '');
        $allowed = ['onboarding', 'settings.integrations'];
        $request->session()->put('google_oauth.return', in_array($return, $allowed, true) ? $return : 'onboarding');

        // `access_type=offline` requests a refresh token so EBQ can keep
        // syncing GSC / Analytics in the background without re-prompting.
        // `prompt=consent` ensures the refresh token is actually issued
        // (Google omits it on subsequent OAuth flows when the same scopes
        // are already granted).
        //
        // `openid email profile` are included so we capture which Google
        // login this account belongs to (stored on google_accounts.email)
        // and can label it in the source pickers when a user connects
        // several accounts.
        //
        // `include_granted_scopes` is intentionally OFF here: this flow is
        // scoped to the Analytics/GSC connect — surfacing previously
        // granted scopes (e.g. gmail.send from the report-mail panel)
        // would clutter the consent screen with unrelated permissions and
        // confuse users who just want to (re)connect their data sources.
        return Socialite::driver('google')
            ->scopes([
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/indexing',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
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

        // Return the user to wherever they kicked off the connect from
        // (onboarding by default, or the Settings → Integrations sources
        // manager when adding an extra source-account).
        $return = (string) $request->session()->pull('google_oauth.return', 'onboarding');
        if ($return === 'settings.integrations') {
            return redirect()
                ->route('settings.index')
                ->with('status', 'Google account connected. Pick the property or site you want to track.');
        }

        return redirect()->route('onboarding');
    }
}
