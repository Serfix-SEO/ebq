<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan volume cursor for INCREMENTAL keyword classification (mirrors the
 * per-domain harvest cursor). Each classify run handles the next lower-volume
 * band (search_volume < cursor), appends verdicts, and never re-charges keywords
 * already below the cursor. See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->unsignedInteger('keywords_classify_cursor')->nullable()->after('keywords_classified_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn('keywords_classify_cursor');
        });
    }
};
