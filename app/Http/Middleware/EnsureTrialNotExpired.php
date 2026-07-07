<?php

namespace App\Http\Middleware;

use App\Support\TrialStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expired-trial lockout (2026-07-07): a trial-expired user keeps their login
 * but is confined to the billing surface until they subscribe. Pairs with
 * ebq:trial-cleanup (countdown emails + data deletion); eligibility comes
 * from the same TrialStatus rule so the two can never disagree — admins,
 * active subscribers and comped plans are never locked.
 *
 * Allowlist: billing + checkout routes (the whole point), logout, and admin
 * impersonation-stop (an admin viewing an expired client must be able to
 * leave). Guests pass through untouched (auth middleware owns them).
 *
 * TEAM MEMBERS ARE NEVER LOCKED (TrialStatus::isLockedOut): a user managing
 * other users' websites via website_user works under those owners' plans, so
 * their own trial expiring must not cut that access. Their OWN websites still
 * expire and get deleted by ebq:trial-cleanup.
 */
class EnsureTrialNotExpired
{
    private const ALLOWED_ROUTE_PREFIXES = [
        'billing.',
        'cashier.',
    ];

    private const ALLOWED_ROUTES = [
        'logout',
        'admin.impersonation.stop',
        // Marketing pages with pricing — locked users may browse them (they
        // show the winback discount; CTAs land on billing.checkout anyway).
        'pricing',
        'landing',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        // Impersonating admin browsing an expired client stays free to move.
        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }
        if (! TrialStatus::isLockedOut($user)) {
            return $next($request);
        }

        $route = $request->route()?->getName() ?? '';
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }
        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return $next($request);
            }
        }

        return redirect()->route('billing.show')
            ->with('error', 'Your free trial has ended. Choose a plan to keep using Serfix — your data is held for 3 days after expiry.');
    }
}
