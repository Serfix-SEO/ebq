<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            // Real DataForSEO backlinks/summary/live counts for a domain
            // (paid, $0.024/request) — same global 30-day-fresh asset
            // pattern as moz_da/moz_pa. Used where an OPR/Moz-derived
            // referring-domains figure would be too inconsistent with a
            // paid figure elsewhere in the same UI (e.g. the content
            // wizard's competitor table vs the site's own DFS-sourced
            // referring-domains count).
            $table->unsignedBigInteger('dfs_referring_domains')->nullable()->after('moz_refreshed_at');
            $table->unsignedBigInteger('dfs_backlinks')->nullable()->after('dfs_referring_domains');
            $table->timestamp('dfs_refreshed_at')->nullable()->after('dfs_backlinks');
        });
    }

    public function down(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            $table->dropColumn(['dfs_referring_domains', 'dfs_backlinks', 'dfs_refreshed_at']);
        });
    }
};
