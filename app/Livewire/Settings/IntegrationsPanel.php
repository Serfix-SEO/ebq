<?php

namespace App\Livewire\Settings;

use App\Jobs\SyncAnalyticsData;
use App\Jobs\SyncSearchConsoleData;
use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class IntegrationsPanel extends Component
{
    public ?string $websiteId = null;

    /** "accountId|value" picker values, mirroring onboarding. */
    public string $gaSelection = '';
    public string $gscSelection = '';

    public string $saved = '';

    /** @var array<int, array{id: string, name: string, account_id: int, account_label: string}> */
    public array $gaOptions = [];

    /** @var array<int, array{siteUrl: string, account_id: int, account_label: string}> */
    public array $gscOptions = [];

    /** @var array<int, array{id: int, label: string}> */
    public array $accounts = [];

    public string $fetchError = '';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->loadPool();
        $this->loadCurrentSelections();
    }

    /**
     * Persist the chosen GA/GSC sources onto the current website and kick
     * off a backfill for any source that just became connected.
     */
    public function saveSources(): void
    {
        $website = $this->editableWebsite();
        if (! $website) {
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

        $this->saved = 'Data sources updated.';
    }

    public function render()
    {
        $googleAccount = Auth::user()?->googleAccounts()->latest()->first();
        $website = $this->editableWebsite();

        return view('livewire.settings.integrations-panel', [
            'googleAccount' => $googleAccount,
            'website' => $website,
        ]);
    }

    private function loadPool(): void
    {
        $user = Auth::user();
        if ($user === null || ! $user->googleAccounts()->exists()) {
            return;
        }

        $pool = app(GoogleSourcePool::class)->forUser($user);
        $this->gaOptions = $pool['ga'];
        $this->gscOptions = $pool['gsc'];
        $this->accounts = $pool['accounts'];

        if ($pool['ga_error'] || $pool['gsc_error']) {
            $this->fetchError = 'Some Google data could not be loaded. Try reconnecting the affected account.';
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
        // Only the owner reconfigures data sources.
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
