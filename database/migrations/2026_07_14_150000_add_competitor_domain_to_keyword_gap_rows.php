<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH competitor the live-SERP verification found ranking (the domain whose
 * best position landed in `competitor_position`) — without it the UI could
 * only say "Competitor #3" with no way to name the actual site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_gap_rows', function (Blueprint $table): void {
            $table->string('competitor_domain')->nullable()->after('competitor_position');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_gap_rows', function (Blueprint $table): void {
            $table->dropColumn('competitor_domain');
        });
    }
};
