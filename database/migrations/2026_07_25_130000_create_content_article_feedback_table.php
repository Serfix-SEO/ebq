<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client sentiment on a generated article — the "Do you like this article?"
 * widget on the review page (love | rewrites | wrong), one CURRENT verdict per
 * (topic, user) via updateOrCreate, with an optional free-text comment. Feeds
 * the admin monitoring page (admin.content-feedback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_article_feedback', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('topic_id')->constrained('content_topics')->cascadeOnDelete();
            // Which article VERSION the verdict was given on (nullable so a topic
            // regeneration that drops the version doesn't delete the feedback).
            $table->foreignUlid('article_id')->nullable()->constrained('content_articles')->nullOnDelete();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('rating', 12);       // love | rewrites | wrong
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->unique(['topic_id', 'user_id']); // one current verdict per client per topic
            $table->index(['rating', 'created_at']);
            $table->index('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_article_feedback');
    }
};
