<?php

namespace App\Http\Controllers;

use App\Services\Microsoft\MicrosoftOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Microsoft OAuth flow for the "send report from Outlook" feature.
 * Mirrors {@see GoogleOAuthController::redirect()} / ::callback().
 *
 * Requires the `socialiteproviders/microsoft` provider to be registered
 * in `App\Providers\EventServiceProvider` via the Socialite provider's
 * standard event listener; see config/services.php for the credentials.
 */
class MicrosoftOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        // `offline_access` is the magic scope that yields a refresh token
        // on Microsoft Graph — without it the access token expires in
        // ~1 hour and reconnect prompts cascade on background sends.
        return Socialite::driver('microsoft')
            ->scopes(['offline_access', 'Mail.Send', 'User.Read'])
            ->redirect();
    }

    public function callback(MicrosoftOAuthService $oauthService): RedirectResponse
    {
        // Same `stateless()` reasoning as the Google flow — proxies in
        // front of EBQ sometimes strip the session cookie during the
        // OAuth round-trip. Microsoft's own state nonce in the redirect
        // covers CSRF.
        $msUser = Socialite::driver('microsoft')->stateless()->user();
        $oauthService->persistAccount(Auth::user(), $msUser);

        return redirect()
            ->route('settings.index')
            ->with('status', 'Connected to Microsoft. You can now send reports from this Outlook account.');
    }
}
