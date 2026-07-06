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
 * Same progressive friction as {@see GuestAuditController}, counted per
 * browser via a signed cookie:
 *   test #1  → free, shown on screen
 *   test #2  → require name + email, delivered by email (not shown)
 *   test #3+ → block, push free-plan signup
 */
class GuestPageSpeedController extends Controller
{
    private const PER_MINUTE = 4;

    private const PER_DAY = 15;

    private const COUNT_COOKIE = 'ebq_guest_pagespeed';

    private const COUNT_COOKIE_MINUTES = 525600; // ~1 year

    public function store(Request $request, SafeHttpGuard $guard, LighthouseClient $lighthouse): JsonResponse
    {
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

        $prior = max(0, (int) $request->cookie(self::COUNT_COOKIE, 0));
        $attempt = $prior + 1;

        // 3rd+ → block, push signup.
        if ($attempt >= 3) {
            return response()->json([
                'require' => 'signup',
                'message' => 'You’ve used your free PageSpeed tests. Create a free account to keep testing — no credit card — and unlock continuous monitoring, full audits and live Search Console data.',
                'register_url' => route('register'),
            ]);
        }

        // 2nd → require name + email; delivered by email.
        $email = null;
        $name = null;
        if ($attempt >= 2) {
            $email = trim((string) $request->input('email', ''));
            if ($email === '') {
                return response()->json([
                    'require' => 'email',
                    'message' => 'This is your last free test — tell us where to send it.',
                ]);
            }
            $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
            ]);
            $name = trim((string) $request->input('name', ''));
        }

        // Validate the captcha exactly once, only on an otherwise-valid submit.
        if (Recaptcha::isEnabled()) {
            $request->validate(['g-recaptcha-response' => ['required', 'string', new ValidRecaptcha]]);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);

        $row = GuestPageSpeed::start($validated['url'], $ip, $email, $name);
        // One job per strategy so each gets a full worker cycle (heavy sites
        // need 40s+ per strategy); they run in parallel and coordinate on a
        // row lock to finalize the report.
        RunGuestPageSpeedStrategy::dispatch($row->id, 'mobile');
        RunGuestPageSpeedStrategy::dispatch($row->id, 'desktop');

        if ($email !== null) {
            Lead::capture($email, $name, null, Lead::SOURCE_GUEST_PAGESPEED);

            return response()->json([
                'token' => $row->token,
                'emailed' => true,
                'email' => $email,
                'message' => "We’ve emailed your PageSpeed report to {$email}. It lands in a minute — check your inbox (and spam, just in case).",
            ], 202)->cookie(self::COUNT_COOKIE, (string) $attempt, self::COUNT_COOKIE_MINUTES);
        }

        return response()->json([
            'token' => $row->token,
            'status_url' => route('guest-pagespeed.status', $row),
            'results_url' => route('guest-pagespeed.show', $row),
            'emailed' => false,
        ], 202)->cookie(self::COUNT_COOKIE, (string) $attempt, self::COUNT_COOKIE_MINUTES);
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
