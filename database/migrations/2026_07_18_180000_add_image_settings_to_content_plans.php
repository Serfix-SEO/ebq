<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan image controls: whether the client wants images at all, and the
 * visual style category to render them in. `image_style_prompt` already
 * existed (free-text art direction); `image_style` is the picked category key
 * (photographic/anime/illustration/…) that maps to a style prompt + Ideogram
 * style_type via ContentImageStyles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->boolean('images_enabled')->default(true)->after('article_length');
            $table->string('image_style', 40)->nullable()->after('images_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn(['images_enabled', 'image_style']);
        });
    }
};
