<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier-1 link graph — passive edge harvesting from pages we already fetch
 * (site-audit crawls, report enrichment). Append-only facts designed to port
 * to ClickHouse unchanged if/when a dedicated crawler ships:
 *
 *  - BIGINT id dictionaries (`domains`, `urls`) — edges reference ids, never
 *    strings; nullable url ids make page-level granularity purely additive.
 *  - `source` tag per edge (own_crawl | cc_wat | enrichment) so future data
 *    sources mix cleanly.
 *  - first_seen/last_seen — edges are never deleted (client churn included).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // registrable domain (eTLD+1)
        });

        Schema::create('link_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('link_domains')->cascadeOnDelete();
            $table->text('path');
            $table->binary('path_hash', 20); // sha1(path) — text is too long to index
            $table->unique(['domain_id', 'path_hash']);
        });

        Schema::create('link_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_domain_id')->constrained('link_domains')->cascadeOnDelete();
            $table->foreignId('to_domain_id')->constrained('link_domains')->cascadeOnDelete();
            $table->foreignId('from_url_id')->nullable()->constrained('link_urls')->nullOnDelete();
            $table->foreignId('to_url_id')->nullable()->constrained('link_urls')->nullOnDelete();
            $table->boolean('dofollow')->default(true);
            $table->string('anchor_class', 12)->nullable(); // naked | generic | text | empty
            $table->string('source', 12); // own_crawl | cc_wat | enrichment
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['from_domain_id', 'to_domain_id', 'source'], 'link_edges_from_to_source');
            $table->index('to_domain_id'); // "who links to X" — the money query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_edges');
        Schema::dropIfExists('link_urls');
        Schema::dropIfExists('link_domains');
    }
};
