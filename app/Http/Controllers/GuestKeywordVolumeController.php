<?php

namespace App\Http\Controllers;

use App\Jobs\RunGuestKeywordVolume;
use App\Models\GuestKeywordVolume;
use App\Models\Lead;
use App\Rules\ValidRecaptcha;
use App\Support\KeywordsEverywhereCountries;
use App\Support\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public, no-signup keyword search-volume finder driven from the marketing
 * site.
 *
 * Requires a signed-in account: anonymous visitors get a blurred sample
 * preview + signup modal; authenticated users run it directly.
 *
 * One keyword per check (the freemium contract); the portal multi-keyword
 * finder is the upsell.
 */
class GuestKeywordVolumeController extends Controller
{
    private const PER_MINUTE = 5;

    private const PER_DAY = 20;

    public function store(Request $request): JsonResponse
    {
        // Public tools require an account: anonymous submit runs nothing (no API)
        // and returns require:signup so the page shows the blurred gate + modal.
        if (auth()->guest()) {
            return response()->json([
                'results_url' => route('tool.preview', array_merge(['tool' => 'volume'], $request->only(['keyword', 'country']))),
            ], 202);
        }

        $ip = (string) $request->ip();

        $minuteKey = 'guest-volume:m:'.$ip;
        $dayKey = 'guest-volume:d:'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, self::PER_MINUTE) || RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json([
                'message' => 'You’ve run a lot of checks in a short time. Please wait a moment and try again.',
            ], 429);
        }

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
        ]);
        $keyword = trim($validated['keyword']);
        if ($keyword === '') {
            return response()->json([
                'message' => 'Enter a keyword to check.',
                'errors' => ['keyword' => ['Enter a keyword to check.']],
            ], 422);
        }

        // KE only supports a short country list — reject anything else (it
        // comes from our own <select>, so an invalid value means tampering).
        $country = strtolower(trim((string) $request->input('country', 'global'))) ?: 'global';
        if (! KeywordsEverywhereCountries::isValid($country)) {
            return response()->json([
                'message' => 'Please choose a valid country.',
                'errors' => ['country' => ['Please choose a valid country.']],
            ], 422);
        }

        if (! is_string(config('services.keywords_everywhere.key')) || trim((string) config('services.keywords_everywhere.key')) === '') {
            return response()->json(['message' => 'Keyword volume lookups are temporarily unavailable. Please try again shortly.'], 503);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);

        // Every user is authenticated here (guests short-circuit above), so
        // there is no email/signup friction — run and show the result.
        $row = GuestKeywordVolume::start($keyword, $country, $ip, null, null);
        RunGuestKeywordVolume::dispatch($row->id);

        return response()->json([
            'token' => $row->token,
            'status_url' => route('guest-volume.status', $row),
            'results_url' => route('guest-volume.show', $row),
            'emailed' => false,
        ], 202);
    }

    public function status(GuestKeywordVolume $guestKeywordVolume): JsonResponse
    {
        return response()->json([
            'status' => $guestKeywordVolume->status,
            'results_url' => route('guest-volume.show', $guestKeywordVolume),
        ]);
    }

    public function show(GuestKeywordVolume $guestKeywordVolume): View
    {
        return view('guest-keyword-volume.show', ['report' => $guestKeywordVolume]);
    }
}
