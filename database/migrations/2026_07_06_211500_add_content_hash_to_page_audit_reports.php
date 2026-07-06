<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content-hash re-audit gate (added 2026-07-06, infra/audits/page-audit.md +
 * live-score-and-language.md §Gotchas). A completed audit was reused
 * indefinitely unless the WP-plugin-reported post `modified` time was newer
 * than `audited_at` — if WordPress doesn't bump `modified` correctly (some
 * page builders / ACF updates don't), stale CWV/perf data persists forever.
 * This hash, of the audited page's extracted body text (independent of
 * whatever WordPress self-reports), lets `ebq:recheck-audit-content`
 * independently detect real content drift and force a re-audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_audit_reports', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('primary_keyword_source');
        });
    }

    public function down(): void
    {
        Schema::table('page_audit_reports', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
