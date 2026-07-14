<?php

namespace App\Http\Controllers;

use App\Jobs\RunGuestPageAudit;
use App\Models\GuestPageAudit;
use App\Models\Lead;
use App\Rules\ValidRecaptcha;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Audit\SerpGlCatalog;
use App\Support\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public, no-signup SEO audit driven from the marketing landing page.
 *
 * A signed-in user submits a URL + keyword; we queue a {@see RunGuestPageAudit} job
 * (lite, no GSC/GA, no paid Serper/Lighthouse) and hand back an unguessable
 * token. The browser polls {@see status()} and lands on {@see show()} — which
 * renders the report and upsells the full GSC/GA-powered audit.
 */
class GuestAuditController extends Controller
{
    /** Per-IP throttle: short burst window + daily ceiling. */
    private const PER_MINUTE = 5;

    private const PER_DAY = 20;

    public function store(Request $request, SafeHttpGuard $guard): JsonResponse
    {
        // Public tools require an account: anonymous submit runs nothing (no API)
        // and returns require:signup so the page shows the blurred gate + modal.
        if (auth()->guest()) {
            return response()->json([
                'results_url' => route('tool.preview', array_merge(['tool' => 'audit'], $request->only(['url', 'keyword', 'country']))),
            ], 202);
        }

        $ip = (string) $request->ip();

        $minuteKey = 'guest-audit:m:'.$ip;
        $dayKey = 'guest-audit:d:'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, self::PER_MINUTE) || RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json([
                'message' => 'You’ve run a lot of audits in a short time. Please wait a moment and try again.',
            ], 429);
        }

        // Normalize before validation: accept "example.com" → "https://example.com".
        $rawUrl = trim((string) $request->input('url', ''));
        if ($rawUrl !== '' && ! preg_match('#^https?://#i', $rawUrl)) {
            $rawUrl = 'https://'.$rawUrl;
        }
        $request->merge(['url' => $rawUrl]);

        // Only URL + keyword are validated up front. The reCAPTCHA is validated
        // *later* — exactly once, on the request that actually runs an audit — so
        // the "we need your email" round-trip doesn't consume (and re-prompt) it.
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:700'],
            'keyword' => ['required', 'string', 'max:200'],
        ]);

        // Optional SERP country (gl). Empty = auto-detect from the page locale.
        // A non-empty value comes from our own <select>, so an invalid one means
        // tampering — reject it rather than silently ignoring.
        $gl = strtolower(trim((string) $request->input('country', '')));
        if ($gl === '') {
            $gl = null;
        } elseif (! array_key_exists($gl, SerpGlCatalog::selectOptions())) {
            return response()->json([
                'message' => 'Please choose a valid country.',
                'errors' => ['country' => ['Please choose a valid country.']],
            ], 422);
        }

        // SSRF / unsafe-target rejection before we create a row or spend a worker.
        $check = $guard->check($validated['url']);
        if (! ($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'That URL can’t be audited. Enter a public website address (https://…).',
                'errors' => ['url' => ['That URL can’t be audited. Enter a public website address (https://…).']],
            ], 422);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);

        // Every user is authenticated here (guests short-circuit above), so
        // there is no email/signup friction — run and show the result.
        $audit = GuestPageAudit::start($validated['url'], $validated['keyword'], $ip, $gl, null, null);
        RunGuestPageAudit::dispatch($audit->id);

        return response()->json([
            'token' => $audit->token,
            'status_url' => route('guest-audit.status', $audit),
            'results_url' => route('guest-audit.show', $audit),
            'emailed' => false,
        ], 202);
    }

    /** Lightweight poll target for the results page / hero JS. */
    public function status(GuestPageAudit $guestPageAudit): JsonResponse
    {
        return response()->json([
            'status' => $guestPageAudit->status,
            'results_url' => route('guest-audit.show', $guestPageAudit),
        ]);
    }

    public function show(GuestPageAudit $guestPageAudit): View
    {
        return view('guest-audit.show', ['audit' => $guestPageAudit]);
    }
}
