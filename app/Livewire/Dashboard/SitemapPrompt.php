<?php

namespace App\Livewire\Dashboard;

use App\Jobs\CrawlWebsitePagesJob;
use App\Models\CrawlRun;
use App\Models\Website;
use App\Models\WebsiteSitemap;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Prominent dashboard nudge shown only when the current website has NO Search
 * Console connected AND no sitemaps yet — i.e. a sourceless site whose dashboard
 * would otherwise be empty. Lets the user add a sitemap inline; adding it kicks
 * off a crawl (seeded from the sitemap) so Site Health / pages start populating.
 */
class SitemapPrompt extends Component
{
    public ?string $websiteId = null;

    public string $newSitemapUrl = '';

    public ?string $status = null;

    public bool $added = false;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function onWebsiteChanged(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset(['newSitemapUrl', 'status', 'added']);
    }

    private function website(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '') || ! Auth::user()?->canViewWebsiteId($this->websiteId)) {
            return null;
        }

        return Website::find($this->websiteId);
    }

    /** Needs the prompt: no GSC source AND no sitemaps on file. */
    private function needsPrompt(Website $website): bool
    {
        return ! $website->hasGsc() && ! $website->sitemaps()->exists();
    }

    public function addSitemap(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }

        $validated = $this->validate([
            'newSitemapUrl' => ['required', 'url', 'max:700'],
        ]);

        WebsiteSitemap::updateOrCreate(
            ['website_id' => $this->websiteId, 'path' => trim($validated['newSitemapUrl'])],
            ['source' => WebsiteSitemap::SOURCE_MANUAL],
        );

        // Seed a crawl from the new sitemap so the dashboard fills in for a
        // site with no Google data. (NOTE: needs a running queue worker.)
        CrawlWebsitePagesJob::dispatch($this->websiteId, CrawlRun::TRIGGER_ON_CREATE);

        $this->reset('newSitemapUrl');
        $this->added = true;
        $this->status = __('Sitemap added — we’re crawling your pages now. Data will appear shortly.');
    }

    public function render()
    {
        $website = $this->website();
        $show = $website !== null && ($this->added || $this->needsPrompt($website));

        return view('livewire.dashboard.sitemap-prompt', [
            'show' => $show,
        ]);
    }
}
