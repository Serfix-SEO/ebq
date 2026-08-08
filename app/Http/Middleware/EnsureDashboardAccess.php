<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two jobs, one middleware, because both are "who may see the SEO dashboard
 * surfaces" decisions and scattering them across dozens of report blades is
 * how they drift apart:
 *
 * 1. SEO-UI kill-switch (2026-08-08): when `features.seo_platform_ui` is off,
 *    the whole SEO product is hidden and every non-admin request to an SEO
 *    surface redirects to Content Autopilot. Admins (and impersonating
 *    admins) pass — they debug client data. Backend is untouched: this
 *    matches SEO *page* route names only, and Livewire's shared update route
 *    (`*livewire.update`) never matches any prefix here, so component actions
 *    from open tabs pass straight through — no Referer machinery needed
 *    (contrast EnsureTrialNotExpired, whose allowlist DID need it).
 *
 * 2. Content-only teaser (2026-07): users with an active content subscription
 *    but a lapsed dashboard trial keep full Content Autopilot access, and the
 *    dashboard reports/crawl features render as a blurred teaser with an
 *    upgrade CTA.
 *
 * Applied globally on the web stack; only the known route prefixes are ever
 * intercepted, so content / billing / websites / settings / team routes stay
 * fully usable and any new route defaults to accessible.
 */
class EnsureDashboardAccess
{
    /** Route-name prefixes that are dashboard reports/crawl surfaces. */
    private const TEASER_PREFIXES = [
        'dashboard', 'statistics', 'site-explorer', 'backlinks', 'competitors',
        'pagespeed.', 'pages.', 'custom-audit.', 'link-structure.', 'keywords.',
        'keyword-', 'rank-tracking.', 'rank-tracker', 'sitemaps.', 'reports.',
        'audit', 'redirects.',
    ];

    /**
     * Additional SEO surfaces the kill-switch covers beyond the teaser list.
     * `onboarding` is the SEO GSC/GA wizard — redirecting it cannot loop
     * because EnsureOnboarded exempts `content.*`. `report.` is deliberately
     * ABSENT from both lists: client report links are deliverables people
     * open from emails, and they keep working in every state.
     */
    private const SEO_ONLY_PREFIXES = [
        'competitor-discovery.', 'website-overview', 'issues.', 'page-audits.',
        'site-audit.', 'overview', 'onboarding',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request); // guests: auth middleware owns them
        }

        $route = $request->route()?->getName() ?? '';

        // 1. Kill-switch: SEO UI off → every SEO surface goes to content.
        if (! config('features.seo_platform_ui')
            && ! $user->is_admin
            && ! $request->session()->has('impersonator_id')
            && ($this->matches($route, self::TEASER_PREFIXES) || $this->matches($route, self::SEO_ONLY_PREFIXES))) {
            // EnsureContentAccess cascades non-entitled users on to
            // content.get-started, so this single target fits everyone.
            return redirect()->route('content.index');
        }

        // 2. Content-only teaser.
        if ($user->isContentOnly() && $this->matches($route, self::TEASER_PREFIXES)) {
            return response()->view('dashboard.content-only-teaser', [
                'route' => $route,
            ], 200);
        }

        return $next($request);
    }

    /** @param  list<string>  $prefixes */
    private function matches(string $route, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($route === rtrim($prefix, '.') || str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
