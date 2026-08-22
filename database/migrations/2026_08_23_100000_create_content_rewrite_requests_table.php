<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_rewrite_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Plain ulids, no FKs (content_generations precedent): audit +
            // refund linkage must survive topic/website/user deletion.
            $table->ulid('topic_id')->index();
            $table->ulid('user_id')->index();
            $table->ulid('website_id');
            $table->text('prompt')->nullable(); // blank = generic quality pass
            $table->string('status', 20)->default('queued'); // queued|running|done|failed
            $table->string('prior_status', 20); // topic status at dispatch — restore target
            $table->ulid('credit_event_id')->nullable();
            $table->unsignedInteger('article_version')->nullable(); // final version produced
            $table->string('error', 500)->nullable(); // INTERNAL only, never client-rendered
            $table->timestamp('client_seen_at')->nullable(); // dismisses the failure banner
            $table->timestamps();
            $table->index(['topic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_rewrite_requests');
    }
};
