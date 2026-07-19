<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-only users (an active content subscription/trial but a LAPSED
 * dashboard trial) keep full Content Autopilot access, but their dashboard
 * reports/crawl features are shown as a blurred teaser with an upgrade CTA —
 * the content pipeline still runs a small internal crawl behind the scenes.
 *
 * One middleware + one view rather than scattering isContentOnly() checks
 * across dozens of report blades. Applied globally on the web stack; it only
 * intercepts the known report/crawl route prefixes, so content / billing /
 * websites / settings / team routes stay fully usable and any new route
 * defaults to accessible.
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

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isContentOnly()) {
            return $next($request);
        }

        $route = $request->route()?->getName() ?? '';
        foreach (self::TEASER_PREFIXES as $prefix) {
            if ($route === rtrim($prefix, '.') || str_starts_with($route, $prefix)) {
                return response()->view('dashboard.content-only-teaser', [
                    'route' => $route,
                ], 200);
            }
        }

        return $next($request);
    }
}
