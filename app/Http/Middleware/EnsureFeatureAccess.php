<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        // No unknown-feature bypass here on purpose: a typo'd `feature:` route arg
        // must fail closed, not silently pass every request through (found
        // 2026-07-06 — the old `! array_key_exists(...) -> next()` branch did the
        // opposite). `User::hasFeatureAccess()` -> `TeamPermissions::allows()`
        // already handles an unrecognised key safely: owners/admins and
        // full-access members (permissions === null) are unaffected either way,
        // and a restricted member's explicit permission list simply won't contain
        // a bogus key, so they're correctly denied.
        $websiteId = session('current_website_id');
        $accessible = ($websiteId !== null && $websiteId !== '')
            ? $user->accessibleWebsitesQuery()->whereKey($websiteId)->exists()
            : false;
        if (! $accessible) {
            $first = $user->accessibleWebsitesQuery()->select('id')->orderBy('domain')->first();
            $websiteId = $first ? (string) $first->id : 0;
            if (($websiteId !== null && $websiteId !== '')) {
                session(['current_website_id' => $websiteId]);
            }
        }

        if (($websiteId !== null && $websiteId !== '') && $user->hasFeatureAccess($feature, $websiteId)) {
            return $next($request);
        }

        $target = $user->firstAccessibleRoute($websiteId);

        if ($request->routeIs($target)) {
            abort(403);
        }

        return redirect()->route($target);
    }
}
