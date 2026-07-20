<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the raw DataForSEO Labs domain metrics (bulk traffic estimation —
 * organic/paid ETV, keyword counts, whatever the endpoint returns) per domain,
 * fetched in one batched call for a plan's competitors and reused across plans.
 * A JSON blob keeps "whatever is provided" without a column per metric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table) {
            $table->json('dfs_metrics')->nullable()->after('dfs_rank');
            $table->timestamp('dfs_metrics_refreshed_at')->nullable()->after('dfs_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table) {
            $table->dropColumn(['dfs_metrics', 'dfs_metrics_refreshed_at']);
        });
    }
};
