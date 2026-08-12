<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bug reports ARE support tickets (owner decision 2026-08-12): every bug
// report gets a ticket thread the client can follow up on. Soft ref, no FK
// — same policy as bug_reports.website_id. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->ulid('support_ticket_id')->nullable()->after('website_id');
            $table->index('support_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->dropIndex(['support_ticket_id']);
            $table->dropColumn('support_ticket_id');
        });
    }
};
