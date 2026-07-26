<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Autopilot Keyword Tracker — the standing, capacity-limited list of
 * keywords a client is watching (500 per content-entitled website on paid, 3 on
 * trial; see ContentAutopilotConfig::trackerKeywords()). Auto-populated when an
 * article publishes and manually addable from the article detail page. The row
 * count itself IS the quota meter (KeywordTrackerQuota) — deliberately isolated
 * from the Redis spend meters. Performance is read from search_console_data
 * (GSC, keyed by normalized_keyword) + content_page_analytics (GA, keyed by page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_tracked_keywords', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            // Source article/topic. Nullable + nullOnDelete so a manually tracked
            // keyword, or one whose topic is later removed, survives as a bare
            // tracked term.
            $table->foreignUlid('topic_id')->nullable()->constrained('content_topics')->nullOnDelete();
            $table->foreignUlid('article_id')->nullable()->constrained('content_articles')->nullOnDelete();

            $table->string('keyword', 200);              // as displayed
            $table->string('normalized_keyword', 200);   // lower+trim, matches GSC query rows
            $table->string('page_url', 600)->nullable(); // published URL (GSC/GA page join)
            $table->boolean('is_primary')->default(false);
            $table->string('source', 12)->default('auto'); // auto | manual
            $table->foreignUlid('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per keyword per website — dedupe + the quota unit.
            $table->unique(['website_id', 'normalized_keyword']);
            $table->index(['website_id', 'created_at']);
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_tracked_keywords');
    }
};
