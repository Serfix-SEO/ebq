<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive: resolution details for bug reports — what was fixed (sent to the
// reporter by email) and when.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->text('resolution_note')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('resolution_note');
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->dropColumn(['resolution_note', 'resolved_at']);
        });
    }
};
