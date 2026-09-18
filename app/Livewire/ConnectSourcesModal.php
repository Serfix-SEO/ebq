<?php

namespace App\Livewire;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * App-wide "connect your data sources" modal. Lives once in the app
 * layout and opens on the `open-connect-sources` event (dispatched by the
 * connect-source banner and the section prompts), so a user can attach a
 * GA property / GSC site to the current website from anywhere — without
 * leaving the page.
 *
 * The Google pool (which calls the GA/GSC list APIs) is only fetched when
 * the modal is actually opened, never on every page render.
 */
class ConnectSourcesModal extends Component
{
    public ?string $websiteId = null;

    /** "accountId|value" picker values, mirroring onboarding. */
    public string $gaSelection = '';
    public string $gscSelection = '';

    public bool $hasGoogle = false;
    public bool $loaded = false;
    public string $fetchError = '';
    public string $saved = '';

    /**
     * Guidance above the GA dropdown when the list cannot contain this
     * site's property: the login has none at all, or none that looks like
     * this domain. The second case is the common one — the site's Analytics
     * was set up under a different Google login (safibusinessservice.com,
     * 2026-09-17: their login saw only another company's property) — and a
     * bare "Not connected" option explained nothing.
     */
    public string $gaHint = '';

    /** Where "connect another Google login" returns to (see GoogleOAuthController). */
    public string $googleReturn = 'settings.integrations';

    /** @var array<int, array{id: string, name: string, account_id: int, account_label: string}> */
    public array $gaOptions = [];

    /** @var array<int, array{siteUrl: string, account_id: int, account_label: string}> */
    public array $gscOptions = [];

    /** @var array<int, array{id: int, label: string}> */
    public array $accounts = [];

    /**
     * Invoked from the modal's Alpine scope ($wire.open()) when the
     * `open-connect-sources` window event fires. Fetches the Google pool
     * lazily — never on a normal page render.
     *
     * An explicit $websiteId (passed via the event's detail) targets a
     * specific site — e.g. the audited site on the audit-detail page, which
     * may differ from the session's "current" website. Falls back to the
     * current website otherwise. Ownership is still enforced in
     * {@see editableWebsite()}.
     */
    public function open(?string $websiteId = null): void
    {
        $this->reset(['saved', 'fetchError', 'gaSelection', 'gscSelection', 'gaOptions', 'gscOptions', 'accounts', 'loaded']);
        $this->websiteId = ($websiteId !== null && $websiteId !== '') ? $websiteId : session('current_website_id');
        $this->googleReturn = config('features.seo_platform_ui') ? 'settings.integrations' : 'content.sources';
        $this->loadPool();
        $this->loadCurrentSelections();
        $this->gaHint = $this->gaHintFor($this->editableWebsite());
    }

    private function gaHintFor(?Website $website): string
    {
        if ($website === null || ! $this->hasGoogle || $website->hasGa() || $this->fetchError !== '') {
            return '';
        }
        if ($this->gaOptions === []) {
            return __('This Google login has no Google Analytics properties. If your site\'s Analytics was set up with a different Google login, connect that one below.');
        }

        $domain = strtolower((string) ($website->normalized_domain ?: $website->domain));
        $domain = (string) preg_replace('/^www\./', '', $domain);
        // Property names are free text ("Safi Business Services - GA4"), so
        // compare letters and digits only: "safibusinessservicesga4" contains
        // the domain's first label "safibusinessservice".
        $compact = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $label = $compact(strtok($domain, '.') ?: $domain);
        foreach ($this->gaOptions as $option) {
            $name = strtolower((string) ($option['name'] ?? ''));
            if ($domain !== '' && (str_contains($name, $domain) || (strlen($label) >= 4 && str_contains($compact($name), $label)))) {
                return '';
            }
        }

        return __('None of these Analytics properties looks like :domain. If its Analytics was set up with a different Google login, connect that login below — or, in Google Analytics, add this login as a Viewer on the property.', ['domain' => $domain]);
    }

    public function saveSources(): void
    {
        $website = $this->editableWebsite();
        if (! $website) {
            $this->fetchError = 'Select a website first.';

            return;
        }

        [$gaAccountId, $gaPropertyId] = $this->splitSelection($this->gaSelection);
        [$gscAccountId, $gscSiteUrl] = $this->splitSelection($this->gscSelection);

        $hadGa = $website->hasGa();
        $hadGsc = $website->hasGsc();

        $website->fill([
            'ga_property_id' => $gaPropertyId,
            'ga_google_account_id' => $gaPropertyId !== '' ? $gaAccountId : null,
            'gsc_site_url' => $gscSiteUrl,
            'gsc_google_account_id' => $gscSiteUrl !== '' ? $gscAccountId : null,
        ])->save();

        if (! $hadGa && $website->hasGa()) {
            SyncAnalyticsData::dispatch($website->id, 365);
        }
        if (! $hadGsc && $website->hasGsc()) {
            SyncSearchConsoleData::dispatch($website->id, 365);
        }

        // Say exactly what is now connected. The old blanket "Connected!"
        // also showed when Analytics was left on "Not connected", which is
        // how a client came to believe Analytics was linked when only
        // Search Console was (support ticket, 2026-09-17).
        $ga = $website->hasGa();
        $gsc = $website->hasGsc();
        if (! $ga && ! $gsc && ! $hadGa && ! $hadGsc) {
            $this->fetchError = __('Nothing is selected yet. Choose your Analytics property and/or Search Console site above.');

            return;
        }
        $this->saved = match (true) {
            ! $ga && ! $gsc => __('Google Analytics and Search Console are disconnected from this website. This page will refresh.'),
            $ga && $gsc => __('Google Analytics and Search Console are connected. We’re pulling your data now — this page will refresh.'),
            $gsc => __('Search Console is connected. Google Analytics is not — choose its property above if you have one. This page will refresh.'),
            default => __('Google Analytics is connected. Search Console is not — choose your site above if you have one. This page will refresh.'),
        };

        // Reload so the banner clears and the dashboard re-renders with the
        // newly connected source(s) once the backfill lands. A little longer
        // when the message carries a "still missing" note worth reading.
        $this->js('setTimeout(() => window.location.reload(), '.($ga && $gsc ? 1200 : 3500).')');
    }

    public function render()
    {
        return view('livewire.connect-sources-modal');
    }

    private function loadPool(): void
    {
        $user = Auth::user();
        $this->hasGoogle = $user !== null && $user->googleAccounts()->exists();

        if (! $this->hasGoogle) {
            $this->loaded = true;

            return;
        }

        $pool = app(GoogleSourcePool::class)->forUser($user);
        $this->gaOptions = $pool['ga'];
        $this->gscOptions = $pool['gsc'];
        $this->accounts = $pool['accounts'];
        $this->loaded = true;

        if ($pool['ga_error'] || $pool['gsc_error']) {
            $this->fetchError = 'Some Google data couldn’t be loaded. Try reconnecting the affected account.';
        }
    }

    private function loadCurrentSelections(): void
    {
        $website = $this->editableWebsite();
        if (! $website) {
            return;
        }

        if ($website->hasGa()) {
            $this->gaSelection = $website->ga_google_account_id.'|'.$website->ga_property_id;
        }
        if ($website->hasGsc()) {
            $this->gscSelection = $website->gsc_google_account_id.'|'.$website->gsc_site_url;
        }
    }

    private function editableWebsite(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '')) {
            return null;
        }

        $website = Website::query()->find($this->websiteId);
        if (! $website || $website->user_id !== Auth::id()) {
            return null;
        }

        return $website;
    }

    /**
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
