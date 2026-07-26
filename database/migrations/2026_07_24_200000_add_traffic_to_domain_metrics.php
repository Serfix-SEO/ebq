<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organic-traffic estimation for the Site Explorer (and reusable everywhere).
 *
 * DataForSEO Labs "historical bulk traffic estimation" is a single flat-priced
 * call for up to 1,000 targets, so one call per discovery run covers the site AND
 * every competitor. We keep BOTH the compact monthly series (fast chart reads) and
 * the FULL raw blob (so any future feature can use ranking-distribution buckets,
 * keyword movement, paid footprint, etc. without re-billing). 30-day cached on the
 * shared `domain_metrics` asset like every other DataForSEO/Moz column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            // Compact monthly series for the chart: [{month, visits, keywords}, …]
            $table->json('dfs_traffic_series')->nullable()->after('dfs_refreshed_at');
            // Full raw estimation blob (all buckets/movement/paid) — "save everything".
            $table->json('dfs_traffic')->nullable()->after('dfs_traffic_series');
            $table->timestamp('dfs_traffic_refreshed_at')->nullable()->after('dfs_traffic');
        });
    }

    public function down(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            $table->dropColumn(['dfs_traffic_series', 'dfs_traffic', 'dfs_traffic_refreshed_at']);
        });
    }
};
