<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Keyword Gap tool can now target ANY website URL, not only one of the
 * user's own Website rows. A foreign-URL run has no owning Website, so
 * `website_id` becomes nullable (widening only — no data change; the FK still
 * holds for the rows that have one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_gap_analyses', function (Blueprint $table): void {
            $table->string('website_id', 26)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_gap_analyses', function (Blueprint $table): void {
            $table->string('website_id', 26)->nullable(false)->change();
        });
    }
};
