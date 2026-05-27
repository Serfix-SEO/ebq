<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two covering indexes on `search_console_data` to support the GROUP BY query
 * and DISTINCT query aggregates in PluginHqController + ReportDataService.
 *
 * The table is large in production (tens of millions of rows on big sites).
 * `ALGORITHM=INPLACE, LOCK=NONE` lets the ALTER run online so the nightly
 * GSC sync keeps inserting rows during the index build.
 *
 * The migration is a no-op on non-MySQL drivers (SQLite/Postgres dev DBs)
 * and re-entry safe (skips if the index already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // SQLite / Postgres dev environments: add via Blueprint without
            // the MySQL-specific ALGORITHM hint.
            Schema::table('search_console_data', function ($table) {
                $table->index(['website_id', 'date', 'query'], 'scd_wid_date_query');
                $table->index(['website_id', 'date', 'position'], 'scd_wid_date_position');
            });
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM search_console_data'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $adds = [];
        if (! in_array('scd_wid_date_query', $existing, true)) {
            $adds[] = 'ADD INDEX scd_wid_date_query (website_id, date, query)';
        }
        if (! in_array('scd_wid_date_position', $existing, true)) {
            $adds[] = 'ADD INDEX scd_wid_date_position (website_id, date, position)';
        }

        if ($adds === []) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE search_console_data %s, ALGORITHM=INPLACE, LOCK=NONE',
            implode(', ', $adds),
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            Schema::table('search_console_data', function ($table) {
                $table->dropIndex('scd_wid_date_query');
                $table->dropIndex('scd_wid_date_position');
            });
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM search_console_data'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $drops = [];
        if (in_array('scd_wid_date_query', $existing, true)) {
            $drops[] = 'DROP INDEX scd_wid_date_query';
        }
        if (in_array('scd_wid_date_position', $existing, true)) {
            $drops[] = 'DROP INDEX scd_wid_date_position';
        }

        if ($drops === []) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE search_console_data %s, ALGORITHM=INPLACE, LOCK=NONE',
            implode(', ', $drops),
        ));
    }
};
