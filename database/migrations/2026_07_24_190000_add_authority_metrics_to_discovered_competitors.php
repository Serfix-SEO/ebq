<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Explorer competitor discovery previously carried only a single OpenPageRank-
 * derived `domain_authority` (which undercounts referring domains 10-100x). We now
 * enrich each discovered competitor from the SAME shared `domain_metrics` asset the
 * Content Autopilot competitor analysis uses (DataForSEO referring-domains/backlinks
 * + Moz DA/PA), and expose an OPT-IN LLM topical-relevance classification. These
 * columns denormalize those values onto the per-website discovered row for display
 * and CSV export; `domain_metrics` stays the source of truth (30-day cache).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovered_competitors', function (Blueprint $table): void {
            $table->unsignedBigInteger('referring_domains')->nullable()->after('domain_authority');
            $table->unsignedBigInteger('backlinks')->nullable()->after('referring_domains');
            $table->unsignedTinyInteger('page_authority')->nullable()->after('backlinks');
            // Opt-in LLM topical relevance (never auto-run during discovery).
            $table->string('topic', 120)->nullable()->after('page_authority');
            $table->timestamp('topic_classified_at')->nullable()->after('topic');
        });
    }

    public function down(): void
    {
        Schema::table('discovered_competitors', function (Blueprint $table): void {
            $table->dropColumn(['referring_domains', 'backlinks', 'page_authority', 'topic', 'topic_classified_at']);
        });
    }
};
