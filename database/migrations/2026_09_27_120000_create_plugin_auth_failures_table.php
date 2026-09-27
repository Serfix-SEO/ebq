<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installs that can no longer authenticate — keyed by HOST, not by website.
 *
 * This replaces the two columns added to `website_plugin_installs` this morning
 * (2026_09_27_090000). That design could only remember a failure for a site we
 * still have a Website row for, and the production scan showed the opposite is
 * the common case: gbwhatsapp.app has been posting every half hour on plugin
 * v1.0.5 against an account that no longer exists, and simcardairportbali.com
 * did the same for six days. Those are exactly the installs worth knowing
 * about, and exactly the ones the old shape was blind to.
 *
 * The website link is therefore nullable and survives the website's deletion
 * (nullOnDelete): a row with no website means "someone is still running our
 * plugin against an account that is gone", which is a different conversation
 * from "your token needs reconnecting".
 *
 * Dropping the old columns is safe: `website_plugin_installs` holds no rows on
 * production, and the columns are hours old.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_auth_failures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The site as the plugin reports itself in its User-Agent. A
            // rejected request carries nothing else we can key on.
            $table->string('host')->unique();
            $table->foreignUlid('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_url', 600)->nullable();
            $table->string('plugin_version', 40)->nullable();
            // token_rejected  = no such token (usually cascaded away with the site)
            // website_missing = token exists but its website does not (403 path)
            $table->string('reason', 24)->default('token_rejected');
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('last_seen_at');
        });

        Schema::table('website_plugin_installs', function (Blueprint $table): void {
            $table->dropColumn(['auth_failing_since', 'last_auth_failure_at']);
        });
    }

    public function down(): void
    {
        Schema::table('website_plugin_installs', function (Blueprint $table): void {
            $table->timestamp('auth_failing_since')->nullable()->after('last_seen_at');
            $table->timestamp('last_auth_failure_at')->nullable()->after('auth_failing_since');
        });

        Schema::dropIfExists('plugin_auth_failures');
    }
};
