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
        $this->loadPool();
        $this->loadCurrentSelections();
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

        $this->saved = 'Connected! We’re pulling your data now — this page will refresh.';

        // Reload so the banner clears and the dashboard re-renders with the
        // newly connected source(s) once the backfill lands.
        $this->js('setTimeout(() => window.location.reload(), 1200)');
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
