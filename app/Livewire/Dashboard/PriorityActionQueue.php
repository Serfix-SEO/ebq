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
        // Hide the queue while the site's first crawl is in progress — most of
        // its actions are crawl-derived, so it would be empty or half-baked. The
        // crawl-in-progress banner stands in until the crawl completes.
        $hide = $this->hasAccess()
            && \App\Models\Website::find($this->websiteId)?->isInitialCrawl() === true;

        $items = ! $hide && $this->hasAccess() ? $this->groupedActions() : [];

        return view('livewire.dashboard.priority-action-queue', [
            'items' => $items,
            'hide' => $hide,
        ]);
    }

    private function hasAccess(): bool
    {
        return ($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupedActions(): array
    {
        $country = $this->country !== '' ? $this->country : null;

        return self::payload($this->websiteId, $country);
    }

    /**
     * Cached grouped-actions payload — shared by groupedActions() and
     * WarmDashboardCaches. Mixes BOTH data versions into the key: ReportCache
     * covers crawl + GSC/GA syncs; RankCache covers the hourly rank checks
     * (since the 2026-06-28 split rank syncs no longer bump ReportCache).
     * Locale is in the key too — titles/descriptions are __() output, so an
     * en-first warm must never freeze Arabic viewers for the 24h TTL.
     */
    public static function payload(string $websiteId, ?string $country = null): array
    {
        $version = \App\Services\ReportCache::version($websiteId);
        $rankVersion = \App\Services\RankCache::version($websiteId);

        return Cache::remember(
            sprintf('action-queue:v3:%s:%d:%d:%s:%s', $websiteId, $version, $rankVersion, $country ?? 'all', app()->getLocale()),
            86400,
            fn (): array => app(ActionQueueService::class)->groupedActions($websiteId, $country),
        );
    }
}
