<?php

namespace App\Jobs;

use App\Livewire\Dashboard\CountryFilter;
use App\Livewire\Dashboard\InsightCards;
use App\Livewire\Dashboard\KpiCards;
use App\Livewire\Dashboard\PriorityActionQueue;
use App\Livewire\Dashboard\QuickWinsCard;
use App\Livewire\Dashboard\SeasonalityCard;
use App\Livewire\Dashboard\TopCountriesCard;
use App\Livewire\Dashboard\TrafficChart;
use App\Models\Website;
use App\Services\Crawler\CrawlReportService;
use App\Support\Queues;
use App\Support\ShardContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pre-computes the /dashboard + /statistics card payloads right after a sync
 * bumps the ReportCache version, so the FIRST visitor never pays the cold
 * aggregate cost (measured 2026-07-06 on the largest GSC account: country
 * group-by 98s, KPI 26s, traffic chart 17s — ~2 min of spinners).
 *
 * Zero drift by construction: each card's cached payload lives in a
 * `payload()` static on the component itself (identical key + closure); this
 * job just calls those same statics. Runs on the SYNC queue (worker box,
 * generous timeouts) — the cache store is the shared Redis, so keys warmed
 * here are hot for the web box. Per-user caches (traffic chart's timezone
 * formatting, quick-wins' plan limit) are warmed for the website OWNER;
 * teammates in other timezones pay their own (rare) cold path.
 *
 * ShouldBeUnique: syncs for the same website within the window (GSC + GA
 * back-to-back) collapse into one warm run.
 */
class WarmDashboardCaches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * 2, not 1: the whole job is idempotent Cache::remember writes, and a
     * worker restart / deploy --force-recreate mid-warm kills attempt #1 —
     * with tries=1 that surfaced as MaxAttemptsExceeded and left the site's
     * caches half-cold until the next sync (2026-07-07 digest alert).
     */
    public int $tries = 2;

    public int $timeout = 900;

    /** Covers the longest observed warm (~8min on the largest account). */
    public int $uniqueFor = 1800;

    public function __construct(public string $websiteId)
    {
        $this->onQueue(Queues::SYNC);
    }

    public function uniqueId(): string
    {
        return 'warm-dashboard:'.$this->websiteId;
    }

    public function handle(): void
    {
        // The site's GSC sync is mid-flight: every 7-day window it upserts
        // bumps the cache version, so anything we warm now gets orphaned
        // minutes later — wasted minutes of heavy aggregates per round
        // (observed 2026-07-07). Skip; the sync dispatches its own warm on
        // completion, which is the one that sticks for the next 24h.
        if (\Illuminate\Support\Facades\Cache::get('gsc-sync-inflight:'.$this->websiteId)) {
            Log::info("WarmDashboardCaches: skipped {$this->websiteId} — GSC sync in flight (end-of-sync warm will cover).");

            return;
        }

        app(ShardContext::class)->forWebsite($this->websiteId);
        $website = Website::find($this->websiteId);
        if (! $website || $website->isFrozen()) {
            return;
        }
        $owner = $website->owner;

        // Each warm is independent — one card's failure must not cold the rest.
        $warm = function (string $label, callable $fn): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning("WarmDashboardCaches: {$label} failed for {$this->websiteId}: {$e->getMessage()}");
            }
        };

        // /dashboard
        $warm('action-queue', fn () => PriorityActionQueue::payload($this->websiteId));
        $warm('country-filter', fn () => CountryFilter::payload($this->websiteId));

        // /statistics
        $warm('kpis', fn () => KpiCards::payload($this->websiteId));
        $warm('insights', fn () => InsightCards::payload($this->websiteId));
        $warm('quick-wins', fn () => QuickWinsCard::payload($this->websiteId, $owner));
        $warm('seasonality', fn () => SeasonalityCard::payload($this->websiteId));
        $warm('top-countries', fn () => TopCountriesCard::payload($this->websiteId));
        if ($owner) {
            $warm('traffic-chart', fn () => TrafficChart::payload($this->websiteId, $owner));
        }

        // Crawl-audit aggregate (Priority Action Queue drill-down) — cached via
        // CrawlReportService::remember(), cold after every crawl. typeBreakdown()
        // is deliberately NOT warmed: it's per-category (user picks one) and far
        // cheaper than the sitewide actionGroups scan.
        $warm('crawl-action-groups', fn () => app(CrawlReportService::class)->actionGroups($this->websiteId));

        // Per-user 28d click impact (mapFinding badge on every issue drill-down
        // row). ~9s cold on a 1.4M-row GSC site — warming it here means even the
        // FIRST type drill after a sync/crawl responds instantly (2026-07-10).
        $warm('user-clicks', fn () => app(CrawlReportService::class)->warmUserClicks($this->websiteId));
    }
}
