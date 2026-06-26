<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two seat-related columns to plans:
 *   max_seats              — hard cap on team members per website; null = unlimited
 *   extra_seat_price_usd   — display-only per-seat add-on price; null = N/A
 *
 * All new api_limits namespaces (keyword_research, ai_studio, long_form,
 * quick_win_finder) and the new plan_features.scheduled_reports flag live
 * inside the existing JSON columns — no schema change needed for those.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_seats')->nullable()->after('max_crawl_pages');
            $table->unsignedInteger('extra_seat_price_usd')->nullable()->after('max_seats');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['max_seats', 'extra_seat_price_usd']);
        });
    }
};
