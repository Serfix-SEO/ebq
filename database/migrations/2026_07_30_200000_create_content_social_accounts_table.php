<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Social auto-share accounts (Facebook Page / X) per website. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_social_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 12); // facebook | x
            $table->text('credentials')->nullable(); // encrypted array cast
            $table->string('status', 12)->default('connected'); // connected | error
            $table->boolean('share_enabled')->default(true);
            $table->string('display_name', 200)->nullable(); // page name / @handle (non-secret, listable)
            $table->timestamp('last_posted_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_social_accounts');
    }
};
