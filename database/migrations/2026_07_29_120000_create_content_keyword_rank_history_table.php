<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rank history for Content Tracker keywords. Before this, ContentSerpChecker
 * OVERWROTE content_tracked_keywords.serp_position on every weekly run, so a
 * keyword's climb was unrecoverable — only "today" existed.
 *
 * Keyed by (website, normalized_keyword) rather than the tracked-keyword row so
 * the history SURVIVES the tracker's delete-to-add churn: untracking frees a
 * quota slot and nulls the FK, but re-adding the same keyword picks the series
 * back up instead of starting from zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_keyword_rank_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tracked_keyword_id')->nullable()
                ->constrained('content_tracked_keywords')->nullOnDelete();
            $table->string('normalized_keyword', 200);
            $table->date('checked_on');
            // null position = checked, but the site was not in the top 100.
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('url', 600)->nullable();
            $table->string('source', 12)->default('serp'); // serp (live Google check)
            $table->timestamps();

            $table->unique(['website_id', 'normalized_keyword', 'checked_on', 'source'], 'ckrh_unique');
            $table->index(['tracked_keyword_id', 'checked_on']);
            $table->index(['website_id', 'checked_on']);
        });

        // Seed the first history point from the position each tracked keyword
        // already carries, so existing clients' charts are not empty on day one.
        DB::table('content_tracked_keywords')
            ->whereNotNull('serp_checked_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $now = now();
                $insert = [];
                foreach ($rows as $row) {
                    $insert[] = [
                        'id' => (string) Str::ulid(),
                        'website_id' => $row->website_id,
                        'tracked_keyword_id' => $row->id,
                        'normalized_keyword' => $row->normalized_keyword,
                        'checked_on' => Carbon::parse($row->serp_checked_at)->toDateString(),
                        'position' => $row->serp_position,
                        'url' => $row->serp_url ?? null,
                        'source' => 'serp',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($insert !== []) {
                    DB::table('content_keyword_rank_history')->insertOrIgnore($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_keyword_rank_history');
    }
};
