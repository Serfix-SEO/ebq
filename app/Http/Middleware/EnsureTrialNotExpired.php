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
        // The content product is bought separately — a lapsed dashboard user
        // must be able to reach Get started + content checkout to buy it. The
        // content routes carry their own EnsureContentAccess gate.
        'content.',
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

        // Livewire posts EVERY action to `livewire.update`, whatever page it
        // came from, so judging that route name contradicts the allowlist
        // above: a locked-out user could open /content but every button on it
        // bounced to the dashboard billing page — clicking "Write now" on the
        // content calendar landed on SEO plans instead of the content plans
        // (prod 2026-08-06, daomarketing.com). Judge the ORIGIN page instead.
        // (Matched on `str_contains`, not a prefix: the update route is named
        // `default.livewire.update` here, not `livewire.update`.)
        if (str_contains($route, 'livewire.') || $request->hasHeader('X-Livewire')) {
            $route = $this->originRouteName($request) ?? $route;
        }

        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }
        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return $next($request);
            }
        }

        // SEO UI off: /billing sells Content Autopilot only, and the product
        // this user's lockout is about is hidden — send them to the content
        // Get started page instead (`content.` is allowlisted above, no loop).
        if (! config('features.seo_platform_ui')) {
            return redirect()->route('content.get-started')
                ->with('error', __('Your free trial has ended. Choose a plan to keep using Serfix — your data is held for 3 days after expiry.'));
        }

        return redirect()->route('billing.show')
            ->with('error', 'Your free trial has ended. Choose a plan to keep using Serfix — your data is held for 3 days after expiry.');
    }

    /**
     * The route name of the page a Livewire action was fired from, read off the
     * Referer.
     *
     * Not a security boundary — a client controls its own Referer — and it does
     * not need to be: this only decides whether an already-authenticated user
     * sees their own content pages or a billing redirect, and every route
     * downstream still enforces its own gate (feature:, content.access,
     * ContentEntitlements::blockReason). Same-host only, and a referer that
     * resolves to nothing falls back to the caller's `livewire.update`, i.e.
     * locked.
     */
    private function originRouteName(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '' || parse_url($referer, PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }

        try {
            return app('router')->getRoutes()
                ->match(Request::create($referer, 'GET'))
                ->getName();
        } catch (\Throwable) {
            return null; // unroutable referer — treat as unknown, stay locked
        }
    }
}
