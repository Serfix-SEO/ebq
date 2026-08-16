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
    // 'issues.' left OFF this list on purpose (2026-08-16): the /issues/{key}
    // drill-downs are the Site Health module's detail pages, and Site Health
    // now ships inside Content Autopilot.
    private const SEO_ONLY_PREFIXES = [
        'competitor-discovery.', 'website-overview', 'page-audits.',
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
        // Applies to impersonating admins too: impersonation exists to see what
        // the CLIENT sees, and exempting it showed the owner an SEO teaser
        // selling a hidden product (2026-08-08). Admins debug SEO surfaces from
        // their own account, which stays exempt via is_admin.
        if (! config('features.seo_platform_ui')
            && ! $user->is_admin
            && ($this->matches($route, self::TEASER_PREFIXES) || $this->matches($route, self::SEO_ONLY_PREFIXES))) {
            // Site Health moved into Content Autopilot (2026-08-16). The
            // crawl-report emails clients already received link /dashboard, and
            // the old health tab lived at /overview?tab=health — both 301 to
            // the new page (permanent: the module moved, not the kill-switch).
            if ($route === 'dashboard' || ($route === 'website-overview' && $request->query('tab') === 'health')) {
                return redirect()->route('content.site-health', status: 301);
            }

            // EnsureContentAccess cascades non-entitled users on to
            // content.get-started — but a user with NO websites must go there
            // DIRECTLY. content.index bounces them through EnsureFeatureAccess
            // → websites.index → EnsureOnboarded → /onboarding → back here: a
            // redirect loop (prod 2026-08-08, a website-less lead). The SEO
            // onboarding wizard used to be their terminal state; get-started
            // is the content product's, and it renders fine with no website.
            return $user->hasAccessibleWebsites()
                ? redirect()->route('content.index')
                : redirect()->route('content.get-started');
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
