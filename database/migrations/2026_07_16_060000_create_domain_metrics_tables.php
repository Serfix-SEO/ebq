<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central accumulating domain-intelligence store (the "data asset"):
 * one row per domain we have EVER touched (report targets, referring
 * domains, competitors). Rows are never deleted on client churn — churned
 * domains keep accumulating free-feed history (CC quarterly, OPR monthly)
 * so returning clients find trend data waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            // active = owned by a current client website (paid DataForSEO
            // refresh via reports); free = everything else (free feeds only).
            $table->string('tier', 10)->default('free')->index();
            $table->unsignedBigInteger('cc_harmonic_rank')->nullable();
            $table->unsignedBigInteger('cc_pagerank_rank')->nullable();
            $table->decimal('opr_score', 4, 2)->nullable();
            $table->unsignedSmallInteger('dfs_rank')->nullable();
            $table->unsignedTinyInteger('trust_score')->nullable();
            $table->unsignedTinyInteger('citation_score')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            $table->boolean('is_seed')->default(false);
            // LLM topic classification — paid once per domain, cached forever.
            $table->string('topic', 40)->nullable();
            $table->timestamp('topic_classified_at')->nullable();
            // Importance signal for the OPR quota governor.
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('opr_refreshed_at')->nullable()->index();
            $table->timestamp('cc_refreshed_at')->nullable();
            $table->timestamps();
        });

        // Append-only metric history — feeds trend charts / rank-movement
        // arrows. Upsert-idempotent per (domain, source, day).
        Schema::create('domain_metric_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_metric_id')->constrained('domain_metrics')->cascadeOnDelete();
            $table->string('source', 20); // cc_harmonic | cc_pagerank | opr | dfs_rank | trust | citation
            $table->decimal('value', 14, 2);
            $table->date('captured_on');
            $table->unique(['domain_metric_id', 'source', 'captured_on'], 'dmh_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_metric_history');
        Schema::dropIfExists('domain_metrics');
    }
};
