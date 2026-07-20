<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan classified keyword set (see DATAFORSEO_KEYWORD_GAP_PLAN.md). Each row
 * is a keyword tagged for THIS plan as `own` (the client already ranks for it) or
 * `gap` (a competitor ranks, the client doesn't, and it's topically relevant —
 * LLM-vetted in bulk once). Both the step-6 gap card and the topic planner read
 * this table, so relevance is classified ONCE and reused (no re-filtering).
 * `content_plans.keywords_classified_at` marks a completed classification so the
 * UI shows the FINAL set, never a partial one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plan_keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->ulid('plan_id');
            $table->string('keyword_hash', 64);
            $table->string('keyword');
            $table->string('type', 8);            // own | gap
            $table->string('country', 16)->default('global');
            $table->unsignedInteger('search_volume')->nullable();
            $table->decimal('competition', 5, 4)->nullable();
            $table->unsignedTinyInteger('keyword_difficulty')->nullable();
            $table->string('search_intent', 20)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'keyword_hash'], 'cpk_plan_kw_unique');
            $table->index(['plan_id', 'type']);
        });

        Schema::table('content_plans', function (Blueprint $table) {
            $table->timestamp('keywords_classified_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn('keywords_classified_at');
        });
        Schema::dropIfExists('content_plan_keywords');
    }
};
