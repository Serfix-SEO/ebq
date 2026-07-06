<?php

namespace App\Services\Sharding;

use App\Models\DbNode;
use App\Models\ShardMove;
use App\Models\User;
use App\Models\Website;
use App\Support\ShardLock;
use App\Support\ShardManager;
use App\Support\ShardTables;
use Illuminate\Support\Facades\DB;

/**
 * Moves a tenant (a user + all their websites' fact data) or a crawl-site's crawl
 * data between shard nodes. ULIDs are globally unique, so a move is a clean copy
 * (no id remapping). Sequence: lock → count → copy → verify row counts → flip the
 * central anchor → purge the source → unlock. Reversible until the purge.
 *
 * Copies are chunked per table (bounded memory). A {@see ShardLock} marks the
 * tenant/crawl-site "migrating" for the whole move, so in-flight write jobs (GSC
 * sync, audits, rank, crawl) re-queue themselves instead of writing to the source
 * during the window — no lost writes. The lock has a safety TTL.
 *
 * Progress (added 2026-07-06): every move creates a {@see ShardMove} row and
 * updates it per phase + per copy-chunk, so the fleet page (and `ebq:shard`)
 * can show live phase / rows-copied / percentage instead of a black box.
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
        $sourceNodeId = $user->db_node_id;
        $dest = $target->connectionName();

        if ($websiteIds === []) {
            $user->update(['db_node_id' => $target->id]);

            return [];
        }
        if (! $this->connectionExists($dest)) {
            throw new \RuntimeException("target connection {$dest} is not registered");
        }

        $move = $this->startMove('tenant', $userId, $user->email, $sourceNodeId, $target->id);

        // Lock the tenant for the whole move so write jobs defer (no source
        // writes between copy and purge). Always released, even on failure.
        foreach ($websiteIds as $wid) {
            ShardLock::lockWebsite($wid);
        }

        try {
            $tables = array_keys(ShardTables::TENANT);
            $whereFor = fn (string $t): string => ShardTables::tenantWhere($t, $websiteIds);

            $this->countPhase($move, $tables, $whereFor, $source);
            $counts = $this->copyPhase($move, $tables, $whereFor, $source, $dest);

            $move->update(['status' => ShardMove::STATUS_VERIFYING, 'current_table' => null]);
            $this->verify($tables, $whereFor, $source, $dest);

            // Cutover: flip the central anchors, then purge the source.
            $move->update(['status' => ShardMove::STATUS_CUTOVER]);
            DB::transaction(function () use ($user, $websiteIds, $target): void {
                $user->update(['db_node_id' => $target->id]);
                Website::whereIn('id', $websiteIds)->update(['db_node_id' => $target->id]);
            });

            $move->update(['status' => ShardMove::STATUS_PURGING]);
            foreach ($websiteIds as $wid) {
                $this->cleanup->purgeWebsiteTenantData($wid, $source);
            }

            if ($sourceNodeId !== null && $sourceNodeId !== $target->id) {
                DbNode::where('id', $sourceNodeId)->where('tenant_count', '>', 0)->decrement('tenant_count');
            }
            $target->increment('tenant_count');
            ShardManager::flush();

            $this->finishMove($move, $counts);

            return $counts;
        } catch (\Throwable $e) {
            $this->failMove($move, $e);
            throw $e;
        } finally {
            foreach ($websiteIds as $wid) {
                ShardLock::unlockWebsite($wid);
            }
        }
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
        $sourceNodeId = $site->crawl_node_id;
        $dest = $target->connectionName();
        if (! $this->connectionExists($dest)) {
            throw new \RuntimeException("target connection {$dest} is not registered");
        }

        $move = $this->startMove('crawl', $crawlSiteId, $site->normalized_domain, $sourceNodeId, $target->id);

        ShardLock::lockCrawlSite($crawlSiteId);
        try {
            $tables = array_keys(ShardTables::CRAWL);
            $whereFor = fn (string $t): string => ShardTables::crawlWhere($t, $crawlSiteId);

            $this->countPhase($move, $tables, $whereFor, $source);
            $counts = $this->copyPhase($move, $tables, $whereFor, $source, $dest);

            $move->update(['status' => ShardMove::STATUS_VERIFYING, 'current_table' => null]);
            $this->verify($tables, $whereFor, $source, $dest);

            $move->update(['status' => ShardMove::STATUS_CUTOVER]);
            $site->update(['crawl_node_id' => $target->id]);

            $move->update(['status' => ShardMove::STATUS_PURGING]);
            $this->cleanup->purgeCrawlSiteData($crawlSiteId, $source);
            if ($sourceNodeId !== null && $sourceNodeId !== $target->id) {
                DbNode::where('id', $sourceNodeId)->where('site_count', '>', 0)->decrement('site_count');
            }
            $target->increment('site_count');
            ShardManager::flush();

            $this->finishMove($move, $counts);
        } catch (\Throwable $e) {
            $this->failMove($move, $e);
            throw $e;
        } finally {
            ShardLock::unlockCrawlSite($crawlSiteId);
        }

        return $counts;
    }

    private function startMove(string $kind, string $subjectId, ?string $label, ?string $sourceNodeId, string $targetNodeId): ShardMove
    {
        return ShardMove::create([
            'kind' => $kind,
            'subject_id' => $subjectId,
            'subject_label' => $label,
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'status' => ShardMove::STATUS_COUNTING,
            'started_at' => now(),
        ]);
    }

    /** Pre-count source rows per table so the copy phase can show a real percentage. */
    private function countPhase(ShardMove $move, array $tables, callable $whereFor, ?string $source): void
    {
        $total = 0;
        foreach ($tables as $table) {
            $total += DB::connection($source)->table($table)->whereRaw($whereFor($table))->count();
        }
        $move->update([
            'status' => ShardMove::STATUS_COPYING,
            'tables_total' => count($tables),
            'rows_total' => $total,
        ]);
    }

    /** @return array<string,int> per-table copied counts */
    private function copyPhase(ShardMove $move, array $tables, callable $whereFor, ?string $source, string $dest): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $move->update(['current_table' => $table]);
            $counts[$table] = $this->copyTable($table, $whereFor($table), $source, $dest, $move);
            $move->increment('tables_done');
        }

        return $counts;
    }

    private function finishMove(ShardMove $move, array $counts): void
    {
        $move->update([
            'status' => ShardMove::STATUS_COMPLETED,
            'current_table' => null,
            'table_counts' => $counts,
            'finished_at' => now(),
        ]);
    }

    private function failMove(ShardMove $move, \Throwable $e): void
    {
        // Best-effort — never mask the real exception with a bookkeeping one.
        try {
            $move->update([
                'status' => ShardMove::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    /** Stream-copy a filtered table from source to dest connection (chunked). */
    private function copyTable(string $table, string $where, ?string $source, string $dest, ?ShardMove $move = null): int
    {
        $copied = 0;
        DB::connection($source)->table($table)->whereRaw($where)->orderBy('id')
            ->chunk(1000, function ($rows) use ($table, $dest, &$copied, $move): void {
                $batch = array_map(fn ($r) => (array) $r, $rows->all());
                // insertOrIgnore (not insert): ULID PKs are globally unique, so a row
                // already present on the target is the SAME logical row — skip it. Makes
                // a re-run / partially-completed move idempotent instead of hitting a
                // duplicate-key error. The row-count verify still confirms completeness.
                DB::connection($dest)->table($table)->insertOrIgnore($batch);
                $copied += count($batch);
                $move?->increment('rows_copied', count($batch));
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
