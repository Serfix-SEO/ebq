<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page daily Google Analytics (GA4) traffic for Content Autopilot reporting.
 * GA's existing sync (analytics_data) only stores date+source totals, with no
 * page dimension — so tracking a published article's own pageviews/sessions
 * needs this dedicated table. Populated daily by SyncContentPageAnalytics
 * (GoogleAnalyticsService::fetchPageTraffic) for content-entitled sites with GA.
 * GSC per-page/per-query metrics already live in search_console_data, so this is
 * GA-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_analytics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('page', 600); // UrlNormalizer-normalized, matches search_console_data.page

            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->decimal('engagement_rate', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['website_id', 'date', 'page']);
            $table->index(['website_id', 'page', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_analytics');
    }
};
