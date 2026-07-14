<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan Site Explorer (backlink report) lookup throttle — a max number of
 * lookups within a rolling window, editable from the admin Plan editor.
 * null limit = unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('site_explorer_limit')->nullable()->after('max_seats');
            $table->unsignedSmallInteger('site_explorer_window_hours')->nullable()->default(24)->after('site_explorer_limit');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['site_explorer_limit', 'site_explorer_window_hours']);
        });
    }
};
