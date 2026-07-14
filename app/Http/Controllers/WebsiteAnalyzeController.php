<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateWebsiteReport;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteReportSnapshot;
use App\Rules\ValidRecaptcha;
use App\Services\ClientActivityLogger;
use App\Services\ReportFreshnessGate;
use App\Support\Audit\SafeHttpGuard;
use App\Support\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Homepage "Analyze website" funnel. A visitor enters a URL and clicks Analyze.
 *
 * KEY RULE — no anonymous provider spend: for a signed-OUT visitor this
 * dispatches NO report job and calls NO DataForSEO/Moz API. It validates the
 * URL (throttle + SSRF + reCAPTCHA), stashes the domain in the session, and
 * returns `require:'signup'`. The frontend then shows a blurred teaser behind
 * the signup modal. Only a signed-IN request triggers report generation.
 */
class WebsiteAnalyzeController extends Controller
{
    private const PER_MINUTE = 5;

    private const PER_DAY = 30;

    public function store(Request $request, SafeHttpGuard $guard, ReportFreshnessGate $gate, ClientActivityLogger $logger): JsonResponse
    {
        // Per-IP burst/day guard — ANONYMOUS requests only. This exists to stop
        // pre-signup scraping/abuse of the free teaser, where there's no other
        // governance yet (no account, no plan). A signed-IN request is already
        // governed by the per-plan Site Explorer limit further down — which is
        // domain-aware (exempt for the user's own websites, deduped per-domain
        // per window) — so applying this blanket per-IP cap on top of it was
        // hiding that real, more specific limit behind a generic message, and
        // could block a user from re-analyzing their OWN attached site from a
        // shared/office IP that had already made 30 requests that day.
        $ip = (string) $request->ip();
        $minuteKey = 'analyze:m:'.$ip;
        $dayKey = 'analyze:d:'.$ip;
        if (! Auth::check()) {
            // Report the REAL limit + retry time, not a vague "try again
            // later" — whichever window was actually exceeded (checked in
            // the order a user would realistically hit them: the tight
            // per-minute burst guard first, then the daily cap).
            if (RateLimiter::tooManyAttempts($minuteKey, self::PER_MINUTE)) {
                $secs = max(1, RateLimiter::availableIn($minuteKey));

                return response()->json([
                    'message' => "You've reached the limit of ".self::PER_MINUTE." analyses per minute. Try again in {$secs}s.",
                ], 429);
            }
            if (RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
                $mins = (int) ceil(RateLimiter::availableIn($dayKey) / 60);

                return response()->json([
                    'message' => "You've reached the limit of ".self::PER_DAY." analyses per day. Try again in {$mins} min.",
                ], 429);
            }
        }

        $rawUrl = trim((string) $request->input('url', ''));
        if ($rawUrl !== '' && ! preg_match('#^https?://#i', $rawUrl)) {
            $rawUrl = 'https://'.$rawUrl;
        }
        $request->merge(['url' => $rawUrl]);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:700'],
        ]);

        $check = $guard->check($validated['url']);
        if (! ($check['ok'] ?? false)) {
            return response()->json([
                'message' => 'That URL can’t be analyzed. Enter a public website address (https://…).',
                'errors' => ['url' => ['That URL can’t be analyzed. Enter a public website address (https://…).']],
            ], 422);
        }

        $domain = WebsiteReportSnapshot::normalizeDomain($validated['url']);
        if ($domain === '') {
            return response()->json([
                'message' => 'Enter a valid website domain.',
                'errors' => ['url' => ['Enter a valid website domain.']],
            ], 422);
        }

        // reCAPTCHA (bot gate) — only for anonymous visitors; logged-in users
        // (dashboard Site Explorer) are already authenticated, no captcha.
        if (Recaptcha::isEnabled() && ! Auth::check()) {
            $request->validate(
                ['g-recaptcha-response' => ['required', 'string', new ValidRecaptcha]],
                ['g-recaptcha-response.required' => 'Please complete the reCAPTCHA below to continue.'],
            );
        }

        if (! Auth::check()) {
            RateLimiter::hit($minuteKey, 60);
            RateLimiter::hit($dayKey, 86400);
        }

        // Remember the domain across the signup/signin round-trip.
        $request->session()->put('analyze_domain', $domain);

        // Signed OUT → NO API call. The report page renders a blurred MOCK
        // teaser + signup modal (generation only happens after signup).
        if (! Auth::check()) {
            return response()->json([
                'results_url' => route('report.view', ['url' => $domain]),
            ], 202);
        }

        // Signed IN → per-plan lookup throttle (admins exempt), then generate.
        // Only a genuinely NEW lookup consumes the quota: a domain the user
        // already owns as one of their websites never counts (they get that
        // data for free elsewhere), and re-analyzing a domain they've already
        // been charged for within the current window doesn't count again —
        // re-opening the same report (cache hit or not) is not a second
        // "lookup". A DIFFERENT user analyzing the same domain is unaffected
        // (the dedup key is per-user, per-domain, per-window).
        $user = Auth::user();
        if (! $user->is_admin && ! $this->ownsWebsiteForDomain($user, $domain)) {
            $plan = $user->effectivePlan();
            $limit = $plan?->siteExplorerLimit();
            if ($limit !== null) {
                $windowHours = $plan->siteExplorerWindowHours();
                $ttl = $windowHours * 3600;
                $seenKey = 'site-explorer-seen:'.$user->id.':'.$domain;

                if (! Cache::has($seenKey)) {
                    $key = 'site-explorer:'.$user->id;
                    if (RateLimiter::tooManyAttempts($key, $limit)) {
                        $mins = (int) ceil(RateLimiter::availableIn($key) / 60);

                        return response()->json([
                            'message' => "You've reached your Site Explorer limit ({$limit} per {$windowHours}h). Try again in {$mins} min.",
                        ], 429);
                    }
                    RateLimiter::hit($key, $ttl);
                    Cache::put($seenKey, true, $ttl);
                }
            }
        }

        Lead::capture((string) $user->email, (string) $user->name);

        $sandbox = (bool) $user->is_admin;
        $logger->log('site_explorer.query', userId: (string) $user->id, meta: [
            'domain' => $domain,
            'cache_hit' => $gate->isFresh($domain, $sandbox),
            'sandbox' => $sandbox,
        ]);

        GenerateWebsiteReport::dispatch($domain, false, $sandbox);

        return response()->json([
            'results_url' => route('report.view', ['url' => $domain]),
        ], 202);
    }

    private function ownsWebsiteForDomain(User $user, string $domain): bool
    {
        return Website::query()
            ->where('normalized_domain', $domain)
            ->get()
            ->contains(fn (Website $w) => $user->canViewWebsiteId($w->id));
    }
}
