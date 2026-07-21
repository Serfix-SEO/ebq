<?php

namespace App\Http\Middleware;

use App\Models\ContentPlan;
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

        $ent = app(ContentEntitlements::class);

        $websiteId = (string) $request->session()->get('current_website_id', '');
        $website = $websiteId !== ''
            ? $user->accessibleWebsitesQuery()->whereKey($websiteId)->first()
            : $ent->preferredWebsite($user);

        if ($website === null) {
            return $next($request); // onboarding/no-site handled elsewhere
        }

        if ($ent->hasContentAccessFor($user, $website)) {
            return $next($request);
        }

        // The pinned site is NOT the one this user runs content on. That pin is
        // rarely a deliberate choice: `feature:content` (EnsureFeatureAccess)
        // runs first and, with nothing in the session, pins
        // `orderBy('domain')->first()` — purely alphabetical. An account whose
        // alphabetically-first domain happens to be uncovered was therefore
        // bounced to Get started, which redirects back here, which re-fires the
        // wizard's wire:init, which bounces again: a redirect loop that reads as
        // "the wizard keeps refreshing on step 1" (prod 2026-07-21, an account
        // holding pubgnamegenerator.net + serfix.io where only the latter was
        // covered).
        //
        // Re-pin to a site the user IS entitled to and carry on, rather than
        // redirecting — silently correcting an alphabetical accident beats
        // gating someone out of a product they pay for.
        //
        // Only when the pinned site has NO content plan at all, i.e. it was
        // never a content site and cannot be what the user meant. A site whose
        // plan merely lapsed is a deliberate choice (they may want to resubscribe
        // for it), so that still shows Get started for THAT site.
        $pinnedIsNotAContentSite = ! ContentPlan::query()
            ->where('website_id', $website->id)
            ->exists();

        if ($pinnedIsNotAContentSite) {
            $preferred = $ent->preferredWebsite($user);
            if ($preferred !== null
                && $preferred->id !== $website->id
                && $ent->hasContentAccessFor($user, $preferred)) {
                $request->session()->put('current_website_id', (string) $preferred->id);

                return $next($request);
            }
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
