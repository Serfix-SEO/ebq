<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Models\WebsiteInvitation;
use App\Rules\ValidRecaptcha;
use App\Support\Recaptcha;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request): View
    {
        $inviteToken = (string) $request->query('invite', '');
        $invitationEmail = '';
        if ($inviteToken !== '') {
            $invitation = WebsiteInvitation::findValidByPlainToken($inviteToken);
            if ($invitation) {
                $invitationEmail = $invitation->email;
            }
        }

        // Carry the plan slug + billing interval from the /pricing CTA
        // (`/register?plan=pro&interval=monthly`) through register →
        // store() → billing checkout. Stored in session so it survives
        // the form POST without being a hidden field an attacker could swap.
        $planSlug = $this->capturePendingPlan($request);

        return view('auth.register', [
            'inviteToken' => $inviteToken,
            'invitationEmail' => $invitationEmail,
            'pendingPlan' => $planSlug,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'dial_code' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\DialCodes::validCodes())],
            'phone' => ['nullable', 'string', 'min:5', 'max:20', 'regex:/^[0-9()\-.\s]+$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'invite' => ['nullable', 'string', 'max:128'],
        ];

        if (Recaptcha::isEnabled()) {
            $rules['g-recaptcha-response'] = ['required', 'string', new ValidRecaptcha];
        }

        $validated = $request->validate($rules, [
            'g-recaptcha-response.required' => 'Please complete the reCAPTCHA to continue.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => ! empty($validated['phone'])
                ? trim(($validated['dial_code'] ?? '').' '.trim($validated['phone']))
                : null,
            'password' => $validated['password'],
        ]);

        WebsiteInvitation::query()
            ->where('email', Str::lower($validated['email']))
            ->where('expires_at', '>', now())
            ->get()
            ->each(fn (WebsiteInvitation $invitation) => $invitation->acceptFor($user));

        event(new Registered($user));

        Auth::login($user);

        // Pulled ONCE before any branch so no early return can orphan the
        // funnel domain in session (the pay-first branch used to).
        $analyzeDomain = (string) $request->session()->pull('analyze_domain', '');

        // Pay-first flow: when the user picked a paid plan on /pricing,
        // jump straight to Stripe Checkout. BillingController auto-creates
        // a placeholder Website to attach the subscription to, and after
        // Stripe success the user lands on /onboarding to fill in their
        // real domain — which UPDATES the placeholder so the subscription
        // stays linked to the same row. Email verification still happens
        // later via the standard verified-route middleware; we don't gate
        // checkout on it (Stripe collects a verified email of its own).
        $pendingPlan     = (string) $request->session()->pull('pending_plan', '');
        $pendingInterval = (string) $request->session()->pull('pending_plan_interval', 'annual');
        if ($pendingPlan !== '' && $this->isCheckoutablePlan($pendingPlan, $pendingInterval)) {
            // Don't create a Website here — the placeholder flow owns that.
            // Hand the funnel domain to post-checkout onboarding instead
            // (ConnectGoogle::mount() prefills from this key).
            if ($analyzeDomain !== '') {
                $request->session()->put('onboarding.domain', $analyzeDomain);
            }

            return redirect()->route('billing.checkout', array_filter([
                'plan'     => $pendingPlan,
                'interval' => $pendingInterval !== 'annual' ? $pendingInterval : null,
            ]));
        }

        // Public tool gate: return to the tool result the visitor was viewing
        // (safe local path only — never an open redirect).
        $redirect = (string) $request->input('redirect', '');
        if ($redirect !== '' && str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return redirect()->to($redirect);
        }

        // Homepage "Analyze website" funnel: a domain the visitor entered
        // before signing up. Attach it to the new account first — that kicks
        // the crawl subscription + historical import and satisfies the
        // onboarding gate — then show their report immediately (the report
        // view is auth-gated but NOT verified-gated, so first value lands
        // before email verification).
        if ($analyzeDomain !== '') {
            app(\App\Services\WebsiteAttachService::class)->attach($user, $analyzeDomain);

            return redirect()->route('report.view', ['url' => $analyzeDomain]);
        }

        return redirect()->route('verification.notice');
    }

    /**
     * Read `?plan=` from the request, validate against an active plan, and
     * stash it in session so the subsequent POST → store() can pick it up.
     * Returns the slug (or '') so the view can show a "you'll be billed
     * for the X plan after sign-up" hint.
     */
    private function capturePendingPlan(Request $request): string
    {
        $slug     = trim((string) $request->query('plan', ''));
        $interval = in_array($request->query('interval'), ['monthly', 'annual'], true)
            ? $request->query('interval')
            : 'annual';

        if ($slug === '') {
            return (string) $request->session()->get('pending_plan', '');
        }
        if (! $this->isCheckoutablePlan($slug, $interval)) {
            return '';
        }
        $request->session()->put('pending_plan', $slug);
        $request->session()->put('pending_plan_interval', $interval);

        return $slug;
    }

    /**
     * True when `$slug` matches an active, checkout-ready paid plan for
     * the given billing interval. Free tiers and unknown slugs return false.
     */
    private function isCheckoutablePlan(string $slug, string $interval = 'annual'): bool
    {
        if ($slug === '' || $slug === 'free') {
            return false;
        }
        $plan = Plan::where('slug', $slug)->where('is_active', true)->first();

        return $plan !== null && $plan->isCheckoutReady($interval);
    }
}
