<?php

namespace App\Livewire\Websites;

use App\Models\Website;
use App\Support\GoogleSourcePool;
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
        // The user can connect data sources later in Settings. The attach
        // service runs the full recipe: create + historical import + shared
        // crawl subscription + current-website pin.
        $result = app(\App\Services\WebsiteAttachService::class)->attach(Auth::user(), $this->domain, [
            'ga_property_id' => $gaPropertyId,
            'ga_google_account_id' => $gaPropertyId !== '' ? $gaAccountId : null,
            'gsc_site_url' => $gscSiteUrl,
            'gsc_google_account_id' => $gscSiteUrl !== '' ? $gscAccountId : null,
        ]);

        if ($result['blocked'] === 'plan_limit') {
            // Same treatment as onboarding: adding past the plan limit goes
            // through billing (this component previously had no gate at all).
            $this->redirectRoute('billing.show', navigate: false);

            return;
        }

        if ($result['blocked'] === 'invalid_domain') {
            $this->addError('domain', __('Please enter a valid domain.'));

            return;
        }

        if ($result['created']) {
            // Same landing as onboarding (ConnectGoogle::finishOnboarding):
            // the NEW site is pinned as current; drop the user on the overview
            // hub's Explorer tab. That page kicks the initial Site Explorer
            // generation on first view (freshness-gated — a domain another
            // account already analyzed serves the shared snapshot free), the
            // crawl + GSC/GA imports queued by the attach run in the
            // background, and the Site Health / Statistics tabs show their
            // real processing / needs-action pills for whatever isn't
            // connected.
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

        return view('livewire.websites.websites-list', compact('ownedWebsites', 'sharedWebsites'))
            ->with('siteScores', $this->siteScores($ownedWebsites->merge($sharedWebsites)));
    }

    /**
     * Trust/Citation score chip data per listed domain, read from the shared
     * report snapshots (payload already carries `scores`; older cached
     * payloads are augmented in-memory — pure math, no provider call).
     *
     * @return array<string, array{trust: ?int, citation: ?int}>
     */
    private function siteScores($websites): array
    {
        $domains = $websites->pluck('domain')->filter()->map(fn ($d) => strtolower($d))->unique()->values();
        if ($domains->isEmpty()) {
            return [];
        }

        $calc = new \App\Services\Reports\AuthorityScoreCalculator();
        $out = [];
        \App\Models\WebsiteReportSnapshot::query()
            ->whereIn('normalized_domain', $domains)
            ->where('status', 'ready')
            ->get(['normalized_domain', 'payload'])
            ->each(function ($s) use ($calc, &$out) {
                $scores = $calc->augment($s->payload ?? [])['scores'] ?? null;
                if (is_array($scores) && ($scores['trust'] !== null || $scores['citation'] !== null)) {
                    $out[$s->normalized_domain] = ['trust' => $scores['trust'], 'citation' => $scores['citation']];
                }
            });

        return $out;
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
