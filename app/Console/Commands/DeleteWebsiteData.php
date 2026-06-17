<?php

namespace App\Console\Commands;

use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeleteWebsiteData extends Command
{
    protected $signature = 'ebq:delete-website-data
                            {website? : Website ID to delete (omit when using --all)}
                            {--all : Delete every website on the platform}
                            {--dry-run : Print what would be deleted without deleting}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Delete a website and all data related to it. Pass --all to wipe every website.';

    public function handle(): int
    {
        $websiteArg = $this->argument('website');
        $all = (bool) $this->option('all');

        if ($all && $websiteArg !== null) {
            $this->error('Pass either a website ID or --all, not both.');

            return self::FAILURE;
        }
        if (! $all && $websiteArg === null) {
            $this->error('Website ID is required (or pass --all).');

            return self::FAILURE;
        }

        return $all ? $this->handleAll() : $this->handleOne((int) $websiteArg);
    }

    private function handleOne(string $websiteId): int
    {
        if (($websiteId === null || $websiteId === '')) {
            $this->error('Website ID must be a positive integer.');

            return self::FAILURE;
        }

        $website = Website::query()->find($websiteId);
        if (! $website) {
            $this->error("Website #{$websiteId} not found.");

            return self::FAILURE;
        }

        $plan = $this->buildPlan($websiteId);
        $this->line('');
        $this->line("<fg=yellow>Delete plan for website #{$website->id} ({$website->domain}):</>");
        $this->line('');
        foreach ($plan as $row) {
            $this->line(sprintf('  %-28s %s', $row['label'], number_format($row['count'])));
        }
        $this->line('');

        $total = array_sum(array_column($plan, 'count'));
        if ($total === 0) {
            $this->comment('No related rows found, but website record will still be deleted.');
        }

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run complete — nothing deleted.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')) {
            $ok = $this->confirm(
                "Delete website #{$website->id} ({$website->domain}) and all related data? This cannot be undone.",
                false
            );
            if (! $ok) {
                $this->comment('Aborted.');

                return self::SUCCESS;
            }
        }

        $this->deleteWebsite($website);
        $this->info("Website #{$websiteId} and related data deleted.");

        return self::SUCCESS;
    }

    private function handleAll(): int
    {
        $total = Website::query()->count();
        $this->line('');
        $this->line("<fg=red>You are about to wipe EVERY website on this platform ({$total} total).</>");
        $this->line('All per-website data cascades: audits, keywords, GSC/GA snapshots, AI history, writer projects, brand voice, redirects, research pages — everything.');
        $this->line('');

        if ($total === 0) {
            $this->comment('No websites to delete.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            // One aggregate plan across the whole platform.
            $plan = $this->buildAggregatePlan();
            foreach ($plan as $row) {
                $this->line(sprintf('  %-28s %s', $row['label'], number_format($row['count'])));
            }
            $this->line('');
            $this->comment('Dry run complete — nothing deleted.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')) {
            // Typed confirmation — `confirm()`'s default-No is too easy to fat-finger past for a platform wipe.
            $phrase = (string) $this->ask('Type "delete all websites" to proceed', '');
            if (trim($phrase) !== 'delete all websites') {
                $this->comment('Aborted.');

                return self::SUCCESS;
            }
        }

        $deleted = 0;
        $failed = 0;
        // Per-website transactions: one bad row shouldn't roll back hours of successful deletes.
        Website::query()->orderBy('id')->chunkById(50, function ($chunk) use (&$deleted, &$failed): void {
            foreach ($chunk as $website) {
                try {
                    $this->deleteWebsite($website);
                    $deleted++;
                    $this->line(sprintf('  deleted #%d (%s)', $website->id, $website->domain));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error(sprintf('  failed #%d (%s): %s', $website->id, $website->domain, $e->getMessage()));
                }
            }
        });

        $this->line('');
        $this->info("Done. Deleted: {$deleted}. Failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function deleteWebsite(Website $website): void
    {
        $id = $website->id;
        DB::transaction(function () use ($website): void {
            // This table uses nullOnDelete, so remove website-scoped rows explicitly.
            DB::table('client_activities')->where('website_id', $website->id)->delete();

            // Deleting website cascades to all website-bound tables via FKs.
            $website->delete();
        });

        Cache::forget("top_countries:{$id}");
        Cache::forget("country_filter:{$id}");
        Cache::forget("insights:counts:{$id}:all");
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function buildAggregatePlan(): array
    {
        return [
            ['label' => 'websites', 'count' => DB::table('websites')->count()],
            ['label' => 'analytics_data', 'count' => DB::table('analytics_data')->count()],
            ['label' => 'search_console_data', 'count' => DB::table('search_console_data')->count()],
            ['label' => 'ai_insights', 'count' => DB::table('ai_insights')->count()],
            ['label' => 'backlinks', 'count' => DB::table('backlinks')->count()],
            ['label' => 'page_audit_reports', 'count' => DB::table('page_audit_reports')->count()],
            ['label' => 'custom_page_audits', 'count' => DB::table('custom_page_audits')->count()],
            ['label' => 'page_indexing_statuses', 'count' => DB::table('page_indexing_statuses')->count()],
            ['label' => 'rank_tracking_keywords', 'count' => DB::table('rank_tracking_keywords')->count()],
            ['label' => 'website_invitations', 'count' => DB::table('website_invitations')->count()],
            ['label' => 'website_user', 'count' => DB::table('website_user')->count()],
            ['label' => 'website_plugin_installs', 'count' => DB::table('website_plugin_installs')->count()],
            ['label' => 'redirect_suggestions', 'count' => DB::table('redirect_suggestions')->count()],
            ['label' => 'outreach_prospects', 'count' => DB::table('outreach_prospects')->count()],
            ['label' => 'client_activities', 'count' => DB::table('client_activities')->count()],
        ];
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function buildPlan(string $websiteId): array
    {
        return [
            ['label' => 'website', 'count' => DB::table('websites')->where('id', $websiteId)->count()],
            ['label' => 'analytics_data', 'count' => DB::table('analytics_data')->where('website_id', $websiteId)->count()],
            ['label' => 'search_console_data', 'count' => DB::table('search_console_data')->where('website_id', $websiteId)->count()],
            ['label' => 'ai_insights', 'count' => DB::table('ai_insights')->where('website_id', $websiteId)->count()],
            ['label' => 'backlinks', 'count' => DB::table('backlinks')->where('website_id', $websiteId)->count()],
            ['label' => 'page_audit_reports', 'count' => DB::table('page_audit_reports')->where('website_id', $websiteId)->count()],
            ['label' => 'custom_page_audits', 'count' => DB::table('custom_page_audits')->where('website_id', $websiteId)->count()],
            ['label' => 'page_indexing_statuses', 'count' => DB::table('page_indexing_statuses')->where('website_id', $websiteId)->count()],
            ['label' => 'rank_tracking_keywords', 'count' => DB::table('rank_tracking_keywords')->where('website_id', $websiteId)->count()],
            ['label' => 'website_invitations', 'count' => DB::table('website_invitations')->where('website_id', $websiteId)->count()],
            ['label' => 'website_user', 'count' => DB::table('website_user')->where('website_id', $websiteId)->count()],
            ['label' => 'website_plugin_installs', 'count' => DB::table('website_plugin_installs')->where('website_id', $websiteId)->count()],
            ['label' => 'redirect_suggestions', 'count' => DB::table('redirect_suggestions')->where('website_id', $websiteId)->count()],
            ['label' => 'outreach_prospects', 'count' => DB::table('outreach_prospects')->where('website_id', $websiteId)->count()],
            ['label' => 'client_activities', 'count' => DB::table('client_activities')->where('website_id', $websiteId)->count()],
        ];
    }
}

