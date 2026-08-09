<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboarded
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasAccessibleWebsites() && ! $request->routeIs('onboarding*', 'google.*', 'content.*', 'settings*', 'billing.*', 'cashier.*', 'verification.*', 'logout')) {
            // Content-only mode: the SEO GSC/GA wizard is hidden, and sending
            // a no-website user there bounced them in a loop (kill-switch →
            // get-started → /websites → here → onboarding → …). Get started
            // carries the domain form that enters the content wizard.
            return redirect()->route(
                config('features.seo_platform_ui') ? 'onboarding' : 'content.get-started',
            );
        }

        return $next($request);
    }
}
