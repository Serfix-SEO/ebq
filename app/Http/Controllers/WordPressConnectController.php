<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * OAuth-style one-click connect for the EBQ WordPress plugin.
 *
 * Flow:
 *   1. WP plugin sends the user to GET /wordpress/connect?site_url=...&redirect=...&state=...
 *   2. EBQ gates on auth; the user picks which Website to link.
 *   3. POST /wordpress/connect mints a scoped Sanctum token on the chosen
 *      Website and 302s back to `redirect` with ?ebq_token=&website_id=&state=.
 *
 * Security:
 *   • The `redirect` URL must live on the same host as `site_url` — prevents a
 *     malicious `site_url=good.com&redirect=evil.com` combo from exfiltrating
 *     tokens.
 *   • `state` is the WP plugin's CSRF nonce; we echo it back unchanged.
 *   • The token is minted against whichever Website the logged-in EBQ user
 *     explicitly picks — not derived from `site_url` — so a spoofed `site_url`
 *     param cannot grant access to someone else's Website.
 */
class WordPressConnectController extends Controller
{
    public function start(Request $request)
    {
        $validated = $request->validate([
            'site_url' => ['required', 'url', 'max:255'],
            'redirect' => ['required', 'url', 'max:500'],
            'state' => ['required', 'string', 'max:128'],
        ]);

        $siteHost = parse_url($validated['site_url'], PHP_URL_HOST);
        $redirectHost = parse_url($validated['redirect'], PHP_URL_HOST);
        if (! $siteHost || ! $redirectHost || strcasecmp($siteHost, $redirectHost) !== 0) {
            return response()->view('wordpress.connect-error', [
                'message' => 'Redirect URL must be on the same domain as the WordPress site.',
            ], 400);
        }

        $user = Auth::user();
        $websites = $user->accessibleWebsitesQuery()->select('id', 'domain')->orderBy('domain')->get();

        return view('wordpress.connect', [
            'siteUrl' => $validated['site_url'],
            'siteHost' => $siteHost,
            'redirect' => $validated['redirect'],
            'state' => $validated['state'],
            'websites' => $websites,
            'suggestedWebsiteId' => $this->suggestWebsiteForHost($user, $siteHost),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'site_url' => ['required', 'url'],
            'redirect' => ['required', 'url'],
            'state' => ['required', 'string'],
        ]);

        $user = Auth::user();
        abort_unless($user && $user->canViewWebsiteId((string) $validated['website_id']), 403);

        $siteHost = parse_url($validated['site_url'], PHP_URL_HOST);
        $redirectHost = parse_url($validated['redirect'], PHP_URL_HOST);
        abort_unless($siteHost && $redirectHost && strcasecmp($siteHost, $redirectHost) === 0, 400);

        $website = Website::findOrFail((string) $validated['website_id']);
        $tokenName = 'WordPress — '.$siteHost;
        $plainToken = $website->createToken($tokenName, ['read:insights'])->plainTextToken;

        $separator = str_contains($validated['redirect'], '?') ? '&' : '?';
        $callback = $validated['redirect'].$separator.http_build_query([
            'ebq_token' => $plainToken,
            'website_id' => $website->id,
            'state' => $validated['state'],
            'ebq_domain' => $website->domain,
            // `ebq_tier` lets the plugin decide which UI to show on first
            // load (Pro features hidden behind a CTA on free tier). Live
            // tier changes are reconciled by the heartbeat / score endpoints
            // returning the current tier in their response — this carry on
            // connect just primes the local option so the editor doesn't
            // flash a wrong button before the first request resolves.
            'ebq_tier' => $website->effectiveTier(),
        ]);

        return redirect()->away($callback);
    }

    private function suggestWebsiteForHost($user, string $host): ?string
    {
        $normalizedHost = strtolower(ltrim(str_replace('www.', '', $host), '.'));
        $match = $user->accessibleWebsitesQuery()
            ->whereRaw('LOWER(domain) = ?', [$normalizedHost])
            ->orWhereRaw('LOWER(domain) = ?', ['www.'.$normalizedHost])
            ->first();

        return $match?->id;
    }
}
