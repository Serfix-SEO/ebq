<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared per-domain report cache (network effect). One row per normalized
 * domain holds the full fetched report payload (DataForSEO backlink profile +
 * history + anchors + referring domains + competitors + Moz DA/PA/Spam). Any
 * user querying a domain reads this shared row; ReportFreshnessGate decides
 * when it is stale (90d default, 30d for a domain owned by a paid user).
 *
 * A handful of headline metrics are denormalized out of the payload so lists
 * and admin views can sort without decoding the JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_report_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('normalized_domain')->unique();

            // Moz headline gauges (nullable — free tier / API miss renders "—").
            $table->unsignedTinyInteger('domain_authority')->nullable();
            $table->unsignedTinyInteger('page_authority')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();

            // DataForSEO headline metrics.
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedBigInteger('backlinks_total')->nullable();

            // Full rendered report payload (all panels).
            $table->longText('payload')->nullable();

            $table->string('status', 32)->default('ready');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_report_snapshots');
    }
};
