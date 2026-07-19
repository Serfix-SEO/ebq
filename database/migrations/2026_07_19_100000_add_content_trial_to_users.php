<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Autopilot becomes a separately-billed product. The 5-day content
 * trial is app-managed (no card), one per user ever. We store an EXPLICIT
 * end timestamp so later admin changes to the trial length never retroactively
 * move a live trial. `is_system` marks the internal "content-leads" user that
 * owns provisional websites during anonymous onboarding (excluded from trial
 * cleanup + admin lists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('content_trial_started_at')->nullable()->after('trial_data_deleted_at');
            $table->timestamp('content_trial_ends_at')->nullable()->after('content_trial_started_at');
            $table->boolean('is_system')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['content_trial_started_at', 'content_trial_ends_at', 'is_system']);
        });
    }
};
