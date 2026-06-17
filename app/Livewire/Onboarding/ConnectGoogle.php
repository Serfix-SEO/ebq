<?php

namespace App\Livewire\Onboarding;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

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

        if ($this->googleConnected) {
            $this->step = 2;
            // Restore any in-progress selections that were stashed before
            // bouncing out to OAuth to connect an extra account.
            $this->gaSelection = (string) session()->pull('onboarding.ga_selection', $this->gaSelection);
            $this->gscSelection = (string) session()->pull('onboarding.gsc_selection', $this->gscSelection);
            $this->domain = (string) session()->pull('onboarding.domain', $this->domain);
            $this->fetchGoogleData();
        }
    }

    public function goToStep(int $step): void
    {
        if ($step === 2 && ! $this->googleConnected) {
            return;
        }

        $this->step = $step;
    }

    public function updatedGscSelection(string $value): void
    {
        if ($value === '' || $this->domain !== '') {
            return;
        }

        [, $siteUrl] = $this->splitSelection($value);
        if ($siteUrl !== '') {
            $this->domain = $this->extractDomain($siteUrl);
        }
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

    public function saveWebsite(): void
    {
        $this->validate([
            'domain' => ['required', 'string', 'max:255'],
            'gaSelection' => ['nullable', 'string', 'max:512'],
            'gscSelection' => ['nullable', 'string', 'max:512'],
        ]);

        [$gaAccountId, $gaPropertyId] = $this->splitSelection($this->gaSelection);
        [$gscAccountId, $gscSiteUrl] = $this->splitSelection($this->gscSelection);

        // Need at least one real data source — the "connect neither" exit
        // is the explicit Skip button, not an empty Finish.
        if ($gaPropertyId === '' && $gscSiteUrl === '') {
            $this->addError('gaSelection', 'Connect at least one of Google Analytics or Search Console — or use “Skip for now”.');

            return;
        }

        $website = $this->persistWebsite([
            'domain' => $this->domain,
            'ga_property_id' => $gaPropertyId,
            'ga_google_account_id' => $gaPropertyId !== '' ? $gaAccountId : null,
            'gsc_site_url' => $gscSiteUrl,
            'gsc_google_account_id' => $gscSiteUrl !== '' ? $gscAccountId : null,
        ]);

        if ($website === null) {
            return; // plan-limit redirect already issued
        }

        // Kick off the 365-day backfill for the sources that are actually
        // connected. The Website model's created hook does the same for
        // fresh rows, but the pay-first flow updates a pre-existing
        // placeholder, so we (re)dispatch here once the real IDs are set.
        // NOTE: requires a running queue worker (QUEUE_CONNECTION=database).
        if ($website->hasGa()) {
            SyncAnalyticsData::dispatch($website->id, 365);
        }
        if ($website->hasGsc()) {
            SyncSearchConsoleData::dispatch($website->id, 365);
        }
        // Subscribe to the shared crawl_site: charge the cap, and crawl only if the
        // domain isn't already covered (otherwise the user instantly reuses the
        // existing shared crawl). Frontier seeds the homepage, so GSC isn't required.
        app(\App\Services\Crawler\CrawlSiteBootstrapper::class)->subscribeWebsite($website);

        $this->finishOnboarding($website);
    }

    /**
     * "Skip for now" — let users in with no Google data at all. They land
     * on the dashboard (full of connect prompts) and can still use the
     * free PageSpeed tool. We create a minimal Website row so the
     * onboarding gate is satisfied.
     */
    public function skipForNow(): void
    {
        // Require a domain so the skip yields a real, named website (free
        // tools, crawl, keyword research) rather than an empty placeholder.
        $this->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $website = $this->persistWebsite([
            'domain' => $this->domain,
            'ga_property_id' => '',
            'ga_google_account_id' => null,
            'gsc_site_url' => '',
            'gsc_google_account_id' => null,
        ]);

        if ($website === null) {
            return;
        }

        // A domain-only add of an already-crawled domain still gets the shared data.
        app(\App\Services\Crawler\CrawlSiteBootstrapper::class)->subscribeWebsite($website);

        $this->finishOnboarding($website);
    }

    public function render()
    {
        return view('livewire.onboarding.connect-google');
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

        // One-shot flag so the dashboard shows the welcome / "pulling your
        // data" modal once. flash() clears it after the next request.
        session()->flash('just_onboarded', true);

        $this->redirectRoute('dashboard');
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
            $this->fetchError = 'We couldn’t load some of your Google data. You can still continue with what loaded, or reconnect the affected account.';
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

    private function extractDomain(string $siteUrl): string
    {
        $url = str_replace('sc-domain:', '', $siteUrl);
        $parsed = parse_url($url, PHP_URL_HOST);

        return $parsed ?: preg_replace('#^https?://#', '', rtrim($url, '/'));
    }
}
