<?php

namespace App\Livewire\Content;

use App\Models\ContentOnboardingSession;
use App\Models\User;
use App\Rules\ValidRecaptcha;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\Audit\SafeHttpGuard;
use App\Models\WebsiteReportSnapshot;
use App\Support\ContentAutopilotConfig;
use App\Support\Recaptcha;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public, anonymous Content Autopilot onboarding. Step 1 asks for the website
 * domain (which becomes the new user's website); steps 2-3 collect the business
 * profile and account details. On finish we create the account, re-parent the
 * provisional website, start the trial and drop the user into the dashboard —
 * where topic ideation + keyword research (dispatched here) are already running.
 */
#[Layout('components.layouts.guest')]
class PublicOnboarding extends Component
{
    public int $step = 1;

    public string $domain = '';
    public ?string $token = null;

    public string $businessDescription = '';
    public array $sellItems = [];
    public array $dontSellItems = [];
    public string $newSell = '';
    public string $newDont = '';

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

        // Resume an unconverted session if the visitor already started.
        $token = (string) session('content_onboarding_token', '');
        if ($token !== '') {
            $s = ContentOnboardingSession::query()->where('token', $token)->whereNull('converted_at')->first();
            if ($s !== null) {
                $this->token = $s->token;
                $this->domain = (string) $s->domain;
                $this->step = max(2, (int) $s->step);

                return;
            }
        }

        // Prefill the domain typed on the landing hero (?domain=…). Step 1 stays
        // so the SSRF/reCAPTCHA/throttle guards in startWithDomain still run.
        $prefill = trim((string) request()->query('domain', ''));
        if ($prefill !== '') {
            $this->domain = mb_substr($prefill, 0, 255);
        }
    }

    // ── Step 1: domain → provisional website ────────────────────────────

    public function startWithDomain(SafeHttpGuard $guard, ContentOnboardingConverter $converter): void
    {
        $this->assertRecaptcha();
        $this->assertThrottle();

        $raw = trim($this->domain);
        if ($raw !== '' && ! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }
        if (! ($guard->check($raw)['ok'] ?? false)) {
            throw ValidationException::withMessages(['domain' => __('Enter a public website address (https://…).')]);
        }
        $domain = WebsiteReportSnapshot::normalizeDomain($raw);
        if ($domain === '') {
            throw ValidationException::withMessages(['domain' => __('Enter a valid website domain.')]);
        }

        $this->hitThrottle();
        [$session] = $converter->begin($domain, request()->ip());
        $this->token = $session->token;
        $this->domain = $domain;
        session(['content_onboarding_token' => $session->token]);
        $this->recaptchaToken = '';
        $this->step = 2;
    }

    // ── Step 2: business profile + offerings ────────────────────────────

    public function addSell(): void
    {
        $v = trim($this->newSell);
        if ($v !== '') {
            $this->sellItems[] = $v;
            $this->newSell = '';
        }
    }

    public function addDont(): void
    {
        $v = trim($this->newDont);
        if ($v !== '') {
            $this->dontSellItems[] = $v;
            $this->newDont = '';
        }
    }

    public function removeSell(int $i): void
    {
        unset($this->sellItems[$i]);
        $this->sellItems = array_values($this->sellItems);
    }

    public function removeDont(int $i): void
    {
        unset($this->dontSellItems[$i]);
        $this->dontSellItems = array_values($this->dontSellItems);
    }

    public function toProfile(): void
    {
        $this->step = 2;
    }

    public function toDetails(): void
    {
        $this->validate(['businessDescription' => 'required|string|min:30|max:1000']);
        $this->session()?->update(['step' => 3]);
        $this->step = 3;
    }

    // ── Step 3: account → convert ───────────────────────────────────────

    public function createAccount(ContentOnboardingConverter $converter): void
    {
        if ($this->token === null) {
            $this->step = 1;

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
            $this->step = 1;

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

    private function throttleKeys(): array
    {
        $ip = (string) request()->ip();

        return ['content-onboard:h:'.$ip, 'content-onboard:d:'.$ip, 'content-onboard:global:d'];
    }

    private function assertThrottle(): void
    {
        $t = ContentAutopilotConfig::onboardingThrottle();
        [$hour, $day, $global] = $this->throttleKeys();
        if (RateLimiter::tooManyAttempts($hour, $t['per_ip_hourly'])
            || RateLimiter::tooManyAttempts($day, $t['per_ip_daily'])
            || RateLimiter::tooManyAttempts($global, $t['global_daily'])) {
            throw ValidationException::withMessages([
                'domain' => __('We are getting a lot of sign-ups right now. Please try again a little later.'),
            ]);
        }
    }

    private function hitThrottle(): void
    {
        [$hour, $day, $global] = $this->throttleKeys();
        RateLimiter::hit($hour, 3600);
        RateLimiter::hit($day, 86400);
        RateLimiter::hit($global, 86400);
    }

    public function render()
    {
        return view('livewire.content.public-onboarding');
    }
}
