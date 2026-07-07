<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trial-expiry data cleanup (2026-07-07): tracks which expiry notices a
 * trial user has received (dedupe across hourly runs) and when their data
 * was deleted (one-shot guard — a returning expired user keeps their login
 * but never gets a fresh trial or a second deletion pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('trial_deletion_notices')->nullable();
            $table->timestamp('trial_data_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['trial_deletion_notices', 'trial_data_deleted_at']);
        });
    }
};
