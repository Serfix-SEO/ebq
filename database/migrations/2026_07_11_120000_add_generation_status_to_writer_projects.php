<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Async article generation: the final "Generate article" step now runs as
 * a queued job (GenerateWriterDraftJob) instead of a 4-minute blocking
 * HTTP request, and both wizard UIs poll these columns for progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writer_projects', function (Blueprint $table): void {
            // idle | queued | running | done | failed
            $table->string('generation_status', 20)->default('idle')->after('generated_html');
            $table->string('generation_error', 120)->nullable()->after('generation_status');
            $table->timestamp('generation_started_at')->nullable()->after('generation_error');
        });
    }

    public function down(): void
    {
        Schema::table('writer_projects', function (Blueprint $table): void {
            $table->dropColumn(['generation_status', 'generation_error', 'generation_started_at']);
        });
    }
};
