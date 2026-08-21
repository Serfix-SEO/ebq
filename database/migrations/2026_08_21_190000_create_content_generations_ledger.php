<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Additive only — safe for prod `migrate --force`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_generations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Deliberately NO foreign keys to websites/topics: this ledger's
            // whole purpose is surviving website deletion — the old counters
            // lived on cascade-deleted rows, so delete-site + re-add reset the
            // trial and monthly caps (found 2026-08-21). user_id is a plain
            // indexed column too, so a user purge never breaks and the row is
            // an immutable audit fact.
            $table->ulid('user_id')->index();
            $table->ulid('website_id');
            $table->ulid('topic_id');
            $table->timestamp('created_at')->index();
        });

        // Backfill from live data so introducing the ledger doesn't RESET
        // anyone's current usage (v1 article = one generation, mirroring
        // ContentEntitlements::usageForWebsite). Websites already deleted are
        // unrecoverable — accepted.
        DB::statement(<<<'SQL'
            INSERT INTO content_generations (id, user_id, website_id, topic_id, created_at)
            SELECT ca.id, w.user_id, ct.website_id, ct.id, ca.created_at
            FROM content_articles ca
            JOIN content_topics ct ON ct.id = ca.topic_id
            JOIN websites w ON w.id = ct.website_id
            WHERE ca.version = 1
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_generations');
    }
};
