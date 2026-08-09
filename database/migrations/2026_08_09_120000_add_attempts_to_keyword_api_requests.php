<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Self-recovery for keyword requests (2026-08-09): a node browser crash drops
// its in-memory queue, orphaning our rows. The reaper/webhook now REDISPATCH
// the stored payload instead of only marking failed; `attempts` is the
// poison-pill cap (a payload that crashes the browser must not loop forever).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_api_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_api_requests', function (Blueprint $table): void {
            $table->dropColumn('attempts');
        });
    }
};
