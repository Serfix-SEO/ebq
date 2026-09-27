<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AEO phase 3 — "does the model name you when a buyer asks?"
 *
 * The honest framing matters here. We cannot see inside ChatGPT: there is no
 * scrapeable query URL, so nobody without those vendors' APIs can report
 * citation share. What we CAN measure is what a model says when asked with no
 * browsing — which is exactly what "the AI recommends us" means for the large
 * share of answers that come from the model's own memory rather than live
 * retrieval. That is the number stored here, and the UI says so in those words.
 *
 * Runs stay one row per question per engine per day, so a re-run is an upsert
 * and a week of history is 7 rows. `ok`/`error` are columns rather than a
 * silent gap, because this codebase has twice lost weeks to a provider failing
 * quietly (Ideogram 401, DeepSeek 402).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The buyer questions we watch for this site. Row count is the quota
        // meter, the same shape content_tracked_keywords uses.
        Schema::create('content_aeo_questions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->string('normalized_question', 300);
            // brand = "best X in Dubai", article = a question one of our
            // articles targets, gsc = a real query from Search Console,
            // client = they typed it themselves.
            $table->string('source', 12)->default('llm');
            $table->foreignUlid('topic_id')->nullable()->constrained('content_topics')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'normalized_question'], 'caq_site_question_unique');
            $table->index(['website_id', 'is_active']);
        });

        Schema::create('content_aeo_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('question_id')->constrained('content_aeo_questions')->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->date('ran_on');
            $table->string('engine', 24);            // deepseek | mistral | google_aio
            $table->boolean('mentioned')->default(false);
            // Where the brand appeared in the answer's list, 1-based. Null when
            // it was not named at all — which is different from "last".
            $table->unsignedTinyInteger('mention_rank')->nullable();
            $table->json('competitors')->nullable();  // who got named instead
            $table->json('cited_urls')->nullable();   // only engines that cite
            $table->text('excerpt')->nullable();      // what it actually said
            $table->boolean('ok')->default(true);
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'engine', 'ran_on'], 'car_question_engine_day_unique');
            $table->index(['website_id', 'ran_on']);
        });

        // One visibility score per site per day, so the page can draw a line.
        Schema::create('content_aeo_scores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->date('scored_on');
            $table->unsignedTinyInteger('score');
            $table->json('components');    // each signal's value and weight
            $table->json('signals_used');  // which ones were available at all
            $table->timestamps();

            $table->unique(['website_id', 'scored_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_aeo_scores');
        Schema::dropIfExists('content_aeo_runs');
        Schema::dropIfExists('content_aeo_questions');
    }
};
