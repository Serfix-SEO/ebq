<?php

namespace App\Livewire\Websites;

use App\Models\Website;
use App\Support\GoogleSourcePool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class WebsitesList extends Component
{
    public string $domain = '';

    /** "accountId|value" picker values, mirroring onboarding. */
    public string $gaSelection = '';
    public string $gscSelection = '';
    public bool $showForm = false;
    public string $fetchError = '';

    /** @var array<int, array{id: string, name: string, account_id: int, account_label: string}> */
    public array $gaOptions = [];

    /** @var array<int, array{siteUrl: string, account_id: int, account_label: string}> */
    public array $gscOptions = [];

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;
        $this->reset(['domain', 'gaSelection', 'gscSelection', 'fetchError']);
        $this->resetValidation();

        if ($this->showForm) {
            $this->fetchGoogleData();
        }
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

    public function addWebsite(): void
    {
        $this->validate([
            'domain' => ['required', 'string', 'max:255'],
            'gaSelection' => ['nullable', 'string', 'max:512'],
            'gscSelection' => ['nullable', 'string', 'max:512'],
        ]);

        [$gaAccountId, $gaPropertyId] = $this->splitSelection($this->gaSelection);
        [$gscAccountId, $gscSiteUrl] = $this->splitSelection($this->gscSelection);

        // GA and GSC are both optional — a domain-only website is allowed.
        // The user can connect data sources later in Settings.
        $website = Website::updateOrCreate(
            ['user_id' => Auth::id(), 'domain' => $this->domain],
            [
                'ga_property_id' => $gaPropertyId,
                'ga_google_account_id' => $gaPropertyId !== '' ? $gaAccountId : null,
                'gsc_site_url' => $gscSiteUrl,
                'gsc_google_account_id' => $gscSiteUrl !== '' ? $gscAccountId : null,
            ]
        );

        if ($website->wasRecentlyCreated) {
            Artisan::queue('ebq:import-historical', [
                '--days' => 365,
                '--website' => (string) $website->id,
            ]);

            // Subscribe to the shared crawl_site: charge the cap and crawl only if
            // the domain isn't already covered (else instantly reuse the shared data).
            app(\App\Services\Crawler\CrawlSiteBootstrapper::class)->subscribeWebsite($website);

            // Same landing as onboarding (ConnectGoogle::finishOnboarding):
            // pin the NEW site as current and drop the user on the overview
            // hub's Explorer tab. That page kicks the initial Site Explorer
            // generation on first view (freshness-gated — a domain another
            // account already analyzed serves the shared snapshot free), the
            // crawl + GSC/GA imports queued above run in the background, and
            // the Site Health / Statistics tabs show their real
            // processing / needs-action pills for whatever isn't connected.
            session(['current_website_id' => $website->id]);
            $this->redirectRoute('website-overview', ['tab' => 'explorer']);

            return;
        }

        $this->reset(['domain', 'gaSelection', 'gscSelection', 'showForm', 'fetchError']);
    }

    public function removeWebsite(string $id): void
    {
        $website = Website::find($id);
        if (! $website || ! Gate::forUser(Auth::user())->allows('delete', $website)) {
            return;
        }

        $website->delete();

        if (session('current_website_id') === $id) {
            $next = Auth::user()->accessibleWebsitesQuery()->first();
            session(['current_website_id' => $next?->id ?? 0]);
        }
    }

    public function render()
    {
        $user = Auth::user();
        $ownedWebsites = $user->websites()->orderBy('domain')->get();
        $sharedWebsites = $user->sharedWebsites()->with('user')->orderBy('domain')->get();

        return view('livewire.websites.websites-list', compact('ownedWebsites', 'sharedWebsites'));
    }

    private function fetchGoogleData(): void
    {
        $user = Auth::user();
        if ($user === null || ! $user->googleAccounts()->exists()) {
            // No Google account is fine — they can still add a domain-only
            // website now and connect Analytics/Search Console later.
            $this->fetchError = 'No Google account connected. You can still add a website by domain and connect Analytics or Search Console later in Settings.';

            return;
        }

        $pool = app(GoogleSourcePool::class)->forUser($user);
        $this->gaOptions = $pool['ga'];
        $this->gscOptions = $pool['gsc'];

        if ($pool['ga_error'] || $pool['gsc_error']) {
            $this->fetchError = 'Some Google data could not be loaded. Try reconnecting the affected account in Settings.';
        }
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

    private function extractDomain(string $siteUrl): string
    {
        $url = str_replace('sc-domain:', '', $siteUrl);
        $parsed = parse_url($url, PHP_URL_HOST);

        return $parsed ?: preg_replace('#^https?://#', '', rtrim($url, '/'));
    }
}
