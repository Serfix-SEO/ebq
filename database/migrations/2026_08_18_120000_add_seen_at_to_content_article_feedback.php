<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mark as seen" for client article feedback, so the admin dashboard shows
 * only what the team has not yet looked at. Additive and nullable: every
 * existing row starts unseen, which is the truthful default — nobody has
 * dismissed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_article_feedback', function (Blueprint $table): void {
            $table->timestamp('seen_at')->nullable()->after('comment');
            $table->string('seen_by')->nullable()->after('seen_at');
            // The dashboard query is "unseen, newest first".
            $table->index(['seen_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('content_article_feedback', function (Blueprint $table): void {
            $table->dropIndex(['seen_at', 'created_at']);
            $table->dropColumn(['seen_at', 'seen_by']);
        });
    }
};
