<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding/settings dropdowns switched `language` to full names
 * ("English", "Chinese (simplified)" — up to 20 chars) and `country` to
 * KeywordFinderLocations keys ("global", "united arab emirates"-style names).
 * The original varchar(12)/varchar(2) columns truncate those → "Data too long
 * for column 'country'". Widen both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->string('language', 40)->default('en')->change();
            $table->string('country', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->string('language', 12)->default('en')->change();
            $table->string('country', 2)->nullable()->change();
        });
    }
};
