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

        return false;
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
