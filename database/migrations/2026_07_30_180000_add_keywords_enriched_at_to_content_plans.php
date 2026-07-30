<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Monthly guard stamp for the paid keyword-metric enrichment sweep (DFS bulk
// difficulty/intent/volume). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->timestamp('keywords_enriched_at')->nullable()->after('keywords_classified_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn('keywords_enriched_at');
        });
    }
};
