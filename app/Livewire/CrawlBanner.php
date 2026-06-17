<?php

namespace App\Livewire;

use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Prominent "we're crawling your site" banner for the current website. Drop it
 * at the top of any page whose data the first crawl builds (dashboard, pages,
 * link structure, keywords, competitor discovery, rank tracking). The view polls
 * so it appears when a crawl starts and disappears when it finishes.
 *
 * On the dashboard it also drives the crawl-derived widgets: when the running
 * state flips it emits `crawl-state-changed`, which Site Health / the action
 * queue listen for to hide during the first crawl and reappear once it's done.
 * On other pages that event simply has no listeners.
 */
class CrawlBanner extends Component
{
    public ?string $websiteId = null;

    /** Id of the running crawl we last observed (0 = none), to detect changes. */
    public ?string $seenCrawlId = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function onWebsiteChanged(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->seenCrawlId = 0;
    }

    private function website(): ?Website
    {
        if (($this->websiteId === null || $this->websiteId === '') || ! Auth::user()?->canViewWebsiteId($this->websiteId)) {
            return null;
        }

        return Website::find($this->websiteId);
    }

    public function render()
    {
        $website = $this->website();
        $crawl = $website?->runningCrawl();

        // When the running crawl appears or clears, notify any crawl-derived
        // widgets on the page to re-evaluate (hide on start, reappear on finish).
        $currentId = $crawl?->id ?? 0;
        if ($currentId !== $this->seenCrawlId) {
            $this->seenCrawlId = $currentId;
            $this->dispatch('crawl-state-changed');
        }

        $cap = $website?->crawlPageCap() ?? 0;
        $crawled = 0;
        $total = 0;
        if ($crawl) {
            // "Total to crawl" = pages discovered for this site so far (the live
            // inventory); "crawled" = pages fetched THIS run. Both bounded by the user's
            // cap. We count the inventory rather than the run's pages_seen, because the
            // fairness per-pass cap makes pages_seen grow ~1k at a time — so pages_seen
            // would read a misleading "1,000 / 1,000" after pass 1 instead of the true
            // discovered total.
            $counts = \App\Models\WebsitePage::where('crawl_site_id', $crawl->crawl_site_id)
                ->whereNull('removed_at')
                ->selectRaw('COUNT(*) as total, COUNT(CASE WHEN last_crawled_at >= ? THEN 1 END) as crawled', [optional($crawl->started_at)->toDateTimeString()])
                ->first();
            $total = $cap > 0 ? min((int) $counts->total, $cap) : (int) $counts->total;
            $crawled = $cap > 0 ? min((int) $counts->crawled, $cap) : (int) $counts->crawled;
        }

        return view('livewire.crawl-banner', [
            'crawl' => $crawl,
            'cap' => $cap,
            'crawled' => $crawled,
            'total' => $total,
            // Plan allowance left = cap minus what this run has crawled into the window.
            'remainingCap' => $cap > 0 ? max(0, $cap - $crawled) : null,
            // Poll fast (10s) while a crawl is in flight; slow (30s) when idle. We still
            // poll when idle so the banner appears when a crawl starts, but the old
            // unconditional 10s poll hammered every page for every user even when idle.
            'pollInterval' => $crawl ? 10 : 30,
        ]);
    }
}
