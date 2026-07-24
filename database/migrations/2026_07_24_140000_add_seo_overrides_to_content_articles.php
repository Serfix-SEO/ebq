<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-article SEO overrides — the in-app editor now exposes the same on-page
 * SEO fields the WordPress plugin's post-edit sidebar does (focus keyphrase,
 * canonical, robots, OpenGraph + Twitter social), editable for THAT article
 * only. All nullable/defaulted so existing versions are untouched; each new
 * saved version carries its own copy (content_articles is append-only).
 * On publish these map to the plugin's `_ebq_*` post meta / webhook payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_articles', function (Blueprint $table): void {
            // Focus keyphrase override — when set, the live audit + publish use
            // it instead of the topic's target_keyword.
            $table->string('focus_keyword', 200)->nullable()->after('slug');
            $table->string('canonical_url', 500)->nullable()->after('focus_keyword');
            $table->boolean('robots_noindex')->default(false)->after('canonical_url');
            $table->boolean('robots_nofollow')->default(false)->after('robots_noindex');
            // Social — OpenGraph + Twitter card. Empty = inherit from title/meta.
            $table->string('og_title', 300)->nullable()->after('robots_nofollow');
            $table->string('og_description', 500)->nullable()->after('og_title');
            $table->string('og_image', 500)->nullable()->after('og_description');
            $table->string('twitter_title', 300)->nullable()->after('og_image');
            $table->string('twitter_description', 500)->nullable()->after('twitter_title');
            $table->string('twitter_image', 500)->nullable()->after('twitter_description');
            $table->string('twitter_card', 20)->nullable()->after('twitter_image');
        });
    }

    public function down(): void
    {
        Schema::table('content_articles', function (Blueprint $table): void {
            $table->dropColumn([
                'focus_keyword', 'canonical_url', 'robots_noindex', 'robots_nofollow',
                'og_title', 'og_description', 'og_image',
                'twitter_title', 'twitter_description', 'twitter_image', 'twitter_card',
            ]);
        });
    }
};
