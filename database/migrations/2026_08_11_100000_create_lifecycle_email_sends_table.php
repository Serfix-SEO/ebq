<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log of lifecycle (onboarding-funnel) emails: which user, which segment
 * (1 articles-flowing feedback ask · 2 never-added-website activation ·
 * 3 strategy-unfinished nudge · 4 not-connected-for-publishing nudge),
 * which stage (initial | followup), and whether the user later left the
 * segment (converted_at). The unique (user, segment, stage) key IS the
 * idempotency mechanism — a double-send is structurally impossible; failed
 * rows are retried via updateOrCreate on the same natural key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_email_sends', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // The website the CTA deep-links to (segments 3/4), snapshot.
            $table->foreignUlid('website_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('segment');          // 1..4
            $table->string('stage', 16);                     // initial | followup
            $table->string('to_email');
            $table->string('subject', 512);                  // rendered subject snapshot
            $table->string('status', 16)->default('sent');   // sent | failed
            $table->timestamp('converted_at')->nullable();   // user left this segment after send
            $table->json('meta')->nullable();                // locale, cta_url, website_domain, error
            $table->timestamps();

            $table->unique(['user_id', 'segment', 'stage']);
            $table->index(['segment', 'stage', 'status', 'created_at']);
            $table->index('created_at');
        });

        // Seed the Setting rows the feature reads. Mandatory: Setting::get()
        // rememberForever-caches the DEFAULT when the row is absent, so a later
        // admin toggle would fight a stale forever-cached value.
        $now = now();
        DB::table('settings')->insertOrIgnore(collect([
            'lifecycle.enabled' => '1',
            'lifecycle.segment.1.enabled' => '1',
            'lifecycle.segment.2.enabled' => '1',
            'lifecycle.segment.3.enabled' => '1',
            'lifecycle.segment.4.enabled' => '1',
            'lifecycle.daily_cap' => '50',
            'lifecycle.min_account_age_days' => '3',
        ])->map(fn ($value, $key) => [
            'key' => $key,
            'value' => json_encode($value),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_email_sends');
    }
};
