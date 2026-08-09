<?php

namespace App\Livewire\Content;

use App\Livewire\Content\Concerns\ContentWizard;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\KeywordFinderLocations;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public, anonymous Content Autopilot onboarding — a FULL-PAGE mirror of the
 * in-dashboard 7-step wizard (business → offerings → how-it-works → images →
 * competitors → keyword research → first articles) with an 8th "create account"
 * step at the end. A domain-capture screen precedes the wizard: it creates a
 * provisional website (owned by the system "content-leads" user) so the whole
 * pipeline — crawl, profile extraction, competitor authority, keyword research,
 * topic ideation — runs anonymously and the visitor SEES the value before
 * signing up. On finish we create the account, re-parent the website, start the
 * trial and drop the user into the dashboard.
 *
 * The wizard logic lives in the shared {@see ContentWizard} trait; the markup is
 * the shared livewire/content/partials/wizard partial ($publicOnboarding=true).
 */
#[Layout('components.layouts.content-onboarding')]
class PublicOnboarding extends Component
{
    use ContentWizard;

    public ?string $websiteId = null;

    public string $domain = '';

    public ?string $token = null;

    public string $name = '';

    public string $email = '';

    public string $dialCode = '+1';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(ContentOnboardingConverter $converter)
    {
        // Signed-in users don't need the ACCOUNT step — but they may well have
        // just captured a domain on the public landing page. Bouncing them
        // straight to Get started dropped that domain on the floor and judged
        // some other (preferred) website instead. Convert the pending session
        // for them here, so the site they actually asked for is attached and
        // covered (subscription, comped free slot, or trial) before routing.
        if (Auth::check()) {
            $pending = $this->pendingSession();

            // Content-only mode: a signed-in user with a captured domain walks
            // the SAME wizard as a guest (steps 1-7, then finish() converts).
            // The instant convert below shipped an ACTIVE plan with an EMPTY
            // profile — topic generation fired with no business description.
            if ($pending !== null
                && ! config('features.seo_platform_ui')
                && Website::query()->whereKey($pending->website_id)->exists()) {
                $this->token = $pending->token;
                $this->domain = (string) $pending->domain;
                $this->websiteId = $pending->website_id;
                $this->wizardStep = max(1, (int) $pending->step);
                $this->bootWizard();

                return;
            }

            if ($pending !== null && Website::query()->whereKey($pending->website_id)->exists()) {
                $result = $converter->convert($pending, Auth::user(), []);
                session(['current_website_id' => $result['website']->id]);
                session()->forget('content_onboarding_token');

                return $this->redirectRoute(
                    $result['covered'] ? 'content.index' : 'content.get-started',
                    navigate: false,
                );
            }

            // Stale token (GC'd provisional site) — drop it so the next visit
            // starts clean instead of hitting the same dead session.
            if ($pending !== null) {
                session()->forget('content_onboarding_token');
            }

            return $this->redirectRoute('content.get-started', navigate: false);
        }

        // Resume the onboarding session created on the landing page.
        $token = (string) session('content_onboarding_token', '');
        if ($token !== '') {
            $s = ContentOnboardingSession::query()->where('token', $token)->whereNull('converted_at')->first();
            if ($s !== null) {
                $this->token = $s->token;
                $this->domain = (string) $s->domain;
                $this->websiteId = $s->website_id;
                $this->wizardStep = max(1, (int) $s->step);
                $this->bootWizard();

                return;
            }
        }

        // No captured domain → the visitor skipped the landing form. The domain
        // is collected ONCE, on the landing page (with reCAPTCHA); send them there.
        return $this->redirectRoute('content.landing', navigate: false);
    }

    /** Persist wizard progress so a reload resumes where the visitor left off. */
    public function dehydrate(): void
    {
        if ($this->token !== null) {
            ContentOnboardingSession::query()->where('token', $this->token)->whereNull('converted_at')
                ->update(['step' => max(1, min($this->wizardStep, 7))]);
        }
    }

    // ── Provisional site/plan resolvers (no auth — session-token scoped) ─

    private function website(): ?Website
    {
        return $this->websiteId
            ? Website::query()->whereKey($this->websiteId)->first()
            : null;
    }

    private function plan(): ?ContentPlan
    {
        return $this->websiteId
            ? ContentPlan::query()->where('website_id', $this->websiteId)->first()
            : null;
    }

    // ── Step 7 → 8 (account) ────────────────────────────────────────────

    public function toAccount(): void
    {
        if ($this->websiteId !== null) {
            $this->wizardStep = 8;
        }
    }

    // ── Step 8: account → convert ───────────────────────────────────────

    public function createAccount(ContentOnboardingConverter $converter): void
    {
        if ($this->token === null) {
            $this->wizardStep = 1;

            return;
        }
        $email = mb_strtolower(trim($this->email));
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Already has an account → log them in with the password they typed
            // and attach this website to that account (they may already have
            // other websites — convert() just adds this one).
            $this->validate([
                'email' => 'required|email|max:190',
                'password' => 'required|string',
            ]);
            if (! Auth::attempt(['email' => $email, 'password' => $this->password], true)) {
                throw ValidationException::withMessages([
                    'password' => __('An account with this email already exists, but that password is wrong. Enter your password, or continue with Google.'),
                ]);
            }
            $user = $existing;
        } else {
            $data = $this->validate([
                'name' => 'required|string|max:120',
                'email' => 'required|email|max:190',
                'phone' => 'nullable|string|max:40',
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] !== '' ? trim($this->dialCode.' '.$data['phone']) : null,
                'password' => $data['password'],
            ]);
            event(new Registered($user));
            Auth::login($user);
        }

        $session = $this->session();
        if ($session === null) {
            $this->wizardStep = 1;

            return;
        }

        $result = $converter->convert($session, $user, $this->builtProfile());

        session(['current_website_id' => $result['website']->id]);
        session()->forget('content_onboarding_token');

        // Covered (trial or a free subscription slot) → the content calendar.
        // Uncovered (trial already used, or subscription full) → Get started,
        // where they pay for this additional site.
        $this->redirectRoute($result['covered'] ? 'content.index' : 'content.get-started', navigate: false);
    }

    /**
     * Step-7 finish for a user who is ALREADY signed in (they entered through
     * the Get started domain form, or logged in mid-wizard). Same convert as
     * createAccount(), minus the account step the wizard hides for them.
     */
    public function finish(ContentOnboardingConverter $converter): void
    {
        if (! Auth::check() || $this->token === null) {
            return;
        }

        $session = $this->session();
        if ($session === null || $session->converted_at !== null) {
            // Converted in another tab, or GC'd — nothing left to finish here.
            $this->redirectRoute('content.get-started', navigate: false);

            return;
        }

        $result = $converter->convert($session, Auth::user(), $this->builtProfile());

        session(['current_website_id' => $result['website']->id]);
        session()->forget('content_onboarding_token');

        $this->redirectRoute($result['covered'] ? 'content.index' : 'content.get-started', navigate: false);
    }

    /** The wizard-typed profile convert() persists onto the plan. */
    private function builtProfile(): array
    {
        return [
            'business_description' => $this->businessDescription,
            'sell' => $this->sellItems,
            'dont_sell' => $this->dontSellItems,
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function session(): ?ContentOnboardingSession
    {
        return $this->token === null ? null
            : ContentOnboardingSession::query()->where('token', $this->token)->first();
    }

    /** The unconverted onboarding session this browser is carrying, if any. */
    private function pendingSession(): ?ContentOnboardingSession
    {
        $token = (string) session('content_onboarding_token', '');

        return $token === '' ? null : ContentOnboardingSession::query()
            ->where('token', $token)
            ->whereNull('converted_at')
            ->whereNotNull('website_id')
            ->first();
    }

    public function render()
    {
        return view('livewire.content.public-onboarding', [
            'publicOnboarding' => true,
            // Signed-in users skip the account step; step 7 ends with finish().
            'authedFinish' => Auth::check(),
            'wizard' => $this->websiteId !== null ? $this->wizardViewData() : [],
            'countryOptions' => KeywordFinderLocations::countryOptions(),
        ]);
    }
}
