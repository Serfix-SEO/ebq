<?php

namespace App\Http\Controllers;

use App\Jobs\RunGuestRankCheck;
use App\Models\GuestRankCheck;
use App\Models\Lead;
use App\Rules\ValidRecaptcha;
use App\Support\Audit\SerpGlCatalog;
use App\Support\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Public, no-signup keyword rank tracker driven from the marketing site.
 *
 * Requires a signed-in account: anonymous visitors get a blurred sample
 * preview + signup modal; authenticated users run it directly.
 */
class GuestRankCheckController extends Controller
{
    private const PER_MINUTE = 4;

    private const PER_DAY = 15;

    public function store(Request $request): JsonResponse
    {
        // Public tools require an account: anonymous submit runs nothing (no API)
        // and returns require:signup so the page shows the blurred gate + modal.
        if (auth()->guest()) {
            return response()->json([
                'results_url' => route('tool.preview', array_merge(['tool' => 'rank'], $request->only(['domain', 'keyword', 'country']))),
            ], 202);
        }

        $ip = (string) $request->ip();

        $minuteKey = 'guest-rank:m:'.$ip;
        $dayKey = 'guest-rank:d:'.$ip;
        if (RateLimiter::tooManyAttempts($minuteKey, self::PER_MINUTE) || RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json([
                'message' => 'You’ve run a lot of checks in a short time. Please wait a moment and try again.',
            ], 429);
        }

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        // Reduce the domain input ("https://www.example.com/path", "example.com")
        // to a bare host. A non-host (no dot) is almost always a typo.
        $domain = $this->normalizeDomain($validated['domain']);
        if ($domain === '' || ! str_contains($domain, '.')) {
            return response()->json([
                'message' => 'Enter a valid domain, like example.com.',
                'errors' => ['domain' => ['Enter a valid domain, like example.com.']],
            ], 422);
        }

        // Optional SERP country (gl). Empty = Serper's default. A non-empty value
        // comes from our own <select>, so an invalid one means tampering.
        $gl = strtolower(trim((string) $request->input('country', '')));
        if ($gl === '') {
            $gl = null;
        } elseif (! array_key_exists($gl, SerpGlCatalog::selectOptions())) {
            return response()->json([
                'message' => 'Please choose a valid country.',
                'errors' => ['country' => ['Please choose a valid country.']],
            ], 422);
        }

        if (! is_string(config('services.serper.key')) || trim((string) config('services.serper.key')) === '') {
            return response()->json(['message' => 'Rank tracking is temporarily unavailable. Please try again shortly.'], 503);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);

        // Every user is authenticated here (guests short-circuit above), so
        // there is no email/signup friction — run and show the result.
        $row = GuestRankCheck::start($validated['keyword'], $domain, $gl, $ip, null, null);
        RunGuestRankCheck::dispatch($row->id);

        return response()->json([
            'token' => $row->token,
            'status_url' => route('guest-rank.status', $row),
            'results_url' => route('guest-rank.show', $row),
            'emailed' => false,
        ], 202);
    }

    public function status(GuestRankCheck $guestRankCheck): JsonResponse
    {
        return response()->json([
            'status' => $guestRankCheck->status,
            'results_url' => route('guest-rank.show', $guestRankCheck),
        ]);
    }

    public function show(GuestRankCheck $guestRankCheck): View
    {
        return view('guest-rank-check.show', ['report' => $guestRankCheck]);
    }

    /** Reduce a URL or bare host to a comparable registrable host (no scheme/www). */
    private function normalizeDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (! str_contains($value, '://')) {
            $value = 'http://'.$value;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host)) {
            return '';
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
