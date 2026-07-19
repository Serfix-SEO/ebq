<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks an anonymous content-onboarding run: a provisional Website (owned by
 * the system "content-leads" user) + wizard progress, keyed by a token stored
 * in the visitor's session. Converted into a real user's website on signup;
 * unconverted rows (+ their provisional sites) are garbage-collected after a
 * few days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_onboarding_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('token', 64)->unique();
            $table->foreignUlid('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedTinyInteger('step')->default(0);
            $table->foreignUlid('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_onboarding_sessions');
    }
};
