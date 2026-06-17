<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routing anchors for the two shard dimensions (see SHARDING_PLAN.md):
 *   - tenant tier: a user (and all their websites) lives on one node →
 *     `users.db_node_id` / `websites.db_node_id`.
 *   - crawl tier: a domain's crawl lives on one node → `crawl_sites.crawl_node_id`.
 *
 * All nullable + default NULL = "the default/central connection" (single-node
 * mode), so this migration is behaviour-neutral until a node is assigned.
 * FK → db_nodes is same-connection (both central), nullOnDelete so destroying a
 * node never cascade-deletes tenants/sites (they must be moved off first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('db_node_id')->nullable()->after('id')->constrained('db_nodes')->nullOnDelete();
        });
        Schema::table('websites', function (Blueprint $table): void {
            $table->foreignUlid('db_node_id')->nullable()->after('user_id')->constrained('db_nodes')->nullOnDelete();
        });
        Schema::table('crawl_sites', function (Blueprint $table): void {
            $table->foreignUlid('crawl_node_id')->nullable()->after('id')->constrained('db_nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('db_node_id');
        });
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('db_node_id');
        });
        Schema::table('crawl_sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('crawl_node_id');
        });
    }
};
