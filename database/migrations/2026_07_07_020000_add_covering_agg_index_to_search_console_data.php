<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Covering index for the dashboard/statistics aggregates (2026-07-07).
 *
 * After fixing the 128MB stock buffer pool, the remaining cost in the KPI
 * sums / country GROUP BY / traffic-chart queries was the query plan: the
 * existing (website_id,date) and (website_id,country) indexes don't cover
 * clicks/impressions, so every one of ~650K matched rows (largest account)
 * did a PK lookup — 8-16s per aggregate even fully in RAM. This index lets
 * all three run index-only ("Using index"), no row lookups.
 *
 * Additive DDL, built INPLACE (online) — safe on prod per the DB rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw statement to force the online algorithm on MariaDB.
        DB::statement('ALTER TABLE search_console_data
            ADD INDEX scd_wid_date_cov (website_id, date, country, clicks, impressions),
            ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(): void
    {
        Schema::table('search_console_data', function ($table): void {
            $table->dropIndex('scd_wid_date_cov');
        });
    }
};
