<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strict Product Mode (2026-09) — the client's real product catalog, scraped
 * from their own store, so e-commerce articles are grounded in products they
 * actually sell (complaint: "the system writes about random products").
 *
 * content_products      — one row per product URL per website (variant-deduped).
 * content_product_runs  — scrape-run progress; the client-facing progress
 *                         screen reads THIS row, never cache (stranded-screen
 *                         precedent, ContentSetupInsights 2026-07-24).
 * content_topic_product — which products a planned topic features/mentions.
 * content_plans         — product_mode: null = undecided (MUST behave exactly
 *                         like today), 'normal', 'strict'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->string('url', 700);
            $table->string('url_hash', 64); // sha256(url) — unique key material (url too long to index raw)
            $table->string('canonical_url', 700)->nullable();
            $table->string('name', 300);
            $table->text('description')->nullable();
            $table->string('brand', 160)->nullable();
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('availability', 16)->default('unknown'); // in_stock|out_of_stock|preorder|unknown
            $table->string('image_url', 700)->nullable();
            $table->string('category', 200)->nullable();
            $table->string('sku', 120)->nullable();
            // UnicodeText-folded match tokens from name+category — precomputed
            // so topic↔product matching never re-folds per comparison.
            $table->json('terms')->nullable();
            $table->string('source', 12)->default('jsonld'); // jsonld|og|llm|shopify_api
            $table->string('content_hash', 40)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_excluded')->default(false); // client's own opt-out (Settings → Products)
            $table->string('status', 8)->default('active'); // active|gone
            $table->timestamps();

            $table->unique(['website_id', 'url_hash']);
            $table->index(['website_id', 'status', 'is_excluded']);
        });

        Schema::create('content_product_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->nullable();
            $table->string('trigger', 12); // onboarding|settings|banner|refresh|admin
            $table->string('status', 12)->default('pending'); // pending|discovering|extracting|finalizing|ready|failed
            $table->unsignedInteger('pages_found')->default(0);
            $table->unsignedInteger('products_extracted')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->unsignedInteger('budget_used')->default(0);
            $table->string('error', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });

        Schema::create('content_topic_product', function (Blueprint $table) {
            $table->foreignUlid('content_topic_id')->constrained('content_topics')->cascadeOnDelete();
            $table->foreignUlid('content_product_id')->constrained('content_products')->cascadeOnDelete();
            $table->string('role', 10)->default('mentioned'); // featured|mentioned
            $table->primary(['content_topic_id', 'content_product_id']);
        });

        Schema::table('content_plans', function (Blueprint $table) {
            $table->string('product_mode', 8)->nullable()->after('site_type_source');
            $table->timestamp('product_mode_decided_at')->nullable()->after('product_mode');
        });
    }

    public function down(): void
    {
        Schema::table('content_plans', function (Blueprint $table) {
            $table->dropColumn(['product_mode', 'product_mode_decided_at']);
        });
        Schema::dropIfExists('content_topic_product');
        Schema::dropIfExists('content_product_runs');
        Schema::dropIfExists('content_products');
    }
};
