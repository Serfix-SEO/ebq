<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public shareable-report tokens. A high-entropy `token` maps to a website;
 * the public /r/{token} route renders that site's report with no auth. Tokens
 * are revocable (revoked_at) and optionally expirable (expires_at). Bad /
 * revoked / expired tokens 404 (never 403) to prevent enumeration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->json('options')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
