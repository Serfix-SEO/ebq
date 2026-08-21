<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only — safe for prod `migrate --force`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            // Admin-set steering directives for this website's content, appended
            // (never overriding) to every content-generation LLM prompt via
            // ContentPlan::promptAddendumBlock(). Sibling of custom_instructions
            // (reserved for a future client-facing field).
            $table->text('admin_content_prompt')->nullable()->after('custom_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn('admin_content_prompt');
        });
    }
};
