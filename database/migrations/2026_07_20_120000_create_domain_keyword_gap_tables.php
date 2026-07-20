<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DataForSEO Labs keyword-gap harvest (see DATAFORSEO_KEYWORD_GAP_PLAN.md):
 *  - keyword_metrics gains difficulty + intent (DFS Labs facts).
 *  - domain_keyword_rankings: the many-to-many link between domains and the
 *    keywords they rank for (one row per domain+keyword+country) — shared asset,
 *    lets us look up a domain's keywords OR every domain ranking for a keyword.
 *  - domain_keyword_harvest: the per-domain volume cursor that drives cheap,
 *    dupe-free month-over-month accumulation (+1,000/competitor/month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_metrics', function (Blueprint $table) {
            $table->unsignedTinyInteger('keyword_difficulty')->nullable()->after('competition');
            $table->string('search_intent', 20)->nullable()->after('keyword_difficulty');
        });

        Schema::create('domain_keyword_rankings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain');                 // bare host, lowercased
            $table->string('keyword_hash', 64);       // KeywordMetric::hashKeyword()
            $table->string('keyword');
            $table->string('country', 16)->default('global');
            $table->unsignedInteger('rank_absolute')->nullable();
            $table->string('se_type', 16)->nullable();   // organic / paid / featured_snippet…
            $table->text('page_url')->nullable();
            $table->double('etv')->nullable();           // est. monthly traffic this kw → this domain
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedInteger('previous_rank')->nullable();
            $table->boolean('is_new')->default(false);
            $table->boolean('is_up')->default(false);
            $table->boolean('is_down')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'keyword_hash', 'country'], 'dkr_domain_kw_country_unique');
            $table->index(['keyword_hash', 'country']); // keyword → domains
            $table->index(['domain', 'country']);        // domain → keywords
        });

        Schema::create('domain_keyword_harvest', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain');
            $table->string('country', 16)->default('global');
            $table->unsignedInteger('volume_cursor')->nullable(); // next pull: search_volume < this
            $table->unsignedInteger('keywords_fetched')->default(0);
            $table->boolean('exhausted')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'country']);
            $table->index(['exhausted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_keyword_harvest');
        Schema::dropIfExists('domain_keyword_rankings');
        Schema::table('keyword_metrics', function (Blueprint $table) {
            $table->dropColumn(['keyword_difficulty', 'search_intent']);
        });
    }
};
