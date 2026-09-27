<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WordPress install whose token has died is invisible today.
 *
 * pubgnamegenerator.net 401'd on every hourly call from 2026-07-18 to
 * 2026-09-26 — two months — and nothing noticed, because the plugin degrades
 * gracefully: feature flags fail open, so the client sees a working plugin
 * that quietly sends and receives nothing. The failure has no exception, no
 * failed job and no error page; the only trace was a column of 401s in the
 * access log that nobody reads.
 *
 * These two columns give the failure a memory, so `ebq:failed-jobs-alert` can
 * say "this site has been unable to authenticate for 63 days" instead of us
 * finding out during unrelated QA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_plugin_installs', function (Blueprint $table): void {
            // When the CURRENT run of failures began — not the last one, so the
            // digest can report how long this has been broken. Cleared by the
            // first successful authenticated call.
            $table->timestamp('auth_failing_since')->nullable()->after('last_seen_at');
            $table->timestamp('last_auth_failure_at')->nullable()->after('auth_failing_since');
        });
    }

    public function down(): void
    {
        Schema::table('website_plugin_installs', function (Blueprint $table): void {
            $table->dropColumn(['auth_failing_since', 'last_auth_failure_at']);
        });
    }
};
