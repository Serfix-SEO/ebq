<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_rewrite_credit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->index();
            $table->integer('delta'); // +purchase/+refund/+grant, -spend
            $table->string('kind', 20); // purchase|spend|refund|admin_grant
            // free = from the monthly subscriber allowance, purchased = paid pool.
            // Refunds mirror the source of the spend they reverse.
            $table->string('source', 12)->default('purchased');
            $table->ulid('topic_id')->nullable();
            $table->ulid('rewrite_request_id')->nullable()->index();
            $table->string('stripe_session_id')->nullable()->unique(); // fulfillment idempotency
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_rewrite_credit_events');
    }
};
