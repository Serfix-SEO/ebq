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

    /** Bound to ?issue= — which finding type sent the user here, so the Page
     *  Health panel can highlight it instead of showing an undifferentiated list. */
    #[Url(as: 'issue')]
    public string $issueType = '';

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
        $issues = [];

        if ($website && $this->pageUrl !== '') {
            $structure = $report->pageLinkStructure($website->id, $this->pageUrl);
            $this->notFound = $structure === null
                ? "That URL hasn't been crawled for this website yet."
                : null;
            if ($structure !== null) {
                $issues = $report->pageFindings($website->id, $this->pageUrl, $structure['page']['id']);
                if ($this->issueType !== '') {
                    // Surface the finding that sent the user here first, instead of
                    // making them hunt for it in a severity-sorted list.
                    usort($issues, fn (array $a, array $b): int => ($b['type'] === $this->issueType) <=> ($a['type'] === $this->issueType));
                }
            }
        }

        // A few suggested pages to make the input discoverable. Cap-filtered
        // via CrawlReportService — the raw WebsitePage query this used to run
        // had no value_rank <= cap filter, so a small-cap user could be shown
        // example URLs outside their window (found 2026-07-06).
        $examples = $website?->crawl_site_id
            ? $report->topInboundPages($website->id)
            : [];

        return view('livewire.link-structure.link-structure-panel', [
            'issues' => $issues,
            'highlightType' => $this->issueType,
            'structure' => $structure,
            'examples' => $examples,
        ]);
    }
}
