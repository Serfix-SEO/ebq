<?php

namespace App\Jobs\Content;

use App\Models\ContentProductRun;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Strict Product Mode, stage 1: find the client's product-page URLs and fan
 * out extraction. Sources (cheapest evidence first):
 *
 *   1. crawled pages whose seo_signals.schema_types contains "Product"
 *      (relevance already proven);
 *   2. sitemap-sourced URLs matching product paths (Shopify's
 *      /sitemap_products_1.xml is already ingested by the crawler);
 *   3. path heuristics on the remaining crawl inventory.
 *
 * Budget: product_crawl_page_budget (own budget — the content-only crawl cap
 * of ~200 pages must NOT clamp catalogs). When over budget, sample ACROSS
 * categories (path prefixes) rather than truncating alphabetically, so a
 * 5k-SKU store still yields a representative catalog.
 */
class DiscoverProductPagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    private const PRODUCT_PATHS = ['/products/', '/product/', '/p/', '/item/', '/shop/'];

    private const CHUNK = 25;

    public function __construct(public string $runId)
    {
        $this->onQueue(Queues::CRAWL);
    }

    public function handle(): void
    {
        $run = ContentProductRun::query()->find($this->runId);
        if ($run === null || $run->status !== ContentProductRun::STATUS_PENDING) {
            return;
        }
        $run->forceFill(['status' => ContentProductRun::STATUS_DISCOVERING, 'heartbeat_at' => now()])->save();

        $website = Website::query()->find($run->website_id);
        if ($website === null) {
            $run->forceFill(['status' => ContentProductRun::STATUS_FAILED, 'error' => 'website_gone', 'finished_at' => now()])->save();

            return;
        }

        $urls = $this->discover($website);
        $budget = ContentAutopilotConfig::productCrawlPageBudget();
        if (count($urls) > $budget) {
            $urls = $this->spreadAcrossCategories($urls, $budget);
        }

        if ($urls === []) {
            $run->forceFill([
                'status' => ContentProductRun::STATUS_FAILED,
                'error' => 'no_product_pages_found',
                'finished_at' => now(),
            ])->save();
            Log::info('content_catalog.no_product_pages', ['website_id' => $website->id]);

            return;
        }

        $run->forceFill([
            'status' => ContentProductRun::STATUS_EXTRACTING,
            'pages_found' => count($urls),
            'heartbeat_at' => now(),
        ])->save();

        $runId = $run->id;
        $jobs = collect($urls)->chunk(self::CHUNK)
            ->map(fn ($chunk) => new ExtractProductBatchJob($runId, $chunk->values()->all()))
            ->all();

        Bus::batch($jobs)
            ->allowFailures()
            ->onQueue(Queues::CRAWL)
            ->finally(function () use ($runId) {
                FinalizeProductCatalogJob::dispatch($runId);
            })
            ->dispatch();

        Log::info('content_catalog.discovery', ['website_id' => $website->id, 'pages' => count($urls)]);
    }

    /** @return list<string> */
    private function discover(Website $website): array
    {
        $crawlSiteId = $website->crawl_site_id;
        $urls = [];

        if ($crawlSiteId !== null) {
            $pages = WebsitePage::query()
                ->where('crawl_site_id', $crawlSiteId)
                ->where('http_status', 200)
                ->get(['url', 'seo_signals', 'source_sitemap']);

            foreach ($pages as $page) {
                $url = (string) $page->url;
                $types = array_map('strtolower', (array) data_get($page->seo_signals, 'schema_types', []));
                $isProductSchema = in_array('product', $types, true);
                $isProductPath = $this->looksLikeProductPath($url);
                if ($isProductSchema || $isProductPath) {
                    // schema-flagged first (sort key 0), path-only second
                    $urls[$url] = $isProductSchema ? 0 : 1;
                }
            }
        }

        asort($urls);

        return array_keys($urls);
    }

    private function looksLikeProductPath(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        foreach (self::PRODUCT_PATHS as $needle) {
            // "/products/" must be a segment with something after it — the
            // bare collection index is not a product page.
            if (str_contains($path, $needle) && rtrim($path, '/') !== rtrim($needle, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sample across path-prefix "categories" so budget cuts stay
     * representative instead of alphabetical.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function spreadAcrossCategories(array $urls, int $budget): array
    {
        $groups = [];
        foreach ($urls as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
            $segments = array_values(array_filter(explode('/', $path)));
            // Group by the first two segments ("collections/skincare",
            // "products" alone for flat shops).
            $key = implode('/', array_slice($segments, 0, 2));
            $groups[$key][] = $url;
        }

        $out = [];
        while (count($out) < $budget && $groups !== []) {
            foreach ($groups as $key => &$group) {
                if ($group === []) {
                    unset($groups[$key]);

                    continue;
                }
                $out[] = array_shift($group);
                if (count($out) >= $budget) {
                    break;
                }
            }
            unset($group);
        }

        return $out;
    }

    public function failed(\Throwable $e): void
    {
        ContentProductRun::query()->whereKey($this->runId)->update([
            'status' => ContentProductRun::STATUS_FAILED,
            'error' => mb_substr('discovery: '.$e->getMessage(), 0, 120),
            'finished_at' => now(),
        ]);
    }
}
