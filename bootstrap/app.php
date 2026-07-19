<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Google Cross-Account Protection (CAP/RISC) posts security events
        // server-to-server and cannot provide a browser CSRF token.
        // Stripe webhooks are signed via STRIPE_WEBHOOK_SECRET — Cashier's
        // controller verifies the signature, so CSRF protection is both
        // unnecessary and impossible (Stripe doesn't carry a CSRF cookie).
        $middleware->validateCsrfTokens(except: [
            'auth/google/cap/events',
            'stripe/webhook',
            // Self-hosted keyword API posts results server-to-server; the body
            // is HMAC-verified in KeywordFinderWebhookController.
            'webhooks/keyword-finder',
        ]);

        $middleware->alias([
            // Override the framework's strict `verified` middleware with a
            // grace-window variant: new users may use the app unverified for
            // config('auth.verification.grace_days') days, then must verify.
            'verified' => \App\Http\Middleware\EnsureEmailVerifiedAfterGrace::class,
            'onboarded' => \App\Http\Middleware\EnsureOnboarded::class,
            'feature' => \App\Http\Middleware\EnsureFeatureAccess::class,
            'website.api' => \App\Http\Middleware\WebsiteApiAuth::class,
            'website.features' => \App\Http\Middleware\InjectFeatureFlags::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'content.access' => \App\Http\Middleware\EnsureContentAccess::class,
        ]);

        // Sharding: after the session is up, route the request to the node(s)
        // hosting the active website's tenant/crawl data. No-op (default
        // connection) until a website carries a node anchor.
        $middleware->web(append: [
            // i18n (2026-07-07): resolves + sets the request locale (en/ar).
            // Guards itself off for admin* paths — admin stays English-only,
            // no separate admin layout exists so this is the exclusion point.
            \App\Http\Middleware\SetLocale::class,
            // WP-plugin deep-links: `?ebq_site=<domain>` switches the session
            // website (only among the user's accessible ones). Must precede
            // ResolveShardContext, which reads current_website_id.
            \App\Http\Middleware\ApplyWebsiteHint::class,
            \App\Http\Middleware\ResolveShardContext::class,
            // Expired-trial lockout: confines trial-expired users to the
            // billing surface (no-op for guests/admins/subscribers/comped).
            \App\Http\Middleware\EnsureTrialNotExpired::class,
            // Content-only users: teaser the dashboard reports/crawl surfaces
            // (they keep full Content Autopilot). No-op for everyone else.
            \App\Http\Middleware\EnsureDashboardAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Forward unhandled exceptions to Sentry. The SDK respects the
        // `ignore_exceptions` list in config/sentry.php (404s, validation,
        // auth challenges) and is a no-op when SENTRY_LARAVEL_DSN is empty,
        // so this is safe in local/dev environments without configuration.
        \Sentry\Laravel\Integration::handles($exceptions);

        // Per-user API quota — 402 JSON for API/JSON callers (WP plugin
        // renders this as a banner), flash + redirect to billing for
        // browser flows so platform pages can show the same banner via
        // resources/views/partials/quota-banner.blade.php.
        $exceptions->render(function (\App\Exceptions\QuotaExceededException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->toPayload(), 402);
            }
            return redirect()
                ->back(fallback: route('dashboard', absolute: false) ?: '/')
                ->with('quota_notice', $e->toPayload());
        });

        // Impersonation-stop with a stale CSRF token (banner rendered in an
        // old tab, or reached via back-navigation after a logout rotated the
        // token) used to dead-end on the raw 419 page. Redirect somewhere
        // sane instead: a fresh page re-renders the banner with a valid
        // token, so a second click always works. NOTE: must hook the 419
        // HttpException, not TokenMismatchException — Handler::prepareException()
        // converts it BEFORE render callbacks run.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->routeIs('admin.impersonation.stop')) {
                return redirect()
                    ->route(auth()->check() ? 'dashboard' : 'login')
                    // Dedicated key: rendered by the app layout next to the
                    // impersonation banner ('error' is only shown on billing).
                    ->with('impersonation_notice', 'That page had expired — please click "Return to admin" again.');
            }

            // Logout with a stale CSRF token (session/sidebar left open past
            // SESSION_LIFETIME, or the token rotated in another tab) used to
            // dead-end on the raw 419 page. Same recovery as impersonation-stop
            // above — CSRF verification still runs normally; this only
            // redirects to a fresh page (which carries a valid token) instead
            // of a dead-end error, so the next logout click just works.
            if ($request->routeIs('logout')) {
                return redirect()
                    ->route(auth()->check() ? 'dashboard' : 'login')
                    ->with('session_notice', 'Your session had expired — please try logging out again.');
            }
        });
    })->create();
