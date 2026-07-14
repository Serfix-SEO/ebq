<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real DataForSEO cost (USD) of the LATEST generation for this domain,
 * captured from the API response's own `tasks[0].cost` field — replaces the
 * flat `services.report.generation_cost_usd` estimate the admin Site
 * Explorer Usage page used before. See DataForSeoBacklinkClient::totalCost().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_report_snapshots', function (Blueprint $table): void {
            $table->decimal('dataforseo_cost_usd', 8, 4)->nullable()->after('backlinks_total');
        });
    }

    public function down(): void
    {
        Schema::table('website_report_snapshots', function (Blueprint $table): void {
            $table->dropColumn('dataforseo_cost_usd');
        });
    }
};
