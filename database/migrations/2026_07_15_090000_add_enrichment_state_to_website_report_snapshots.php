<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interim pipeline state for the empty-domain report enrichment (stage,
 * keyword request ids, page-text excerpts, SERP tally, attempt counters).
 * Kept out of `payload` so payload stays "renderable report only".
 * Additive — safe on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_report_snapshots', function (Blueprint $table): void {
            $table->json('enrichment_state')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('website_report_snapshots', function (Blueprint $table): void {
            $table->dropColumn('enrichment_state');
        });
    }
};
