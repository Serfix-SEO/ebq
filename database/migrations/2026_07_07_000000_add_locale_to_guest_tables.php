<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Captures the visitor's locale at submission time so the queued
     * follow-up email (sent later, from a worker with no HTTP request
     * context) can render in the same language as the tool page they used.
     */
    public function up(): void
    {
        Schema::table('guest_page_audits', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('name');
        });

        Schema::table('guest_page_speeds', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('name');
        });

        Schema::table('guest_rank_checks', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('name');
        });

        Schema::table('guest_keyword_volumes', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('guest_page_audits', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('guest_page_speeds', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('guest_rank_checks', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('guest_keyword_volumes', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
