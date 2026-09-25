<?php

namespace App\Services\Content\Catalog;

use App\Jobs\Content\DiscoverProductPagesJob;
use App\Models\ContentPlan;
use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use Illuminate\Support\Facades\Cache;

/**
 * Strict Product Mode catalog: run orchestration + the read API the planner,
 * writer and UI consume. Run STATE lives on content_product_runs rows; cache
 * is only an atomic once-guard for dispatch (ContentSetupInsights pattern).
 */
class ProductCatalogService
{
    /** URL path fragments that mark a product page (shared with discovery + refresh hooks). */
    public const PRODUCT_PATHS = ['/products/', '/product/', '/p/', '/item/', '/shop/'];

    /**
     * Product-page path heuristic: the fragment must be a segment WITH
     * something after it — the bare collection index is not a product page.
     */
    public static function isProductPath(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        foreach (self::PRODUCT_PATHS as $needle) {
            if (str_contains($path, $needle) && rtrim($path, '/') !== rtrim($needle, '/')) {
                return true;
            }
        }

        // Hyphenated product segments: /product-detail/<slug> (Wix),
        // /product-page/<slug>, /products-detail/<slug>. The literals above are
        // exact segments, so ergospace.ae's 5,000 /product-detail/ pages were
        // invisible and their catalog scan reported "no product pages found"
        // (2026-09-25). Requires something AFTER the segment, so the listing
        // index itself is still not mistaken for a product.
        // ...but NOT /product-listing/<category>, /product-category/<x> and
        // friends: those are category indexes, and scanning them wastes an
        // extraction budget on pages that carry no product of their own.
        return preg_match('#/products?[-_](?!listing|list|category|categories|collection|collections|search|filter)[a-z]+/[^/]+#', $path) === 1;
    }

    /** Does any plan on this website run strict product mode? (refresh hooks gate on this) */
    public function strictPlanFor(string $websiteId): ?ContentPlan
    {
        return ContentPlan::query()
            ->where('website_id', $websiteId)
            ->where('product_mode', ContentPlan::PRODUCT_MODE_STRICT)
            ->first();
    }

    /** A strict plan may start planning once at least one usable product exists. */
    public function readyFor(string $websiteId): bool
    {
        return ContentProduct::query()
            ->where('website_id', $websiteId)
            ->usable()
            ->exists();
    }

    /**
     * A catalog scan is still running for this site.
     *
     * Products are written as the scan streams them in, so `readyFor()` turns
     * true after the FIRST product — seconds into a scan that may take ten
     * minutes. Planning on that sliver is planning on a near-empty catalog:
     * blisfragrance.com (2026-09-25) had 524 products, but the planner fired
     * partway through and produced topics named after fragrances the shop does
     * not sell — exactly what strict mode promises never to do. Use this
     * ALONGSIDE readyFor() wherever "the catalog is complete" is what is meant.
     */
    public function scanInProgress(string $websiteId): bool
    {
        return ContentProductRun::query()
            ->where('website_id', $websiteId)
            ->whereIn('status', ContentProductRun::IN_FLIGHT)
            ->exists();
    }

    public function usableCount(string $websiteId): int
    {
        return ContentProduct::query()->where('website_id', $websiteId)->usable()->count();
    }

    public function latestRun(string $websiteId): ?ContentProductRun
    {
        return ContentProductRun::query()
            ->where('website_id', $websiteId)
            ->latest('id')
            ->first();
    }

    /**
     * Start a scrape run unless one is already in flight. Returns the run
     * (existing in-flight one, or the new one).
     */
    public function startRun(ContentPlan $plan, string $trigger): ContentProductRun
    {
        $websiteId = (string) $plan->website_id;

        $inFlight = ContentProductRun::query()
            ->where('website_id', $websiteId)
            ->whereIn('status', ContentProductRun::IN_FLIGHT)
            ->latest('id')
            ->first();
        if ($inFlight !== null) {
            return $inFlight;
        }

        // Atomic once-guard against double-clicks racing the query above.
        if (! Cache::add('catalog:run-start:'.$websiteId, 1, 300)) {
            return $this->latestRun($websiteId) ?? ContentProductRun::query()->create([
                'website_id' => $websiteId, 'plan_id' => $plan->id,
                'trigger' => $trigger, 'status' => ContentProductRun::STATUS_PENDING,
            ]);
        }

        $run = ContentProductRun::query()->create([
            'website_id' => $websiteId,
            'plan_id' => $plan->id,
            'trigger' => $trigger,
            'status' => ContentProductRun::STATUS_PENDING,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);
        DiscoverProductPagesJob::dispatch($run->id);

        return $run;
    }

    /**
     * Catalog summary for the planner's ideation prompt: category counts, top
     * folded terms, and a sample of product names+urls. Cached on the catalog
     * fingerprint so a refreshed catalog invalidates it.
     *
     * @return array{count:int, categories:array<string,int>, top_terms:list<string>, samples:list<array{name:string,url:string}>}
     */
    public function summaryFor(string $websiteId, int $sampleSize = 40): array
    {
        $base = ContentProduct::query()->where('website_id', $websiteId)->usable();
        $fingerprint = $base->count().'-'.(string) $base->max('updated_at');

        return Cache::remember('catalog:summary:'.$websiteId.':'.md5($fingerprint), 3600, function () use ($websiteId, $sampleSize) {
            $products = ContentProduct::query()
                ->where('website_id', $websiteId)->usable()
                ->get(['name', 'url', 'category', 'terms']);

            $categories = $products->pluck('category')->filter()->countBy()->sortDesc()->take(20)->all();

            $termCounts = [];
            foreach ($products as $p) {
                foreach ((array) $p->terms as $t) {
                    $termCounts[$t] = ($termCounts[$t] ?? 0) + 1;
                }
            }
            arsort($termCounts);

            // Spread samples across categories so the prompt never sees one
            // collection only (mirror of the discovery-budget spread rule).
            $samples = $products->groupBy(fn ($p) => (string) $p->category)
                ->flatMap(fn ($group) => $group->take(max(2, (int) ceil($sampleSize / max(1, $products->pluck('category')->filter()->unique()->count())))))
                ->take($sampleSize)
                ->map(fn ($p) => ['name' => (string) $p->name, 'url' => (string) $p->url])
                ->values()->all();

            return [
                'count' => $products->count(),
                'categories' => $categories,
                'top_terms' => array_slice(array_keys($termCounts), 0, 30),
                'samples' => $samples,
            ];
        });
    }
}
