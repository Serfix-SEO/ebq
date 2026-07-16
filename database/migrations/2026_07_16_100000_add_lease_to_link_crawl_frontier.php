<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lease-based claiming for the link-crawl frontier. Replaces the sequential
 * pass model (barrier-gated, fleet-underutilizing) with atomic per-worker
 * claims: a batch flips N `pending` rows to `in_progress` under a lease, so
 * unlimited batches can run concurrently across both boxes without ever
 * double-crawling a URL. Expired leases (crashed workers) are swept back to
 * `pending` by the reaper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_crawl_frontier', function (Blueprint $table) {
            $table->string('lease_id', 40)->nullable()->after('attempts');
            $table->timestamp('leased_until')->nullable()->after('lease_id');
            // Reaper: find in_progress rows whose lease expired.
            $table->index(['status', 'leased_until']);
        });
    }

    public function down(): void
    {
        Schema::table('link_crawl_frontier', function (Blueprint $table) {
            $table->dropIndex(['status', 'leased_until']);
            $table->dropColumn(['lease_id', 'leased_until']);
        });
    }
};
