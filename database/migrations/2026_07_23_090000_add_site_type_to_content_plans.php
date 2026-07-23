<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only — safe under `migrate --force` on live data. Null site_type
// means "not classified yet" and every consumer must behave exactly like the
// pre-site-type pipeline.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->string('site_type', 32)->nullable()->after('business_description');
            // 'auto' (extractor/backfill) or 'user' (explicit chip click) — a
            // user decision is never overwritten by re-classification.
            $table->string('site_type_source', 8)->nullable()->after('site_type');
            $table->string('audience', 500)->nullable()->after('site_type_source');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn(['site_type', 'site_type_source', 'audience']);
        });
    }
};
