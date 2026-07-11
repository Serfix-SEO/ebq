<?php

namespace App\Http\Middleware;

use App\Models\CrawlSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an external deep-link pin which website the session should have
 * selected: `?ebq_site=<domain>`. The WordPress plugin appends this to its
 * raw portal links (rank-tracking, custom-audit, settings, …) — without it
 * those pages render whatever website the session last had selected, which
 * is the wrong site whenever the account has several.
 *
 * Only switches among websites the authenticated user can already access
 * (owner or team member — the same rule as the website switcher), so the
 * param needs no signature. Silent no-op for guests and unknown domains;
 * the login flow preserves the full intended URL, so the hint still applies
 * on the first authenticated request after sign-in.
 *
 * Must run before ResolveShardContext (bootstrap/app.php) — the shard router
 * reads `current_website_id` from the session on the same request.
 */
class ApplyWebsiteHint
{
    public function handle(Request $request, Closure $next): Response
    {
        $hint = trim((string) $request->query('ebq_site', ''));

        if ($hint !== '' && $request->hasSession() && ($user = $request->user()) !== null) {
            $normalized = CrawlSite::normalizeDomain($hint);

            if ($normalized !== '') {
                $website = $user->accessibleWebsitesQuery()
                    ->where('normalized_domain', $normalized)
                    ->first(['websites.id']);

                if ($website !== null) {
                    session(['current_website_id' => (string) $website->id]);
                }
            }
        }

        return $next($request);
    }
}
