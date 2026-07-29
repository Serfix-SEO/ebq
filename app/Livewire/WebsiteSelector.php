<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class WebsiteSelector extends Component
{
    /**
     * Session flag: the pinned website was chosen by the USER from this
     * dropdown, not auto-pinned for them. Middleware that "corrects" an
     * accidental pin must leave a deliberate one alone.
     */
    public const EXPLICIT_PIN_KEY = 'current_website_pinned_by_user';

    public ?string $websiteId = null;

    /** @var array<int, array{id: int, domain: string}> */
    public array $websites = [];

    public function mount(): void
    {
        $this->websites = Auth::user()
            ->accessibleWebsitesQuery()
            ->select('id', 'domain')
            ->orderBy('domain')
            ->get()
            ->map(fn ($w) => ['id' => $w->id, 'domain' => $w->domain])
            ->toArray();

        $sessionId = session('current_website_id');
        $ids = array_column($this->websites, 'id');

        $this->websiteId = in_array($sessionId, $ids, true) ? $sessionId : ($ids[0] ?? null);

        if ($this->websiteId) {
            session(['current_website_id' => $this->websiteId]);
        }
    }

    public function updatedWebsiteId(string $value): void
    {
        $ids = array_column($this->websites, 'id');
        if (! in_array($value, $ids, true)) {
            $value = (string) ($ids[0] ?? '');
            $this->websiteId = $value;
        }

        // Mark the pin as DELIBERATE. EnsureContentAccess silently re-pins a
        // site that has no content plan (it corrects the alphabetical auto-pin
        // EnsureFeatureAccess makes when the session is empty) — without this
        // flag it cannot tell that accident apart from a user who just picked
        // this site from the dropdown, so the selection reverted on refresh
        // (prod 2026-07-29).
        session([
            'current_website_id' => $value,
            self::EXPLICIT_PIN_KEY => true,
        ]);
        $this->dispatch('website-changed', websiteId: $value);
    }

    public function render()
    {
        return view('livewire.website-selector');
    }
}
