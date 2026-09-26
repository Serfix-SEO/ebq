<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Visibility (AEO) phase 1 — the two things we can prove without paying
 * anyone: whether the AI engines are ALLOWED to read a site, and whether they
 * actually CAME.
 *
 * The third phase-1 signal — humans arriving from an AI answer — needs no table
 * at all: `analytics_data` already stores GA4 sessions per (website, date,
 * sessionSource), so AI referrals are a filtered read of data we sync daily.
 *
 * Bot hits can only be observed by code running ON the client's site (our
 * WordPress plugin or our PHP kit), so most websites will have an audit row and
 * no hit rows. That is a normal state, not a broken one, and the UI must say
 * "not installed" rather than "0 visits".
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per (site, bot, day). The client's site reports counts in
        // daily batches and may re-send the same day — hence the unique key and
        // an upsert on ingest, so a retried batch can never double-count.
        Schema::create('content_aeo_bot_hits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('bot', 32);          // App\Support\Aeo\AiAgents key
            $table->date('hit_on');
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('pages')->default(0);   // distinct paths that day
            $table->string('sample_path', 600)->nullable(); // one real example, for the UI
            $table->string('reporter', 12)->default('plugin'); // plugin | kit
            $table->timestamps();

            $table->unique(['website_id', 'bot', 'hit_on'], 'caebh_site_bot_day_unique');
            $table->index(['website_id', 'hit_on']);
        });

        // One row per readiness check. Kept as history, not overwritten, so
        // "you unblocked GPTBot three weeks ago" is answerable and so a failed
        // fetch never destroys the last good answer.
        Schema::create('content_aeo_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');

            // bot key => allowed | blocked | unknown. "unknown" is load-bearing:
            // a robots.txt we could not fetch must never be recorded as allowed.
            $table->json('robots_ai');
            $table->boolean('robots_fetched')->default(false);
            $table->boolean('llms_txt_present')->default(false);
            $table->string('llms_txt_url', 600)->nullable();
            $table->json('schema_coverage')->nullable();  // counts by @type from the crawl
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->json('breakdown');                    // per-check pass/weight, for the UI
            $table->timestamps();

            $table->index(['website_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_aeo_audits');
        Schema::dropIfExists('content_aeo_bot_hits');
    }
};
