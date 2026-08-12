<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Covering index for the research page's striking-distance aggregation
// (90-day whole-site GROUP BY query with SUM(impressions)/AVG(position)):
// lets it run index-only instead of touching 1.7M+ table rows (20s → subsec
// on the biggest site; prod lag incident 2026-08-12). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_data', function (Blueprint $table): void {
            $table->index(['website_id', 'date', 'query', 'impressions', 'position'], 'scd_wid_date_query_cov');
        });
    }

    public function down(): void
    {
        Schema::table('search_console_data', function (Blueprint $table): void {
            $table->dropIndex('scd_wid_date_query_cov');
        });
    }
};
