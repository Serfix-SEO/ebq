<?php

namespace App\Services\Sharding;

use App\Models\DbNode;
use App\Models\User;
use App\Models\Website;
use App\Support\ShardManager;
use App\Support\ShardTables;
use Illuminate\Support\Facades\DB;

/**
 * Moves a tenant (a user + all their websites' fact data) or a crawl-site's crawl
 * data between shard nodes. ULIDs are globally unique, so a move is a clean copy
 * (no id remapping). Sequence: copy → verify row counts → flip the central anchor
 * → purge the source. Reversible until the purge.
 *
 * Copies are chunked per table (bounded memory) over the source/target node
 * connections. Run during low tenant activity: in-flight writes to the source
 * between copy and anchor-flip are not captured (a per-tenant migrating lock is a
 * future hardening — see SHARDING_PLAN.md).
 */
class ShardMover
{
    public function __construct(private ShardCleanup $cleanup) {}

    /**
     * Move one tenant's data to $target. Returns per-table copied counts.
     *
     * @return array<string,int>
     */
    public function moveTenant(string $userId, DbNode $target): array
    {
        $user = User::findOrFail($userId);
        $websiteIds = $user->websites()->pluck('id')->map(fn ($v) => (string) $v)->all();
        $source = ShardCleanup::connectionFor($user->db_node_id);
        $dest = $target->connectionName();

        if ($websiteIds === []) {
            $user->update(['db_node_id' => $target->id]);

            return [];
        }
        if (! $this->connectionExists($dest)) {
            throw new \RuntimeException("target connection {$dest} is not registered");
        }

        $counts = [];
        // Parent-before-child (ShardTables order) so within-tier FKs resolve on insert.
        foreach (array_keys(ShardTables::TENANT) as $table) {
            $where = ShardTables::tenantWhere($table, $websiteIds);
            $counts[$table] = $this->copyTable($table, $where, $source, $dest);
        }

        $this->verify(array_keys(ShardTables::TENANT), fn ($t) => ShardTables::tenantWhere($t, $websiteIds), $source, $dest);

        // Cutover: flip the central anchors, then purge the source.
        DB::transaction(function () use ($user, $websiteIds, $target): void {
            $user->update(['db_node_id' => $target->id]);
            Website::whereIn('id', $websiteIds)->update(['db_node_id' => $target->id]);
        });
        foreach ($websiteIds as $wid) {
            $this->cleanup->purgeWebsiteTenantData($wid, $source);
        }

        $target->increment('tenant_count');
        ShardManager::flush();

        return $counts;
    }

    /**
     * Move one crawl-site's crawl data to $target crawl node.
     *
     * @return array<string,int>
     */
    public function moveCrawlSite(string $crawlSiteId, DbNode $target): array
    {
        $site = \App\Models\CrawlSite::findOrFail($crawlSiteId);
        $source = ShardCleanup::connectionFor($site->crawl_node_id);
        $dest = $target->connectionName();
        if (! $this->connectionExists($dest)) {
            throw new \RuntimeException("target connection {$dest} is not registered");
        }

        $counts = [];
        foreach (array_keys(ShardTables::CRAWL) as $table) {
            $counts[$table] = $this->copyTable($table, ShardTables::crawlWhere($table, $crawlSiteId), $source, $dest);
        }
        $this->verify(array_keys(ShardTables::CRAWL), fn ($t) => ShardTables::crawlWhere($t, $crawlSiteId), $source, $dest);

        $site->update(['crawl_node_id' => $target->id]);
        $this->cleanup->purgeCrawlSiteData($crawlSiteId, $source);
        $target->increment('site_count');
        ShardManager::flush();

        return $counts;
    }

    /** Stream-copy a filtered table from source to dest connection (chunked). */
    private function copyTable(string $table, string $where, ?string $source, string $dest): int
    {
        $copied = 0;
        DB::connection($source)->table($table)->whereRaw($where)->orderBy('id')
            ->chunk(1000, function ($rows) use ($table, $dest, &$copied): void {
                $batch = array_map(fn ($r) => (array) $r, $rows->all());
                DB::connection($dest)->table($table)->insert($batch);
                $copied += count($batch);
            });

        return $copied;
    }

    /** Abort if any table's row count differs between source and dest. */
    private function verify(array $tables, callable $where, ?string $source, string $dest): void
    {
        foreach ($tables as $table) {
            $clause = $where($table);
            $src = DB::connection($source)->table($table)->whereRaw($clause)->count();
            $dst = DB::connection($dest)->table($table)->whereRaw($clause)->count();
            if ($src !== $dst) {
                throw new \RuntimeException("move verify failed on {$table}: source={$src} target={$dst} (source left intact)");
            }
        }
    }

    private function connectionExists(string $name): bool
    {
        if (config("database.connections.{$name}")) {
            return true;
        }
        (new ShardManager)->register();

        return (bool) config("database.connections.{$name}");
    }
}
