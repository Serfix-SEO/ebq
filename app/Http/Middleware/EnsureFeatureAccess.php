<?php

namespace App\Http\Middleware;

use App\Support\TeamPermissions;
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

        if (! array_key_exists($feature, TeamPermissions::FEATURES)) {
            return $next($request);
        }

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
