<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('content-ai.table_prefix', 'content_ai_').'articles';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();

            // Serfix's id for this article. Unique + nullable: the FIRST delivery
            // carries no external_id (the publisher learns ours from our reply),
            // so we match on slug then, and on external_id for every update after.
            $table->string('external_id')->nullable()->unique();
            $table->string('slug')->unique();

            $table->string('title');
            $table->string('h1')->nullable();
            $table->longText('html');
            $table->longText('markdown')->nullable();
            $table->text('excerpt')->nullable();

            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_image')->nullable();
            $table->string('language', 12)->default('en');
            $table->string('target_keyword')->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->unsignedInteger('word_count')->default(0);

            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();

            // Set when a human edits the row locally, so a later article.updated
            // delivery can refuse to clobber it (config publishing.preserve_local_edits).
            $table->timestamp('locally_edited_at')->nullable();

            // Raw delivery + a hash of it: lets us replay/debug a bad import and
            // skip work when an identical payload is delivered twice.
            $table->json('payload')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
