<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sharding (option A): drop the foreign keys that point from a TENANT/CRAWL-tier
 * table to a CENTRAL table, so those tables can live on a separate shard node
 * (which has no central tables to reference). The columns stay as app-enforced
 * soft refs; integrity is now kept by {@see \App\Services\Sharding\ShardCleanup}
 * (cascade) + the mover (move sets). WITHIN-tier FKs are kept
 * (rank_tracking_snapshots→keywords, keyword_gap_rows→analyses,
 * website_internal_links/crawl_findings→website_pages/crawl_runs,
 * website_finding_states→crawl_findings).
 *
 * MySQL/MariaDB only: the test suite runs on sqlite where these FKs are harmless
 * to keep (and rebuilding sqlite tables to drop them is risky), and every real
 * shard node is MariaDB. Re-entrant: each drop is guarded, so re-running (or a
 * table whose FK was already removed earlier) is a no-op.
 */
return new class extends Migration
{
    /** @var array<string, array<int,string>> table => cross-tier FK columns */
    private const CROSS_TIER_FKS = [
        // tenant tier → central (websites / users / keywords)
        'search_console_data' => ['website_id', 'keyword_id'],
        'analytics_data' => ['website_id'],
        'page_indexing_statuses' => ['website_id'],
        'backlinks' => ['website_id'],
        'ai_insights' => ['website_id'],
        'page_audit_reports' => ['website_id'],
        'custom_page_audits' => ['website_id', 'user_id'],
        'rank_tracking_keywords' => ['website_id', 'user_id'],
        'writer_projects' => ['website_id', 'user_id'],
        'brand_voice_profiles' => ['website_id'],
        'website_sitemaps' => ['website_id'],
        'keyword_alerts' => ['website_id', 'keyword_id'],
        'keyword_gap_analyses' => ['website_id', 'user_id'],
        'competitor_discovery_runs' => ['website_id', 'user_id'],
        'discovered_competitors' => ['website_id'],
        'outreach_prospects' => ['website_id'],
        'redirect_suggestions' => ['website_id'],
        'crawl_report_sends' => ['website_id', 'recipient_user_id', 'sent_by_user_id'],
        'client_activities' => ['website_id', 'user_id', 'actor_user_id'],
        // crawl tier → central (crawl_sites / websites)
        'website_pages' => ['crawl_site_id'],
        'website_internal_links' => ['crawl_site_id'],
        'crawl_runs' => ['crawl_site_id'],
        'crawl_findings' => ['crawl_site_id'],
        'website_finding_states' => ['website_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return; // keep FKs in tests; shard nodes are MariaDB
        }

        foreach (self::CROSS_TIER_FKS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$column]));
                } catch (\Throwable) {
                    // FK already absent (re-run, or never created) — fine.
                }
            }
        }
    }

    public function down(): void
    {
        // Re-adding cross-tier FKs is intentionally unsupported — the sharded
        // schema is app-enforced. Recreate from the table migrations if needed.
    }
};
