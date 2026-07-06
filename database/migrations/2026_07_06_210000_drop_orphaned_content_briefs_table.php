<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * content_briefs was created for ContentBriefGenerator/ContentBriefService but
 * that integration never shipped — no model, no queries, no references
 * anywhere in app/ (confirmed by repo-wide grep + 0 rows in prod). Dropping
 * the dead schema. See infra/main.md "Latent bugs surfaced by the reference
 * sweep" (2026-06-16) and infra/reference/database.md.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('content_briefs');
    }

    public function down(): void
    {
        Schema::create('content_briefs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignUlid('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->index(['website_id', 'created_at']);
            $table->index(['keyword_id']);
        });
    }
};
