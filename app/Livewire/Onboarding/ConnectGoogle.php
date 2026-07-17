<?php

namespace App\Livewire\Onboarding;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Two-step onboarding, domain-first (redesigned 2026-07-17 — the old flow led
 * with the Google OAuth ask and buried the domain input in skip-text, which
 * users found confusing):
 *
 *   Step 1 "Add your website" — one domain input (pre-filled from the signup
 *   funnel when available). Creates the Website immediately and subscribes it
 *   to the shared crawl, so value starts flowing before any OAuth decision.
 *
 *   Step 2 "Connect Google (optional)" — value-framed, skippable. Not
 *   connected: a single Google button + a prominent "go to dashboard" exit.
 *   Connected: plain-language GSC/GA pickers that UPDATE the step-1 website
 *   and kick off the 365-day backfills.
 */
class ConnectGoogle extends Component
{
    public int $step = 1;

    /**
     * Picker values encode the source account alongside the property/site
     * as "accountId|value" so a GA property and a GSC site can come from
     * two different Google logins. Empty = that source is being skipped.
     */
    public string $gaSelection = '';
    public string $gscSelection = '';
    public string $domain = '';

    public bool $googleConnected = false;
    public string $fetchError = '';

    /** @var array<int, array{id: string, name: string, account_id: int, account_label: string}> */
    public array $gaOptions = [];

    /** @var array<int, array{siteUrl: string, account_id: int, account_label: string}> */
    public array $gscOptions = [];

    public function mount(): void
    {
        $user = Auth::user();
        $this->googleConnected = (bool) $user?->googleAccounts()->exists();

        // Prefill the domain — from the OAuth-bounce stash OR from the Site
        // Explorer signup funnel (RegisteredUserController stashes the
        // analyzed domain so the user never has to retype it).
        $this->domain = (string) session()->pull('onboarding.domain', $this->domain);

        // A real website already exists (created in step 1, or the user
        // bounced out to OAuth and came back) → straight to step 2.
        $website = $this->currentWebsite();
        if ($website !== null && $website->domain !== '') {
            $this->domain = $this->domain !== '' ? $this->domain : $website->domain;
            $this->step = 2;
        }

        if ($this->googleConnected && $this->step === 2) {
            // Restore any in-progress selections stashed before bouncing out
            // to OAuth to connect an extra account.
            $this->gaSelection = (string) session()->pull('onboarding.ga_selection', $this->gaSelection);
            $this->gscSelection = (string) session()->pull('onboarding.gsc_selection', $this->gscSelection);
            $this->fetchGoogleData();
        }
    }

    /**
     * Step 1 → 2: create (or fill the pay-first placeholder) website from the
     * domain alone and start its crawl — the user gets value regardless of
     * what they decide about Google on step 2.
     */
    public function addWebsite(): void
    {
        $this->validate([
            'domain' => ['required', 'string', 'max:255'],
        ], [], ['domain' => __('website address')]);

        $website = $this->persistWebsite([
            'domain' => $this->domain,
            'ga_property_id' => '',
            'ga_google_account_id' => null,
            'gsc_site_url' => '',
            'gsc_google_account_id' => null,
        ]);

        if ($website === null) {
            return; // plan-limit redirect already issued
        }

        // Free tools + audit start now: subscribe to the shared crawl_site
        // (charges the cap; reuses the existing shared crawl when covered).
        app(\App\Services\Crawler\CrawlSiteBootstrapper::class)->subscribeWebsite($website);

        $this->step = 2;
        if ($this->googleConnected) {
            $this->fetchGoogleData();
        }
    }

    public function updatedGscSelection(string $value): void
    {
        // Domain is fixed in step 1 now — nothing to auto-fill.
    }

    /**
     * "Change" on the step-2 domain chip → back to step 1 with the domain
     * pre-filled for editing. Re-submitting updates the SAME website row
     * (persistWebsite reuses it) and the Website saving hook rebinds the
     * crawl_site when the normalized domain changes.
     */
    public function changeDomain(): void
    {
        $this->step = 1;
    }

    /**
     * Stash the current picks and bounce to Google so the user can add a
     * second account (e.g. GA lives on one login, GSC on another). On
     * return we re-pool with the new account included.
     */
    public function connectAnotherAccount(): void
    {
        session([
            'onboarding.ga_selection' => $this->gaSelection,
            'onboarding.gsc_selection' => $this->gscSelection,
            'onboarding.domain' => $this->domain,
        ]);

        $this->redirect(route('google.redirect', ['return' => 'onboarding']));
    }

    /** Step 2 "Save & finish" — attach the picked sources to the step-1 website. */
    public function saveWebsite(): void
    {
        $website = $this->currentWebsite();
        if ($website === null || $website->domain === '') {
            $this->step = 1; // step 1 was somehow skipped — send them back

            return;
        }

        [$gaAccountId, $gaPropertyId] = $this->splitSelection($this->gaSelection);
        [$gscAccountId, $gscSiteUrl] = $this->splitSelection($this->gscSelection);

        // Nothing picked → same as "go to dashboard"; no error walls here.
        if ($gaPropertyId === '' && $gscSiteUrl === '') {
            $this->finishOnboarding($website);

            return;
        }

        $website->fill([
            'ga_property_id' => $gaPropertyId,
            'ga_google_account_id' => $gaPropertyId !== '' ? $gaAccountId : null,
            'gsc_site_url' => $gscSiteUrl,
            'gsc_google_account_id' => $gscSiteUrl !== '' ? $gscAccountId : null,
        ])->save();

        // Kick off the 365-day backfill for the sources actually connected.
        if ($website->hasGa()) {
            SyncAnalyticsData::dispatch($website->id, 365);
        }
        if ($website->hasGsc()) {
            SyncSearchConsoleData::dispatch($website->id, 365);
        }

        $this->finishOnboarding($website);
    }

    /**
     * "Go to my dashboard" — the explicit no-Google exit from step 2. The
     * website already exists (step 1), so this just completes onboarding.
     */
    public function skipForNow(): void
    {
        $website = $this->currentWebsite();
        if ($website === null || $website->domain === '') {
            $this->step = 1;

            return;
        }

        $this->finishOnboarding($website);
    }

    public function render()
    {
        return view('livewire.onboarding.connect-google');
    }

    /** The user's website (pay-first placeholder ranks last so real rows win). */
    private function currentWebsite(): ?Website
    {
        return Website::query()
            ->where('user_id', Auth::id())
            ->orderByRaw("CASE WHEN domain = '' THEN 1 ELSE 0 END")
            ->first();
    }

    /**
     * Reuse the pay-first placeholder row when present, otherwise create.
     * Returns null when the plan-limit gate redirected the user to billing.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function persistWebsite(array $attrs): ?Website
    {
        $userId = Auth::id();
        $user = Auth::user();

        $existing = Website::query()
            ->where('user_id', $userId)
            ->orderByRaw("CASE WHEN domain = '' THEN 0 ELSE 1 END") // placeholder first
            ->first();

        // Plan-limit gate only blocks the *create* path — updating an
        // existing placeholder doesn't add a website to the account.
        if ($existing === null && $user !== null && ! $user->canAddWebsite()) {
            $this->redirectRoute('billing.show', navigate: false);

            return null;
        }

        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing;
        }

        return Website::create(array_merge(['user_id' => $userId], $attrs));
    }

    private function finishOnboarding(Website $website): void
    {
        session()->forget(['onboarding.ga_selection', 'onboarding.gsc_selection', 'onboarding.domain']);

        // Pin this website as "current" so the dashboard's Livewire
        // components read the right website_id on first render.
        session(['current_website_id' => $website->id]);

        // One-shot flag so the overview hub shows the welcome banner once.
        // flash() clears it after the next request.
        session()->flash('just_onboarded', true);

        $this->redirectRoute('website-overview');
    }

    private function fetchGoogleData(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $pool = app(GoogleSourcePool::class)->forUser($user);
        $this->gaOptions = $pool['ga'];
        $this->gscOptions = $pool['gsc'];

        if ($pool['ga_error'] || $pool['gsc_error']) {
            $this->fetchError = __('We couldn’t load some of your Google data. You can still continue with what loaded, or reconnect the affected account.');
        }

        $this->autoSelectSources();
    }

    /**
     * Pre-pick the obvious GSC/GA options for the added domain so most users
     * just review + "Save & finish" instead of hunting through dropdowns.
     * Only fills EMPTY selections (never overrides a user's/stashed pick).
     *
     *  - GSC: exact host match against the site URL (`sc-domain:example.com`
     *    or `https://www.example.com/`, www-insensitive). sc-domain wins ties.
     *  - GA: property whose name contains the domain's brand token (name is
     *    all GA gives us) — or the only property when exactly one exists.
     */
    private function autoSelectSources(): void
    {
        $host = strtolower(trim($this->domain));
        $host = preg_replace('#^https?://#', '', rtrim($host, '/'));
        $host = preg_replace('/^www\./', '', (string) $host);
        if ($host === '') {
            return;
        }

        if ($this->gscSelection === '') {
            $exact = null;
            foreach ($this->gscOptions as $opt) {
                $site = strtolower((string) $opt['siteUrl']);
                $siteHost = str_starts_with($site, 'sc-domain:')
                    ? substr($site, 10)
                    : (string) preg_replace('/^www\./', '', (string) parse_url($site, PHP_URL_HOST));
                if ($siteHost === $host) {
                    $exact = $opt['account_id'].'|'.$opt['siteUrl'];
                    if (str_starts_with($site, 'sc-domain:')) {
                        break; // domain property beats URL-prefix
                    }
                }
            }
            if ($exact !== null) {
                $this->gscSelection = $exact;
            }
        }

        if ($this->gaSelection === '') {
            if (count($this->gaOptions) === 1) {
                $only = $this->gaOptions[0];
                $this->gaSelection = $only['account_id'].'|'.$only['id'];
            } else {
                $brand = preg_replace('/[^a-z0-9]/', '', explode('.', $host)[0]);
                if ($brand !== '') {
                    $matches = array_values(array_filter($this->gaOptions, fn ($opt) => str_contains(
                        (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $opt['name'])), $brand
                    )));
                    if (count($matches) === 1) {
                        $this->gaSelection = $matches[0]['account_id'].'|'.$matches[0]['id'];
                    }
                }
            }
        }
    }

    /**
     * Split an "accountId|value" picker value into [accountId, value].
     *
     * @return array{0: int|null, 1: string}
     */
    private function splitSelection(string $selection): array
    {
        if ($selection === '') {
            return [null, ''];
        }

        $pos = strpos($selection, '|');
        if ($pos === false) {
            return [null, $selection];
        }

        $accountId = substr($selection, 0, $pos);

        return [($accountId !== null && $accountId !== '') ? $accountId : null, substr($selection, $pos + 1)];
    }
}
