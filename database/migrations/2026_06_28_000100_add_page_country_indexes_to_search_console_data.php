<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_data', function (Blueprint $table) {
            // Covers: WHERE website_id=? AND page=? AND date>=? (PageDetail queries)
            $table->index(['website_id', 'page', 'date'], 'scd_wid_page_date');

            // Covers: WHERE website_id=? AND country!=? GROUP BY country (CountryFilter + country aggregations)
            $table->index(['website_id', 'country'], 'scd_wid_country');
        });
    }

    public function down(): void
    {
        Schema::table('search_console_data', function (Blueprint $table) {
            $table->dropIndex('scd_wid_page_date');
            $table->dropIndex('scd_wid_country');
        });
    }
};
