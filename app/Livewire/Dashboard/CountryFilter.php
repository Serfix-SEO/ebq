<?php

namespace App\Livewire\Dashboard;

use App\Models\SearchConsoleData;
use App\Support\Countries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reusable country dropdown. Dispatches `country-changed` with the new code
 * (uppercase alpha-3 like "USA", empty string for all-countries) whenever the
 * user picks one. Every Livewire component that listens to `country-changed`
 * rehydrates against the new filter.
 *
 * Countries available = distinct countries in search_console_data for the
 * currently-selected website — so the dropdown never shows markets where this
 * site has no presence. Cached for 10 minutes to keep the select snappy.
 */
class CountryFilter extends Component
{
    public ?string $websiteId = null;

    #[Url(as: 'country', history: true)]
    public string $country = '';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->country = '';
        $this->dispatch('country-changed', country: '');
    }

    public function updatedCountry(string $value): void
    {
        $this->dispatch('country-changed', country: $value);
    }

    public function render()
    {
        $options = [];
        if (($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $options = Cache::remember(
                "country_filter:{$this->websiteId}",
                600,
                fn () => SearchConsoleData::query()
                    ->where('website_id', $this->websiteId)
                    ->where('country', '!=', '')
                    ->selectRaw('country, SUM(clicks) as clicks')
                    ->groupBy('country')
                    ->orderByDesc('clicks')
                    ->limit(50)
                    ->pluck('country')
                    ->map(fn ($code) => [
                        'code' => (string) $code,
                        'name' => Countries::name((string) $code),
                        'flag' => Countries::flag((string) $code),
                    ])
                    ->all()
            );
        }

        return view('livewire.dashboard.country-filter', [
            'options' => $options,
        ]);
    }
}
