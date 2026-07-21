<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('content-ai.table_prefix', 'content_ai_').'images';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')
                ->constrained(config('content-ai.table_prefix', 'content_ai_').'articles')
                ->cascadeOnDelete();

            // Where it came from, and where it now lives on OUR disk.
            $table->text('source_url');
            $table->string('source_hash', 64)->index();
            $table->string('disk', 60);
            $table->string('path');
            $table->string('alt_text', 500)->nullable();
            $table->string('role', 20)->default('inline');
            $table->unsignedBigInteger('bytes')->default(0);

            $table->timestamps();

            // One stored copy per source image per article — re-imports reuse it
            // instead of re-downloading and orphaning the previous file.
            $table->unique(['article_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
