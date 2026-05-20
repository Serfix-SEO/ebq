<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `selected_links` — the user's curated picks from `link_suggestions`
     * plus any links they typed in manually on the strategy step.
     *
     * Shape:
     *   {
     *     "internal": [{ "anchor": "vegan protein", "url": "https://…", "manual": false }],
     *     "external": [{ "anchor": "WHO study",    "url": "https://…", "manual": true  }]
     *   }
     *
     * `link_suggestions` (the AI-generated pool) stays alongside it as
     * the source of candidates. `selected_links` is what the article
     * generator consumes — empty means "no user preference, fall back
     * to the GSC-derived internal_links and skip external entirely".
     */
    public function up(): void
    {
        Schema::table('writer_projects', function (Blueprint $table) {
            $table->json('selected_links')->nullable()->after('link_suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('writer_projects', function (Blueprint $table) {
            $table->dropColumn('selected_links');
        });
    }
};
