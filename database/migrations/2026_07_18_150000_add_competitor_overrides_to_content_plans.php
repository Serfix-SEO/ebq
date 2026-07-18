<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            // Manual competitor add/remove from the wizard's "Competitors"
            // step: {added: string[], removed: string[]}. Applied on top of
            // the derived/cached ContentSetupInsights result, never inside it.
            $table->json('competitor_overrides')->nullable()->after('offerings');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn('competitor_overrides');
        });
    }
};
