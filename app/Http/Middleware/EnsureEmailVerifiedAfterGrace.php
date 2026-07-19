<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grace-window variant of Laravel's built-in `EnsureEmailIsVerified`.
 *
 * A freshly registered user may use the app UNVERIFIED for
 * `config('auth.verification.grace_days')` days (default 3). Once that window
 * from `created_at` elapses, an unverified user is forced to the verify-email
 * screen until they confirm their address. Verified users always pass; guests
 * are left to the `auth` middleware.
 *
 * Registered as the `verified` alias in bootstrap/app.php, so every route
 * already gated by `verified` gets grace semantics automatically.
 */
class EnsureEmailVerifiedAfterGrace
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $user = $request->user();

        // No user (guest) → let `auth` handle it. Non-MustVerifyEmail models
        // and already-verified users pass straight through.
        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        // Still inside the grace window from registration → allow.
        $graceDays = (int) config('auth.verification.grace_days', 3);
        if ($graceDays > 0 && $user->created_at !== null
            && $user->created_at->gt(now()->subDays($graceDays))) {
            return $next($request);
        }

        // Grace expired and still unverified → force verification.
        return $request->expectsJson()
            ? abort(403, 'Your email address is not verified.')
            : Redirect::route($redirectToRoute ?: 'verification.notice');
    }
}
