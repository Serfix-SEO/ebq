<?php

namespace App\Livewire\Sitemaps;

use App\Jobs\SyncSitemaps;
use App\Models\Website;
use App\Models\WebsiteSitemap;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class SitemapsManager extends Component
{
    public ?string $websiteId = null;
    public string $newSitemapUrl = '';
    public ?string $status = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    /**
     * Switch the active website when the global selector changes.
     */
    #[On('website-changed')]
    public function onWebsiteChanged(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset(['newSitemapUrl', 'status']);
    }

    /**
     * Best-effort first-load pull from GSC. Runs via wire:init so the page
     * renders first, then populates if the property has no synced rows yet.
     */
    public function autoSync(): void
    {
        $website = $this->currentWebsite();
        if (! $website || ! $website->hasGsc()) {
            return;
        }

        $alreadySynced = $website->sitemaps()
            ->where('source', WebsiteSitemap::SOURCE_GSC)
            ->exists();

        if (! $alreadySynced) {
            SyncSitemaps::dispatchSync($this->websiteId);
        }
    }

    /**
     * Manual "Sync from GSC" button — always re-pulls the list.
     */
    public function syncFromGsc(): void
    {
        $website = $this->currentWebsite();
        if (! $website) {
            return;
        }

        if (! $website->hasGsc()) {
            $this->status = 'No Google Search Console source is connected for this website.';
            return;
        }

        SyncSitemaps::dispatchSync($this->websiteId);
        $this->status = 'Synced sitemaps from Google Search Console.';
    }

    public function addSitemap(): void
    {
        if (! $this->currentWebsite()) {
            return;
        }

        $validated = $this->validate([
            'newSitemapUrl' => ['required', 'url', 'max:700'],
        ]);

        WebsiteSitemap::updateOrCreate(
            ['website_id' => $this->websiteId, 'path' => trim($validated['newSitemapUrl'])],
            ['source' => WebsiteSitemap::SOURCE_MANUAL],
        );

        $this->reset('newSitemapUrl');
        $this->status = 'Sitemap added.';
    }

    public function removeSitemap(string $id): void
    {
        if (! $this->currentWebsite()) {
            return;
        }

        WebsiteSitemap::where('website_id', $this->websiteId)
            ->whereKey($id)
            ->delete();
    }

    private function currentWebsite(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '') || ! Auth::user()?->canViewWebsiteId($this->websiteId)) {
            return null;
        }

        return Website::find($this->websiteId);
    }

    public function render()
    {
        $website = $this->currentWebsite();

        $sitemaps = $website
            ? $website->sitemaps()->orderByDesc('source')->orderBy('path')->get()
            : collect();

        return view('livewire.sitemaps.sitemaps-manager', [
            'sitemaps' => $sitemaps,
            'hasGsc' => (bool) $website?->hasGsc(),
        ]);
    }
}
