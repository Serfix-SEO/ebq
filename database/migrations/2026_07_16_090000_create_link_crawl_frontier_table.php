<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier-1.5 targeted-crawler frontier: the URL work-list the link crawler
 * walks to actively discover outbound links from the domains we track
 * (referring domains, competitors, client neighborhoods in domain_metrics),
 * depositing every external link into the permanent link graph via
 * EdgeRecorder. Distinct from `website_pages` (the site-audit frontier, which
 * is crawl_site_id-scoped) — this one is a flat host/URL queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_crawl_frontier', function (Blueprint $table) {
            $table->id();
            $table->string('host', 255)->index();   // registrable-ish host — politeness grouping
            $table->text('url');
            $table->binary('url_hash', 20)->unique(); // sha1(url) — dedup at scale
            $table->unsignedTinyInteger('depth')->default(0); // 0 = seed homepage, 1 = discovered internal
            $table->string('status', 10)->default('pending'); // pending | done | failed | blocked
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_at']);
            $table->index(['host', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_crawl_frontier');
    }
};
