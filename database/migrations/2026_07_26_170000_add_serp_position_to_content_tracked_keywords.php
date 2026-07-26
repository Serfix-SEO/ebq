<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live SERP rank for tracked keywords — a real Google position (Serper) shown
 * alongside the GSC average position. GSC is empty for days after a new article
 * publishes (impression lag), so the live SERP check gives an immediate signal
 * instead of the row sitting on "Collecting…". Refreshed by CheckTrackedKeywordSerpJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_tracked_keywords', function (Blueprint $table): void {
            $table->unsignedSmallInteger('serp_position')->nullable()->after('page_url'); // null = not in top 100 / unchecked
            $table->string('serp_url', 600)->nullable()->after('serp_position');
            $table->timestamp('serp_checked_at')->nullable()->after('serp_url');
        });
    }

    public function down(): void
    {
        Schema::table('content_tracked_keywords', function (Blueprint $table): void {
            $table->dropColumn(['serp_position', 'serp_url', 'serp_checked_at']);
        });
    }
};
