<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * content_articles.version was tinyint unsigned — a runaway regeneration loop
 * (namesforfreefire.com, 2026-08-29) hit 255 and every subsequent storeVersion
 * crashed with "out of range". The dispatcher now caps regenerations, but the
 * column must still not be the crash point (client rewrites + edits + scrubs
 * legitimately accumulate). Additive-safe widen to smallint unsigned (65535).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_articles', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('content_articles', function (Blueprint $table) {
            $table->unsignedTinyInteger('version')->default(1)->change();
        });
    }
};
