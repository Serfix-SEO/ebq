<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen link_crawl_frontier.status from char(10) to (20): the lease model
 * introduced the `in_progress` state (11 chars), which overflowed the
 * original column on MySQL (sqlite tests don't enforce length, so it slipped
 * through). Additive column change — safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_crawl_frontier', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('link_crawl_frontier', function (Blueprint $table) {
            $table->string('status', 10)->default('pending')->change();
        });
    }
};
