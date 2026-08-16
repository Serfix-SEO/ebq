<?php

namespace App\Console\Commands;

use App\Support\ShardTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete crawl rows whose `crawl_site_id` points at a crawl_sites row that no
 * longer exists.
 *
 * `Website::deleted` GCs the shared crawl through ShardCleanup, but batches
 * already in flight keep writing for a few minutes after the site row is gone,
 * so a crawl that was running when its last subscriber left leaves debris
 * behind it (1,353 website_pages rows on prod, 2026-08-16). CrawlPageBatchJob
 * now aborts when its site has vanished; this cleans up what the race already
 * produced, and stays available as a periodic sweep.
 *
 * DESTRUCTIVE — deletes rows. Defaults to --dry-run; the real delete needs
 * --force, and prints the per-table counts either way.
 *
 *   php artisan ebq:prune-orphan-crawl-data
 *   php artisan ebq:prune-orphan-crawl-data --force
 */
class PruneOrphanCrawlData extends Command
{
    protected $signature = 'ebq:prune-orphan-crawl-data
        {--force : Actually delete (without this the command only reports)}';

    protected $description = 'Delete crawl rows left behind by deleted crawl sites';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $live = DB::table('crawl_sites')->pluck('id');
        $rows = [];
        $total = 0;

        // Reverse order = children first (website_finding_states before
        // crawl_findings), matching ShardCleanup::purgeCrawlSiteData.
        foreach (array_reverse(array_keys(ShardTables::CRAWL)) as $table) {
            // website_finding_states has no crawl_site_id of its own; it is
            // cleaned via its finding, which this same pass removes.
            if (! $this->hasCrawlSiteColumn($table)) {
                continue;
            }

            $q = DB::table($table)->whereNotNull('crawl_site_id')->whereNotIn('crawl_site_id', $live);
            $count = (clone $q)->count();
            if ($count === 0) {
                continue;
            }

            $rows[] = [$table, $count, $force ? 'deleted' : 'would delete'];
            $total += $count;

            if ($force) {
                // Chunked so a large sweep never builds one giant transaction.
                do {
                    $deleted = (clone $q)->limit(5000)->delete();
                } while ($deleted > 0);
            }
        }

        // Finding states hang off findings, not crawl sites — sweep the ones
        // whose finding is gone (including any this pass just removed).
        $states = DB::table('website_finding_states')
            ->whereNotIn('finding_id', DB::table('crawl_findings')->select('id'));
        if (($stateCount = (clone $states)->count()) > 0) {
            $rows[] = ['website_finding_states', $stateCount, $force ? 'deleted' : 'would delete'];
            $total += $stateCount;
            if ($force) {
                do {
                    $deleted = (clone $states)->limit(5000)->delete();
                } while ($deleted > 0);
            }
        }

        if ($rows === []) {
            $this->info('No orphaned crawl rows.');

            return self::SUCCESS;
        }

        $this->table(['table', 'rows', 'action'], $rows);

        if (! $force) {
            $this->comment('Dry run — nothing deleted. Re-run with --force to remove these '.$total.' rows.');

            return self::SUCCESS;
        }

        $this->info('Deleted '.$total.' orphaned crawl rows.');

        return self::SUCCESS;
    }

    /**
     * Only tables keyed DIRECTLY on crawl_site_id. website_finding_states is
     * keyed on its finding (`finding_id IN (SELECT … crawl_site_id = :cs)`), so
     * a str_contains check matches it by accident and produces an "Unknown
     * column crawl_site_id" query — anchor to the start of the clause.
     */
    private function hasCrawlSiteColumn(string $table): bool
    {
        return str_starts_with(ShardTables::CRAWL[$table] ?? '', 'crawl_site_id =');
    }
}
