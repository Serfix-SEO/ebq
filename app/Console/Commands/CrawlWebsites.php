<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSitemapDeltaJob;
use App\Jobs\CrawlWebsitePagesJob;
use App\Models\CrawlRun;
use App\Models\CrawlSite;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CrawlWebsites extends Command
{
    protected $signature = 'ebq:crawl-websites
        {--website= : Crawl only this website id}
        {--force : Crawl every eligible website regardless of recrawl schedule}
        {--backfill : Only websites never crawled yet (one-off after deploy)}
        {--sitemap-deltas : Daily mode — only check sitemaps for new URLs and crawl those}
        {--reanalyze : Clear conditional-GET validators first so every page is re-fetched + re-parsed (applies analyzer/canonical changes to existing pages)}';

    protected $description = 'Dispatch site crawls: weekly full recrawl, one-off backfill, or daily sitemap-delta checks';

    public function handle(): int
    {
        $single = $this->option('website');
        $force = (bool) $this->option('force');
        $backfill = (bool) $this->option('backfill');
        $sitemapDeltas = (bool) $this->option('sitemap-deltas');

        // Iterate shared crawl_sites (one crawl per domain), not websites.
        $query = CrawlSite::query()->whereHas('websites', fn (Builder $w) => $this->hasSource($w));

        if ($single !== null) {
            // $single is a ULID string, not a numeric id — (int) cast used to truncate
            // it (every current-era ULID starts "01", so it always collapsed to 1,
            // matching whichever row happened to satisfy that under MySQL's numeric
            // string coercion — wrong-site risk). Fixed 2026-06-23.
            $csid = Website::where('id', (string) $single)->value('crawl_site_id');
            $query->whereKey($csid ?: 0);
        }

        if ($sitemapDeltas) {
            return $this->dispatchSitemapDeltas($query);
        }

        if ($backfill) {
            $query->whereDoesntHave('crawlRuns'); // never crawled
        } elseif (! $force && $single === null) {
            $query->whereDoesntHave('crawlRuns', function (Builder $q): void {
                $q->where('started_at', '>=', now()->subDays(7)); // weekly cadence
            });
        }

        $reanalyze = (bool) $this->option('reanalyze');
        $trigger = $backfill ? CrawlRun::TRIGGER_BACKFILL : CrawlRun::TRIGGER_SCHEDULED;
        $count = 0;
        $query->chunkById(100, function ($sites) use (&$count, $trigger, $force, $reanalyze): void {
            foreach ($sites as $site) {
                if ($reanalyze) {
                    WebsitePage::where('crawl_site_id', $site->id)
                        ->update(['content_hash' => null, 'etag' => null, 'last_modified_header' => null]);
                }
                $websiteId = $this->triggerWebsiteId($site);
                if ($websiteId !== null) {
                    CrawlWebsitePagesJob::dispatch($websiteId, $trigger, $force);
                    $count++;
                }
            }
        });

        $this->info("Dispatched {$count} crawl job(s).");

        return self::SUCCESS;
    }

    private function dispatchSitemapDeltas(Builder $query): int
    {
        $query->whereHas('websites', fn (Builder $w) => $w->whereHas('sitemaps'));
        $count = 0;
        $query->chunkById(100, function ($sites) use (&$count): void {
            foreach ($sites as $site) {
                $websiteId = $this->triggerWebsiteId($site);
                if ($websiteId !== null) {
                    CrawlSitemapDeltaJob::dispatch($websiteId);
                    $count++;
                }
            }
        });

        $this->info("Dispatched {$count} sitemap-delta check(s).");

        return self::SUCCESS;
    }

    /** A subscriber website constraint: has GSC or sitemaps. */
    private function hasSource(Builder $w): Builder
    {
        return $w->where(function (Builder $q): void {
            $q->where(function (Builder $g): void {
                $g->whereNotNull('gsc_site_url')->where('gsc_site_url', '!=', '');
            })->orWhereHas('sitemaps');
        });
    }

    /** Pick a subscriber website to carry the crawl dispatch (prefer a sourced one). */
    private function triggerWebsiteId(CrawlSite $site): ?string
    {
        $id = $site->websites()->where(fn (Builder $q) => $this->hasSource($q))->value('id')
            ?? $site->websites()->value('id');

        return $id ? $id : null;
    }
}
