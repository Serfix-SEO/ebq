<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing/lifecycle email opt-out (set via the signed unsubscribe link in
 * lifecycle emails). Suppresses lifecycle/marketing mail only — transactional
 * mail (verification, trial-deletion notices, published-article) ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_emails_opted_out_at')->nullable()->after('trial_discount_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('marketing_emails_opted_out_at');
        });
    }
};
