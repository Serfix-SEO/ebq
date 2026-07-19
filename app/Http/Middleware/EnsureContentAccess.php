<?php

namespace App\Http\Middleware;

use App\Models\ContentTopic;
use App\Services\Content\ContentEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Content Autopilot product surfaces (calendar / settings /
 * integrations / review) behind an actual content entitlement for the CURRENT
 * website — PER SITE. A user with other covered (trial/paid) sites is never
 * blocked on those; only the specific uncovered site is gated. Uncovered sites
 * are sent to Get started to pay (single site, or the per-extra-site addon).
 *
 * Exception: a site that already has GENERATED ARTICLES stays reachable even
 * when access lapsed, so the client can still PUBLISH what a past
 * trial/subscription produced (publishing is never gated). A brand-new, never-
 * paid site only has ideation TOPICS (no articles) → it is still blocked.
 */
class EnsureContentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $websiteId = (string) $request->session()->get('current_website_id', '');
        $website = $websiteId !== ''
            ? $user->accessibleWebsitesQuery()->whereKey($websiteId)->first()
            : $user->accessibleWebsitesQuery()->first();

        if ($website === null) {
            return $next($request); // onboarding/no-site handled elsewhere
        }

        $ent = app(ContentEntitlements::class);
        if ($ent->hasContentAccessFor($user, $website)) {
            return $next($request);
        }

        // Lapsed but has GENERATED ARTICLES on this site → allow (publish-only).
        // A never-paid site only has ideation topics (no articles) → stays gated.
        $hasPublishableContent = ContentTopic::query()
            ->where('website_id', $website->id)
            ->whereHas('articles')
            ->exists();
        if ($hasPublishableContent) {
            return $next($request);
        }

        return redirect()->route('content.get-started');
    }
}
