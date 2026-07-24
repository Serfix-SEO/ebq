<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-granted ("comped") Content Autopilot access — a number of FREE
 * per-website content slots an operator can assign to any client without a
 * Stripe `content` subscription, with an optional expiry. Additive to any
 * real subscription/trial allowance; non-destructive on expiry (existing
 * covered sites keep working, only new coverage is gated). Enforced in
 * ContentEntitlements (hasContentAccess / sitesAllowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('content_comp_sites')->default(0)->after('content_trial_ends_at');
            // null = permanent grant; a past datetime = expired (reverts to real plan).
            $table->timestamp('content_comp_until')->nullable()->after('content_comp_sites');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['content_comp_sites', 'content_comp_until']);
        });
    }
};
