<?php

namespace App\Http\Controllers;

use App\Jobs\RunGuestPageSpeedStrategy;
use App\Models\GuestPageSpeed;
use App\Models\Lead;
use App\Rules\ValidRecaptcha;
use App\Services\LighthouseClient;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public, no-signup PageSpeed test driven from the marketing site.
 *
 * Requires a signed-in account: anonymous visitors get a blurred sample
 * preview + signup modal; authenticated users run it directly.
 */
class GuestPageSpeedController extends Controller
{
    private const PER_MINUTE = 4;

    private const PER_DAY = 15;

    public function store(Request $request, SafeHttpGuard $guard, LighthouseClient $lighthouse): JsonResponse
    {
        // Public tools require an account: anonymous submit runs nothing (no API)
        // and returns require:signup so the page shows the blurred gate + modal.
        if (auth()->guest()) {
            return response()->json([
                'results_url' => route('tool.preview', array_merge(['tool' => 'pagespeed'], $request->only(['url']))),
            ], 202);
        }

        $ip = (string) $request->ip();

        $minuteKey = 'guest-pagespeed:m:'.$ip;
        $dayKey = 'guest-pagespeed:d:'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, self::PER_MINUTE) || RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json([
                'message' => 'You’ve run a lot of tests in a short time. Please wait a moment and try again.',
            ], 429);
        }

        $rawUrl = trim((string) $request->input('url', ''));
        if ($rawUrl !== '' && ! preg_match('#^https?://#i', $rawUrl)) {
            $rawUrl = 'https://'.$rawUrl;
        }
        $request->merge(['url' => $rawUrl]);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:700'],
        ]);

        if (! $lighthouse->isConfigured()) {
            return response()->json(['message' => 'PageSpeed testing is temporarily unavailable. Please try again shortly.'], 503);
        }

        // SSRF / unsafe-target rejection before we create a row or spend a worker.
        $check = $guard->check($validated['url']);
        if (! ($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'That URL can’t be tested. Enter a public website address (https://…).',
                'errors' => ['url' => ['That URL can’t be tested. Enter a public website address (https://…).']],
            ], 422);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);

        // Every user is authenticated here (guests short-circuit above), so
        // there is no email/signup friction — run and show the result.
        // One job per strategy so each gets a full worker cycle; they run in
        // parallel and coordinate on a row lock to finalize the report.
        $row = GuestPageSpeed::start($validated['url'], $ip, null, null);
        RunGuestPageSpeedStrategy::dispatch($row->id, 'mobile');
        RunGuestPageSpeedStrategy::dispatch($row->id, 'desktop');

        return response()->json([
            'token' => $row->token,
            'status_url' => route('guest-pagespeed.status', $row),
            'results_url' => route('guest-pagespeed.show', $row),
            'emailed' => false,
        ], 202);
    }

    public function status(GuestPageSpeed $guestPageSpeed): JsonResponse
    {
        return response()->json([
            'status' => $guestPageSpeed->status,
            'results_url' => route('guest-pagespeed.show', $guestPageSpeed),
        ]);
    }

    public function show(GuestPageSpeed $guestPageSpeed): View
    {
        return view('guest-pagespeed.show', ['report' => $guestPageSpeed]);
    }
}
