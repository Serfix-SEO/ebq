<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only: one-shot marker so the trial-discount promo email
// (ebq:send-trial-discount-emails) is never sent twice to the same user.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('trial_discount_email_sent_at')->nullable()->after('trial_data_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('trial_discount_email_sent_at');
        });
    }
};
