<?php

namespace App\Support;

use App\Models\DbNode;
use Illuminate\Support\Facades\DB;

/**
 * Per-request / per-job routing state for the sharded tiers.
 *
 * Three logical tiers (see SHARDING_PLAN.md):
 *   - GLOBAL  — identity, billing, catalogs, routing anchors → the default
 *               (central) connection, always.
 *   - TENANT  — per-website fact data → the owner's shard node.
 *   - CRAWL   — per-domain crawl data → the domain's crawl node.
 *
 * A tenant/crawl model asks this singleton for its connection at query time (via
 * {@see \App\Models\Concerns\UsesTenantConnection} / UsesCrawlConnection). When
 * nothing is routed — single-node, CLI, tests, or anchors still NULL — both
 * resolve to `null` (Eloquent's default connection), so behaviour is identical
 * to today. Routing only diverges once a website/crawl-site carries a node id.
 *
 * Anchors live on the central tables and are read here via the query builder on
 * the DEFAULT connection (never via the routed models, to avoid recursion).
 * Bound as a singleton; reset between requests by {@see \App\Http\Middleware\ResolveShardContext}.
 */
class ShardContext
{
    private ?string $tenantConnection = null;

    private ?string $crawlConnection = null;

    /** @var array<string, array{t: ?string, c: ?string}> per-request memo */
    private array $websiteMemo = [];

    public function setTenantConnection(?string $name): void
    {
        $this->tenantConnection = $name;
    }

    public function setCrawlConnection(?string $name): void
    {
        $this->crawlConnection = $name;
    }

    public function tenantConnection(): ?string
    {
        return $this->tenantConnection;
    }

    public function crawlConnection(): ?string
    {
        return $this->crawlConnection;
    }

    public function reset(): void
    {
        $this->tenantConnection = null;
        $this->crawlConnection = null;
    }

    /**
     * Route the current request/job to the node(s) hosting this website's data:
     * tenant tier from websites.db_node_id, crawl tier from the linked
     * crawl_sites.crawl_node_id. NULL anchors → default connection.
     */
    public function forWebsite(?string $websiteId): void
    {
        if ($websiteId === null || $websiteId === '') {
            $this->reset();

            return;
        }

        $r = $this->resolveWebsite($websiteId);
        $this->tenantConnection = $r['t'];
        $this->crawlConnection = $r['c'];
    }

    /** Route only the crawl tier (e.g. a crawl job keyed by crawl_site). */
    public function forCrawlSite(?string $crawlSiteId): void
    {
        $this->crawlConnection = $this->connectionFor($this->crawlNodeId($crawlSiteId));
    }

    /**
     * @return array{t: ?string, c: ?string}
     */
    private function resolveWebsite(string $websiteId): array
    {
        if (isset($this->websiteMemo[$websiteId])) {
            return $this->websiteMemo[$websiteId];
        }

        $t = null;
        $c = null;
        try {
            $row = DB::table('websites')->where('id', $websiteId)->first(['db_node_id', 'crawl_site_id']);
            if ($row !== null) {
                $t = $this->connectionFor($row->db_node_id ?? null);
                $c = $this->connectionFor($this->crawlNodeId($row->crawl_site_id ?? null));
            }
        } catch (\Throwable) {
            // DB unavailable / table missing (install, early boot) → default.
        }

        return $this->websiteMemo[$websiteId] = ['t' => $t, 'c' => $c];
    }

    private function crawlNodeId(?string $crawlSiteId): ?string
    {
        if ($crawlSiteId === null || $crawlSiteId === '') {
            return null;
        }
        try {
            $v = DB::table('crawl_sites')->where('id', $crawlSiteId)->value('crawl_node_id');

            return $v !== null ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function connectionFor(?string $nodeId): ?string
    {
        return ($nodeId === null || $nodeId === '') ? null : DbNode::connectionNameFor((string) $nodeId);
    }
}
