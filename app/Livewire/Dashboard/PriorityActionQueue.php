<?php

namespace App\Livewire\Dashboard;

use App\Services\ActionQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Priority Action Queue" — the first widget on the actionable Dashboard.
 * Shows grouped, impact-ranked SEO actions; clicking a row navigates to the
 * dedicated, filterable + paginated issue detail page ({@see \App\Livewire\SiteIssues}).
 * Data comes from {@see ActionQueueService} (all read-only, from existing sources).
 */
#[Lazy]
class PriorityActionQueue extends Component
{
    public ?string $websiteId = null;

    public string $country = '';

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="h-3 w-40 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="mt-5 space-y-3">
                <div class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
                <div class="h-12 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
            </div>
        </div>
        HTML;
    }

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->country = '';
    }

    #[On('country-changed')]
    public function onCountryChanged(string $country): void
    {
        $this->country = $country;
    }

    /**
     * Re-render when the crawl-in-progress banner reports a state change, so the
     * queue hides during the first crawl and reappears once it finishes. Empty
     * body: the attribute alone forces a fresh render().
     */
    #[On('crawl-state-changed')]
    public function onCrawlStateChanged(): void
    {
    }

    public function render()
    {
        $website = $this->hasAccess() ? \App\Models\Website::find($this->websiteId) : null;

        // While the site's first crawl hasn't produced final results yet,
        // exclude crawl-derived items (their counts aren't final) — but still
        // show GSC/rank-tracking-derived items (cannibalization, rank drops,
        // quick wins, etc.), which don't depend on crawl state at all. The
        // queue itself is never hidden outright: hiding it entirely used to
        // hide real, ready data (e.g. a tracked keyword's rank drop) for as
        // long as isInitialCrawl()'s queued-window covers a brand-new site,
        // which reads as "nothing to see" when there may be real actions.
        $crawlInitial = $website?->isInitialCrawl() === true;

        $items = $this->hasAccess() ? $this->groupedActions(! $crawlInitial) : [];

        return view('livewire.dashboard.priority-action-queue', [
            'items' => $items,
            'crawlInitial' => $crawlInitial,
            // An empty queue right as a crawl JUST completed reads as "still
            // finalizing" rather than a confident "you're all caught up" — a
            // brand-new site whose crawl finished a moment ago showed the
            // latter with zero issues before anything had a chance to settle,
            // which reads as "no issues found" when it may just not be ready
            // yet. Narrow grace window (60s), never affects an established site.
            'justFinished' => ! $crawlInitial && count($items) === 0 && $this->recentlyFinishedCrawl($website),
        ]);
    }

    private function recentlyFinishedCrawl(?\App\Models\Website $website): bool
    {
        if ($website?->crawl_site_id === null) {
            return false;
        }

        $finishedAt = \App\Models\CrawlRun::where('crawl_site_id', $website->crawl_site_id)
            ->where('status', \App\Models\CrawlRun::STATUS_COMPLETED)
            ->max('finished_at');

        return $finishedAt !== null && \Illuminate\Support\Carbon::parse($finishedAt)->gt(now()->subSeconds(60));
    }

    private function hasAccess(): bool
    {
        return ($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupedActions(bool $includeCrawlIssues): array
    {
        $country = $this->country !== '' ? $this->country : null;

        return self::payload($this->websiteId, $country, $includeCrawlIssues);
    }

    /**
     * Cached grouped-actions payload — shared by groupedActions() and
     * WarmDashboardCaches. Mixes BOTH data versions into the key: ReportCache
     * covers crawl + GSC/GA syncs; RankCache covers the hourly rank checks
     * (since the 2026-06-28 split rank syncs no longer bump ReportCache).
     * Locale is in the key too — titles/descriptions are __() output, so an
     * en-first warm must never freeze Arabic viewers for the 24h TTL.
     * $includeCrawlIssues is in the key too (v4) — a site flipping in/out of
     * its initial-crawl window must not read the other state's cached shape.
     */
    public static function payload(string $websiteId, ?string $country = null, bool $includeCrawlIssues = true): array
    {
        $version = \App\Services\ReportCache::version($websiteId);
        $rankVersion = \App\Services\RankCache::version($websiteId);

        return Cache::remember(
            sprintf('action-queue:v4:%s:%d:%d:%s:%s:%d', $websiteId, $version, $rankVersion, $country ?? 'all', app()->getLocale(), $includeCrawlIssues ? 1 : 0),
            86400,
            fn (): array => app(ActionQueueService::class)->groupedActions($websiteId, $country, $includeCrawlIssues),
        );
    }
}
