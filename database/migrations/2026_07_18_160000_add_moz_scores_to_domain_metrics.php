<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            // Moz DA/PA, fetched (rate/spend-guarded — see MozSpendMeter) and
            // cached here so ANY feature touching a domain (content wizard
            // competitors, backlinks, prospecting, etc.) reads the same
            // 30-day-fresh value instead of re-calling Moz per-feature.
            $table->unsignedTinyInteger('moz_da')->nullable()->after('spam_score');
            $table->unsignedTinyInteger('moz_pa')->nullable()->after('moz_da');
            $table->timestamp('moz_refreshed_at')->nullable()->after('moz_pa');
        });
    }

    public function down(): void
    {
        Schema::table('domain_metrics', function (Blueprint $table): void {
            $table->dropColumn(['moz_da', 'moz_pa', 'moz_refreshed_at']);
        });
    }
};
