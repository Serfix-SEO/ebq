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
            'onboarded' => \App\Http\Middleware\EnsureOnboarded::class,
            'feature' => \App\Http\Middleware\EnsureFeatureAccess::class,
            'website.api' => \App\Http\Middleware\WebsiteApiAuth::class,
            'website.features' => \App\Http\Middleware\InjectFeatureFlags::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'research.rollout' => \App\Http\Middleware\EnsureResearchRolloutAccess::class,
        ]);

        // Sharding: after the session is up, route the request to the node(s)
        // hosting the active website's tenant/crawl data. No-op (default
        // connection) until a website carries a node anchor.
        $middleware->web(append: [
            \App\Http\Middleware\ResolveShardContext::class,
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
    })->create();
