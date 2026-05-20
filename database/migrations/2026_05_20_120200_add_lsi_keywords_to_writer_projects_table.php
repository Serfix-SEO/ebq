<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writer_projects', function (Blueprint $table) {
            $table->json('lsi_keywords')->nullable()->after('additional_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('writer_projects', function (Blueprint $table) {
            $table->dropColumn('lsi_keywords');
        });
    }
};
