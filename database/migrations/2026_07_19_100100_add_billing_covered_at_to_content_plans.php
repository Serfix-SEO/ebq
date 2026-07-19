<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A content subscription covers 1 website (base) + N addon websites. This
 * marks WHICH of a user's websites consume those slots — an explicit flag
 * (not derived from Stripe quantity, which can't say *which* site). Set when a
 * website is activated (trial start / checkout / addon); cleared on
 * deactivate / reconcile after a downgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->timestamp('billing_covered_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn('billing_covered_at');
        });
    }
};
