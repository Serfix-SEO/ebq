<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competitor-mention guard state, one json blob per plan:
 *
 *   assessed_at / harmful / reason  — the LLM assessment (or fail-soft marker)
 *   auto: [{brand, domain, reason}] — product competitors the classifier blocked
 *   references: [domain, …]         — competitor-ADJACENT domains that are valid
 *                                     citations (e.g. google.com for an SEO tool)
 *   manual: [term, …]               — client-added blocked terms
 *   removed: [term, …]              — auto terms the client un-blocked
 *
 * The on/off switch itself lives in content_plans.toggles
 * ('block_competitor_mentions') alongside every other article toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->json('competitor_guard')->nullable()->after('competitor_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn('competitor_guard');
        });
    }
};
