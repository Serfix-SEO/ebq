<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AEO phase 2 — the entity behind the article, and the answer-readiness score.
 *
 * AI answers cite people and organisations, not domains. Every JSON-LD `author`
 * we emit today is `Organization: <the bare domain>` (ArticleReview::seoKit,
 * the PHP kit template), which is the weakest possible claim to expertise. A
 * real named author with credentials and profile links is the single biggest
 * E-E-A-T lever available to us, and it is also what activates the
 * `author_box` toggle that has sat dead in ContentPlan since it was added.
 *
 * The AEO score is stored SEPARATELY from seo_score on purpose. Folding these
 * checks into ContentSeoScorer would add ~24 points of new weight to a rubric
 * the publish floor gates on (`ContentArticleProducer:322`), so every site's
 * scores would drop overnight and working articles would start failing. Two
 * numbers, shown side by side, is also what the original design called for
 * (docs/architecture/30-simulation.md:281 — "don't merge CFD and AEO").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_authors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 160)->nullable();          // "Head Perfumer", "Founder"
            $table->text('bio')->nullable();                  // capped in the form, not here
            $table->string('credentials', 300)->nullable();    // "IFRA certified, 12 years"
            $table->string('avatar_url', 600)->nullable();
            // Profile URLs that prove the person exists elsewhere — schema.org
            // sameAs, and the part a model can corroborate.
            $table->json('same_as')->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index(['website_id', 'is_default']);
        });

        Schema::table('content_plans', function (Blueprint $table): void {
            // The publishing organisation. `business_description` is prose for
            // the writer; these are the facts a knowledge graph can hold.
            $table->string('org_legal_name')->nullable()->after('business_description');
            $table->string('org_logo_url', 600)->nullable()->after('org_legal_name');
            $table->json('org_same_as')->nullable()->after('org_logo_url');
        });

        Schema::table('content_articles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('aeo_score')->nullable()->after('seo_issues');
            $table->json('aeo_issues')->nullable()->after('aeo_score');
            // The graph as audited, so a republish ships exactly what we
            // scored rather than rebuilding it from a later version.
            $table->json('schema_json')->nullable()->after('aeo_issues');
        });
    }

    public function down(): void
    {
        Schema::table('content_articles', function (Blueprint $table): void {
            $table->dropColumn(['aeo_score', 'aeo_issues', 'schema_json']);
        });
        Schema::table('content_plans', function (Blueprint $table): void {
            $table->dropColumn(['org_legal_name', 'org_logo_url', 'org_same_as']);
        });
        Schema::dropIfExists('content_authors');
    }
};
