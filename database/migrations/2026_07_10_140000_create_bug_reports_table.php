<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app "Report a bug" feature: reports live on the CENTRAL connection
// (users/websites are central tables, so real FKs are fine — only
// tenant→central FKs are banned by the sharding policy). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable(); // soft ref — no cascade entanglement
            $table->text('url');
            $table->text('description');
            $table->string('screenshot_path')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('viewport', 50)->nullable(); // "1920x1080@2"
            $table->string('status', 20)->default('new'); // new | resolved
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
