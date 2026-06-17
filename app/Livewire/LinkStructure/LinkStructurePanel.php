<?php

namespace App\Livewire\LinkStructure;

use App\Models\Website;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Per-page internal link structure. The user types (or deep-links to) a page
 * URL and sees what links to it, what it links to, broken targets, and
 * AI-suggested links — all from the crawl graph.
 */
class LinkStructurePanel extends Component
{
    public ?string $websiteId = null;

    /** Bound to the ?url= query param so action-queue fix links deep-link here. */
    #[Url(as: 'url')]
    public string $pageUrl = '';

    public ?string $notFound = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->pageUrl = trim($this->pageUrl);
    }

    #[On('website-changed')]
    public function onWebsiteChanged(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset(['pageUrl', 'notFound']);
    }

    public function analyze(): void
    {
        $this->notFound = null;
        $this->pageUrl = trim($this->pageUrl);
    }

    private function currentWebsite(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '') || ! Auth::user()?->canViewWebsiteId($this->websiteId)) {
            return null;
        }

        return Website::find($this->websiteId);
    }

    public function render(CrawlReportService $report)
    {
        $website = $this->currentWebsite();
        $structure = null;

        if ($website && $this->pageUrl !== '') {
            $structure = $report->pageLinkStructure($website->id, $this->pageUrl);
            $this->notFound = $structure === null
                ? "That URL hasn't been crawled for this website yet."
                : null;
        }

        // A few suggested pages to make the input discoverable.
        $examples = $website?->crawl_site_id
            ? \App\Models\WebsitePage::where('crawl_site_id', $website->crawl_site_id)
                ->whereNotNull('last_crawled_at')->whereNull('removed_at')
                ->orderByDesc('inbound_link_count')->limit(8)->pluck('url')->all()
            : [];

        return view('livewire.link-structure.link-structure-panel', [
            'structure' => $structure,
            'examples' => $examples,
        ]);
    }
}
