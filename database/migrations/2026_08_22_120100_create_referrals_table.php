<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Deliberately no hard FKs (content_generations precedent): the row
            // is reward/audit history and must survive account deletion.
            $table->ulid('referrer_user_id')->index();
            $table->ulid('referred_user_id')->unique(); // one reward per referred account
            $table->string('code_used', 16);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('credit_cents')->nullable();
            $table->string('currency', 3)->default('usd');
            $table->string('stripe_invoice_id')->nullable()->unique(); // webhook-retry idempotency
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
