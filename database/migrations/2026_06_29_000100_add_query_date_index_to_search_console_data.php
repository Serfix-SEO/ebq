<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_data', function (Blueprint $table) {
            // Optimal index for per-keyword time-series queries in RankTrackingDetail:
            // WHERE website_id=? AND query=? AND date>=?  GROUP BY date
            // The existing scd_wid_date_query is (website_id, date, query) — after a
            // date range the query column can't be used for equality filtering.
            // This (website_id, query, date) index pins query equality first, then
            // does a date range scan on a tiny fraction of rows.
            $table->index(['website_id', 'query', 'date'], 'scd_wid_query_date');
        });
    }

    public function down(): void
    {
        Schema::table('search_console_data', function (Blueprint $table) {
            $table->dropIndex('scd_wid_query_date');
        });
    }
};
