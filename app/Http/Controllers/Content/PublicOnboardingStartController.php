<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Models\WebsiteReportSnapshot;
use App\Rules\ValidRecaptcha;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\Audit\SafeHttpGuard;
use App\Support\ContentAutopilotConfig;
use App\Support\Recaptcha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Entry point for the public Content Autopilot funnel: the landing page
 * (`/content-autopilot`) collects the website domain + reCAPTCHA and POSTs here.
 * We verify (captcha, throttle, SSRF), create the provisional website, stash the
 * onboarding-session token, then hand off to the wizard at the Business step —
 * so the domain is asked ONCE, on the landing page, not again in the wizard.
 */
class PublicOnboardingStartController extends Controller
{
    public function __invoke(Request $request, SafeHttpGuard $guard, ContentOnboardingConverter $converter): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('content.get-started');
        }

        // reCAPTCHA (standard form field g-recaptcha-response).
        if (Recaptcha::isEnabled()) {
            $request->validate(
                ['g-recaptcha-response' => ['required', 'string', new ValidRecaptcha]],
                ['g-recaptcha-response.required' => __('Please complete the reCAPTCHA to continue.')]
            );
        }

        // Rate limit (per-IP hourly/daily + global daily; admin-tunable).
        $ip = (string) $request->ip();
        $t = ContentAutopilotConfig::onboardingThrottle();
        $keys = ['content-onboard:h:'.$ip, 'content-onboard:d:'.$ip, 'content-onboard:global:d'];
        if (RateLimiter::tooManyAttempts($keys[0], $t['per_ip_hourly'])
            || RateLimiter::tooManyAttempts($keys[1], $t['per_ip_daily'])
            || RateLimiter::tooManyAttempts($keys[2], $t['global_daily'])) {
            return back()->withInput()->withErrors([
                'domain' => __('We are getting a lot of sign-ups right now. Please try again a little later.'),
            ]);
        }

        // Normalize + SSRF-guard the domain.
        $raw = trim((string) $request->input('domain'));
        if ($raw !== '' && ! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }
        if (! ($guard->check($raw)['ok'] ?? false)) {
            return back()->withInput()->withErrors(['domain' => __('Enter a public website address (https://…).')]);
        }
        $domain = WebsiteReportSnapshot::normalizeDomain($raw);
        if ($domain === '') {
            return back()->withInput()->withErrors(['domain' => __('Enter a valid website domain.')]);
        }

        // Count the attempt only once we're past validation.
        RateLimiter::hit($keys[0], 3600);
        RateLimiter::hit($keys[1], 86400);
        RateLimiter::hit($keys[2], 86400);

        [$session] = $converter->begin($domain, $ip);
        session(['content_onboarding_token' => $session->token]);

        // Remember the URL the visitor actually TYPED (path included) — the
        // profile detector prefers it over the bare domain, because sites like
        // kayali.com serve nothing at the root (a bare 302 to /en-ae) and the
        // real content lives under the entered path. SSRF-guarded above.
        $path = trim((string) parse_url($raw, PHP_URL_PATH), '/');
        if ($path !== '' && $session->website_id) {
            \Illuminate\Support\Facades\Cache::put(
                'content:entered-url:'.$session->website_id,
                $raw,
                now()->addDays(30)
            );
        }

        // Straight into the wizard (Business step) — no second domain prompt.
        return redirect()->route('content.onboarding');
    }
}
