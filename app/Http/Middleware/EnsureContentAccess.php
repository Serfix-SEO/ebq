<?php

namespace App\Http\Middleware;

use App\Models\ContentPlan;
use App\Services\Content\ContentEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Content Autopilot product surfaces (calendar / settings /
 * integrations / review) behind an actual content entitlement for the CURRENT
 * website. Users without access are sent to Get started (to trial or buy).
 *
 * Exception: a website that already has a content plan WITH topics stays
 * reachable even when access lapsed, so the client can still PUBLISH articles
 * already generated during their trial/subscription (publishing is never gated).
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

        // Lapsed but has generated content on this site → allow (publish-only).
        $hasExistingContent = ContentPlan::query()
            ->where('website_id', $website->id)
            ->whereHas('topics')
            ->exists();
        if ($hasExistingContent) {
            return $next($request);
        }

        return redirect()->route('content.get-started');
    }
}
