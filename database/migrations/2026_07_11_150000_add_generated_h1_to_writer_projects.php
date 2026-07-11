<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO-optimized H1 support (owner report 2026-07-11: "h1 was not
 * generated and SEO optimized"):
 *  - `h1` / `h1_suggestions` — Strategy-step card: LLM-suggested H1
 *    options + the user's chosen/edited H1.
 *  - `generated_h1` — the writer's own H1 (keyword-front, ≤65 chars,
 *    output language) when the user didn't pick one.
 * Precedence downstream: h1 → generated_h1 → project title.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writer_projects', function (Blueprint $table): void {
            $table->string('h1', 200)->nullable()->after('meta_title');
            $table->json('h1_suggestions')->nullable()->after('h1');
            $table->string('generated_h1', 200)->nullable()->after('generated_html');
            // Strategy data card: LSI suggestions (one-click add) + the
            // volume/competition/trend map for every surfaced keyword.
            $table->json('lsi_suggestions')->nullable()->after('h1_suggestions');
            $table->json('keyword_data')->nullable()->after('lsi_suggestions');
            // Post-generation coverage report (LSI hit/miss, counts) —
            // shown on the review step so quality is visible, not a log line.
            $table->json('generation_meta')->nullable()->after('generated_h1');
        });
    }

    public function down(): void
    {
        Schema::table('writer_projects', function (Blueprint $table): void {
            $table->dropColumn(['h1', 'h1_suggestions', 'generated_h1', 'lsi_suggestions', 'keyword_data', 'generation_meta']);
        });
    }
};
