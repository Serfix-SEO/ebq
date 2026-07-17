<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Autopilot — auto content calendar (plan: AUTO_CONTENT_CALENDAR_PLAN.md).
 *
 * Six tables:
 *  - content_plans         one per website: cadence, style toggles, business profile
 *  - content_topics        the calendar cells (dated, stateful pipeline rows)
 *  - content_articles      one row per draft VERSION (revision loop keeps history)
 *  - content_images        AI-generated images per article (featured/inline)
 *  - content_integrations  publish credentials per website (encrypted)
 *  - content_publications  one row per publish attempt/result per integration
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('status', 12)->default('active'); // active|paused
            $table->unsignedTinyInteger('articles_per_week')->default(3);
            $table->json('publish_days')->nullable();        // [1,3,5] ISO weekday numbers
            $table->unsignedTinyInteger('publish_hour_start')->default(9);
            $table->unsignedTinyInteger('publish_hour_end')->default(11);
            $table->string('timezone', 64)->default('UTC');

            $table->unsignedSmallInteger('article_length')->default(2500); // 1500|2000|2500|3000
            $table->boolean('auto_publish')->default(false);
            // Hours a `ready` article waits for client veto before auto-publish.
            $table->unsignedSmallInteger('review_hours')->default(24);
            $table->json('toggles')->nullable();             // toc|key_takeaways|faq|external_links|author_box|cta_enabled
            $table->string('cta_url', 500)->nullable();
            $table->text('custom_instructions')->nullable(); // CustomPromptGuard-validated
            $table->text('business_description')->nullable();
            $table->json('offerings')->nullable();           // {sell:[], dont_sell:[]}
            $table->json('internal_urls')->nullable();       // manual money-page additions
            $table->text('image_style_prompt')->nullable();
            $table->string('language', 12)->default('en');
            $table->string('country', 2)->nullable();

            $table->timestamps();
        });

        Schema::create('content_topics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('content_plans')->cascadeOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();

            $table->string('title', 300);
            $table->string('target_keyword', 200);
            $table->json('secondary_keywords')->nullable();
            $table->string('intent', 20)->nullable();        // informational|commercial|transactional|navigational
            $table->string('source', 20)->default('llm');    // gsc_gap|keywords|competitor|llm|manual
            $table->unsignedInteger('keyword_volume')->nullable();
            $table->date('scheduled_for')->nullable();
            // suggested|approved|researching|writing|scoring|revising|ready|scheduled|publishing|published|failed|skipped
            $table->string('status', 12)->default('suggested');
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('brief')->nullable();               // research output (ResearchTopicJob)
            $table->json('meta')->nullable();                // dedupe fingerprint, cannibalization result
            $table->text('last_error')->nullable();
            $table->timestamp('stage_started_at')->nullable(); // reaper watchdog anchor
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['website_id', 'scheduled_for']);
            $table->index(['plan_id', 'status']);
            $table->index(['status', 'stage_started_at']);
        });

        Schema::create('content_articles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('topic_id')->constrained('content_topics')->cascadeOnDelete();

            $table->unsignedTinyInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('h1', 300)->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('slug', 200)->nullable();
            $table->longText('html')->nullable();
            $table->longText('markdown')->nullable();
            $table->json('outline')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->json('seo_issues')->nullable();
            $table->json('style_issues')->nullable();
            $table->json('generation_meta')->nullable();     // provider/model/tokens/cost_usd/duration/reasoning

            $table->timestamps();

            $table->unique(['topic_id', 'version']);
            $table->index(['topic_id', 'is_current']);
        });

        Schema::create('content_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('article_id')->constrained('content_articles')->cascadeOnDelete();

            $table->string('role', 10)->default('inline');   // featured|inline
            $table->string('section_anchor', 200)->nullable();
            $table->text('prompt')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->json('params')->nullable();              // speed/style/aspect/seed/resolution
            $table->string('disk_path', 500)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->string('alt_text', 300)->nullable();
            $table->string('caption', 300)->nullable();
            $table->string('filename', 200)->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->string('status', 10)->default('pending'); // pending|generated|failed|rejected

            $table->timestamps();

            $table->index(['article_id', 'role']);
        });

        Schema::create('content_integrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();

            // wordpress (our plugin) | wordpress_app_password | shopify | webhook
            $table->string('platform', 30);
            $table->text('credentials')->nullable();         // encrypted JSON cast
            $table->json('config')->nullable();              // author/category/blog handle/post status
            $table->string('status', 12)->default('pending'); // pending|connected|error
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['website_id', 'platform']);
        });

        Schema::create('content_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('article_id')->constrained('content_articles')->cascadeOnDelete();
            $table->foreignUlid('integration_id')->constrained('content_integrations')->cascadeOnDelete();

            $table->string('external_id', 100)->nullable();
            $table->string('external_url', 600)->nullable();
            $table->string('status', 12)->default('queued'); // queued|sent|confirmed|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('response')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('verified_at')->nullable();    // post-publish live-URL fetch OK

            $table->timestamps();

            $table->unique(['article_id', 'integration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publications');
        Schema::dropIfExists('content_integrations');
        Schema::dropIfExists('content_images');
        Schema::dropIfExists('content_articles');
        Schema::dropIfExists('content_topics');
        Schema::dropIfExists('content_plans');
    }
};
