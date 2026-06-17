<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\AnalyticsData;
use App\Models\PageIndexingStatus;
use App\Models\SearchConsoleData;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wipe GSC + GA data (and everything derived from them) so the next sync
 * repopulates from scratch. Use when a schema change or upstream reshape
 * means the existing rows are stale/incomplete.
 *
 * Preserved (untouched):
 *   - websites, users, google_accounts (master data — needed for resync)
 *   - page_audit_reports (Lighthouse, not GSC-derived)
 *   - rank_tracking_{keywords,snapshots} (SerpAPI-driven)
 *   - backlinks (third-party)
 *
 * Wiped:
 *   - search_console_data, analytics_data (primary)
 *   - page_indexing_statuses, ai_insights (GSC-derived)
 *   - last_search_console_sync_at, last_analytics_sync_at on websites
 *   - app cache (top_countries:*, insights:counts:*, country_filter:*, …)
 *
 * After running, fire a fresh pull:
 *   php artisan ebq:resync-gsc --days=30
 *   (analytics re-syncs automatically on its scheduled job, or dispatch
 *    App\Jobs\SyncAnalyticsData manually per website.)
 *
 * Usage:
 *   php artisan ebq:purge-sync-data --dry-run
 *   php artisan ebq:purge-sync-data --website=42
 *   php artisan ebq:purge-sync-data --force        # skip confirmation
 */
class PurgeSyncData extends Command
{
    protected $signature = 'ebq:purge-sync-data
                            {--website= : Limit to a single website ID}
                            {--dry-run : Print counts without deleting}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe GSC + GA data and everything derived from them so the next sync pulls fresh.';

    public function handle(): int
    {
        $websiteOption = $this->option('website');
        $websiteId = null;
        if ($websiteOption !== null && $websiteOption !== '') {
            $websiteId = (int) $websiteOption;
            if (($websiteId === null || $websiteId === '')) {
                $this->error("Invalid --website value: {$websiteOption}");

                return self::FAILURE;
            }
            if (! Website::query()->whereKey($websiteId)->exists()) {
                $this->error("Website #{$websiteId} not found.");

                return self::FAILURE;
            }
        }
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $scope = $websiteId ? "website #{$websiteId}" : 'ALL websites';

        $plan = $this->buildPlan($websiteId);

        $this->line('');
        $this->line("<fg=yellow>Purge plan for {$scope}:</>");
        $this->line('');
        foreach ($plan as $row) {
            $this->line(sprintf('  %-30s %s', $row['label'], number_format($row['count'])));
        }
        $this->line('');

        $totalRows = array_sum(array_column($plan, 'count'));
        if ($totalRows === 0) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm("Delete these rows for {$scope}? This cannot be undone.", false)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($websiteId): void {
            $this->scope(SearchConsoleData::query(), $websiteId)->delete();
            $this->scope(AnalyticsData::query(), $websiteId)->delete();
            $this->scope(PageIndexingStatus::query(), $websiteId)->delete();
            $this->scope(AiInsight::query(), $websiteId)->delete();

            Website::query()
                ->when($websiteId, fn ($q, $id) => $q->whereKey($id))
                ->update([
                    'last_search_console_sync_at' => null,
                    'last_analytics_sync_at' => null,
                ]);
        });

        $this->clearCaches($websiteId);

        $this->info("Purge complete for {$scope}.");
        $this->line('');
        $this->comment('Next step: php artisan ebq:resync-gsc'.($websiteId ? " --website={$websiteId}" : '').' --days=30');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function buildPlan(?string $websiteId): array
    {
        return [
            ['label' => 'search_console_data', 'count' => $this->scope(SearchConsoleData::query(), $websiteId)->count()],
            ['label' => 'analytics_data', 'count' => $this->scope(AnalyticsData::query(), $websiteId)->count()],
            ['label' => 'page_indexing_statuses', 'count' => $this->scope(PageIndexingStatus::query(), $websiteId)->count()],
            ['label' => 'ai_insights', 'count' => $this->scope(AiInsight::query(), $websiteId)->count()],
            ['label' => 'websites (sync timestamps reset)', 'count' => (int) Website::query()
                ->when($websiteId, fn ($q, $id) => $q->whereKey($id))
                ->where(function ($q): void {
                    $q->whereNotNull('last_search_console_sync_at')->orWhereNotNull('last_analytics_sync_at');
                })
                ->count()],
        ];
    }

    private function scope($query, ?string $websiteId)
    {
        return $query->when($websiteId, fn ($q, $id) => $q->where('website_id', $id));
    }

    private function clearCaches(?string $websiteId): void
    {
        if ($websiteId) {
            Cache::forget("top_countries:{$websiteId}");
            Cache::forget("country_filter:{$websiteId}");
            // insights:counts:{id}:{country} has many country variants — flush the few we know about
            // plus the "all" bucket. For the rest, they expire in 10 minutes anyway.
            Cache::forget("insights:counts:{$websiteId}:all");

            return;
        }

        Cache::flush();
    }
}
