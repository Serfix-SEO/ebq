<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The covering index added an hour earlier did NOT speed the striking
// aggregation (the GROUP BY sort over 1.7M in-range entries dominates, not
// row access) — measured 21s with and without. Drop it: pure write
// amplification on a hot table. The fix that works is the 6h cache + daily
// warmer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_data', function (Blueprint $table): void {
            $table->dropIndex('scd_wid_date_query_cov');
        });
    }

    public function down(): void
    {
        Schema::table('search_console_data', function (Blueprint $table): void {
            $table->index(['website_id', 'date', 'query', 'impressions', 'position'], 'scd_wid_date_query_cov');
        });
    }
};
