<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tracker SERP-check country override, separate from content_plans.country
// (which also steers article geo-targeting/keyword research — the client may
// want articles targeted at one market but rank checks from another).
// Null = fall back to the plan country. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->string('serp_country', 10)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn('serp_country');
        });
    }
};
