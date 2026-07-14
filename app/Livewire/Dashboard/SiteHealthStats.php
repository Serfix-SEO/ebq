<?php

namespace App\Livewire\Dashboard;

use App\Jobs\CrawlWebsitePagesJob;
use App\Models\CrawlRun;
use App\Models\Website;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Compact Site-Health summary on the dashboard (crawl-derived): health score,
 * pages crawled, open issues, orphans, and a blocked-crawler warning. The full
 * per-page detail lives in the Link Structure page + the action queue.
 *
 * Also auto-starts the first crawl for an existing, never-crawled website that
 * has a crawl source — so clients never need a manual "crawl" button (admins
 * trigger recrawls from the clients page).
 */
#[Lazy]
class SiteHealthStats extends Component
{
    public ?string $websiteId = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    /**
     * Re-render when the crawl-in-progress banner reports a state change, so this
     * card hides when the first crawl starts and reappears — populated — once it
     * finishes. Empty body: the attribute alone forces a fresh render().
     */
    #[On('crawl-state-changed')]
    public function onCrawlStateChanged(): void
    {
    }

    public function placeholder(): string
    {
        return '<div class="h-24 animate-pulse rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"></div>';
    }

    public function render(CrawlReportService $report)
    {
        $website = ($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId)
            ? Website::find($this->websiteId)
            : null;

        $summary = $website ? $report->summary($website->id) : null;
        $justStarted = false;

        // Auto-start the first crawl for a never-crawled site that has a source.
        if ($website && $summary && ! $summary['has_crawl'] && ! $website->isFrozen()) {
            $hasSource = $website->hasGsc() || $website->sitemaps()->exists();
            if ($hasSource) {
                // Dispatch at most once per hour per site (ShouldBeUnique also guards).
                if (Cache::add("crawl-autostart:{$website->id}", true, 3600)) {
                    CrawlWebsitePagesJob::dispatch($website->id, CrawlRun::TRIGGER_BACKFILL);
                }
                $justStarted = true;
            }
        }

        // The first crawl is in progress (no finished crawl yet). Rather than hide
        // the card entirely, show PARTIAL numbers (the pages crawled + issues found
        // so far) with a "may change" notice — but only once there's something to
        // show; until then the prominent crawl banner stands in.
        $partial = $website !== null
            && ! $website->hasCompletedCrawl()
            && ($justStarted || $website->isInitialCrawl());
        $hasPartialData = $summary && (int) ($summary['pages_total'] ?? 0) > 0;
        $hide = $partial && ! $hasPartialData;

        return view('livewire.dashboard.site-health-stats', [
            'summary' => $summary,
            'hide' => $hide,
            'partial' => $partial && $hasPartialData,
            'protection' => $website?->crawlSite?->crawl_protection,
            'egressIp' => config('crawler.egress_ip'),
            'crawlerUa' => \App\Services\Crawler\CrawlFetcher::UA,
        ]);
    }
}
