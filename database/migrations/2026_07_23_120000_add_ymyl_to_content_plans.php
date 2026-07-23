<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive — safe under `migrate --force` on live data. Null = unclassified
// (no care rule beyond the site type's own ymyl_care default).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            // Classifier-assessed: does the site's SUBJECT affect readers'
            // health, money, safety or legal standing? Type-independent — a
            // supplements brand or finance blog gets the conservative-claims
            // writer rule even though 'brand'/'blog' don't set ymyl_care.
            $table->boolean('ymyl')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn('ymyl');
        });
    }
};
