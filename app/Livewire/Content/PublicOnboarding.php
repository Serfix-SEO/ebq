<?php

namespace App\Livewire\Content;

use App\Livewire\Content\Concerns\ContentWizard;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Rules\ValidRecaptcha;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\Recaptcha;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
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
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $recaptchaToken = '';

    public function mount()
    {
        // Signed-in users don't need the public flow — send them to the in-app
        // Get started (trial / activate / buy).
        if (Auth::check()) {
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
        $this->assertRecaptcha();
        $data = $this->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:40',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        if (User::query()->where('email', mb_strtolower($data['email']))->exists()) {
            throw ValidationException::withMessages([
                'email' => __('An account with this email already exists. Please log in to continue.'),
            ]);
        }

        $session = $this->session();
        if ($session === null) {
            $this->wizardStep = 1;

            return;
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'phone' => $data['phone'] !== '' ? trim($data['phone']) : null,
            'password' => $data['password'],
        ]);

        $website = $converter->convert($session, $user, [
            'business_description' => $this->businessDescription,
            'sell' => $this->sellItems,
            'dont_sell' => $this->dontSellItems,
        ]);

        event(new Registered($user));
        Auth::login($user);
        session(['current_website_id' => $website->id]);
        session()->forget('content_onboarding_token');

        $this->redirectRoute('content.settings', navigate: false);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function session(): ?ContentOnboardingSession
    {
        return $this->token === null ? null
            : ContentOnboardingSession::query()->where('token', $this->token)->first();
    }

    private function assertRecaptcha(): void
    {
        if (Recaptcha::isEnabled()) {
            validator(['g-recaptcha-response' => $this->recaptchaToken],
                ['g-recaptcha-response' => ['required', 'string', new ValidRecaptcha]],
                ['g-recaptcha-response.required' => __('Please complete the reCAPTCHA to continue.')]
            )->validate();
        }
    }

    public function render()
    {
        return view('livewire.content.public-onboarding', [
            'publicOnboarding' => true,
            'wizard' => $this->websiteId !== null ? $this->wizardViewData() : [],
        ]);
    }
}
