<?php

namespace App\Services\Sharding;

use App\Models\DbNode;
use App\Support\ShardTables;
use Illuminate\Support\Facades\DB;

/**
 * App-level cascade for the sharded tiers. Cross-tier foreign keys are dropped
 * (a tenant/crawl node has no central tables to point at), so deleting a website
 * or GC'ing a crawl-site can't rely on DB `ON DELETE CASCADE` — this deletes the
 * per-website / per-crawl-site rows explicitly, on the correct node connection.
 *
 * Tables are deleted child-before-parent (reverse of {@see ShardTables}) so the
 * subquery-scoped child rows are removed while their in-tier parent still exists.
 */
class ShardCleanup
{
    /** Delete every tenant-tier row for a website on its tenant node. */
    public function purgeWebsiteTenantData(string $websiteId, ?string $tenantConnection): void
    {
        $conn = DB::connection($tenantConnection);
        foreach (array_reverse(array_keys(ShardTables::TENANT)) as $table) {
            $conn->table($table)->whereRaw(ShardTables::tenantWhere($table, [$websiteId]))->delete();
        }
    }

    /** Delete every crawl-tier row for a crawl-site on its crawl node. */
    public function purgeCrawlSiteData(string $crawlSiteId, ?string $crawlConnection): void
    {
        $conn = DB::connection($crawlConnection);
        foreach (array_reverse(array_keys(ShardTables::CRAWL)) as $table) {
            $conn->table($table)->whereRaw(ShardTables::crawlWhere($table, $crawlSiteId))->delete();
        }
    }

    /** Laravel connection name for a node id (null id → default/central connection). */
    public static function connectionFor(?string $nodeId): ?string
    {
        return ($nodeId === null || $nodeId === '') ? null : DbNode::connectionNameFor($nodeId);
    }
}
