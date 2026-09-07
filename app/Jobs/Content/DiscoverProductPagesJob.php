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

        // The live sitemap FIRST — the content-only crawl caps at ~200 pages,
        // so big catalogs never fully enter crawl inventory (bellavest
        // 2026-09-07: 108 sitemap products, 32 of them never crawled → a 72-
        // product catalog). Extraction fetches pages itself, so discovery has
        // no reason to depend on what the crawler happened to walk.
        foreach ($this->sitemapProductUrls($website) as $url) {
            $urls[$url] = 1; // path-heuristic tier; schema-flagged crawl rows below outrank
        }

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

    /**
     * Product-path URLs straight from the site's sitemaps: registered ones,
     * robots.txt `Sitemap:` lines, and the /sitemap.xml convention as a
     * fallback. Fail-open — a broken sitemap must never fail discovery.
     *
     * @return list<string>
     */
    private function sitemapProductUrls(Website $website): array
    {
        try {
            $domain = trim((string) $website->normalized_domain);
            if ($domain === '') {
                return [];
            }
            $candidates = $website->sitemaps()->pluck('path')
                ->map(static fn ($p) => (string) $p)->filter()->values()->all();

            $robots = app(\App\Services\Crawler\CrawlFetcher::class)
                ->fetch('https://'.$domain.'/robots.txt', timeout: 10);
            if (preg_match_all('/^\s*Sitemap:\s*(\S+)/im', (string) ($robots['body'] ?? ''), $m)) {
                $candidates = array_merge($candidates, $m[1]);
            }
            if ($candidates === []) {
                $candidates[] = 'https://'.$domain.'/sitemap.xml';
            }
            $candidates = $this->expandIndexesProductFirst(array_slice(array_values(array_unique($candidates)), 0, 5));

            $urls = [];
            foreach (app(\App\Support\Crawler\SitemapUrlExtractor::class)->extract($candidates) as $entry) {
                $url = trim((string) $entry['loc']);
                if ($url === ''
                    || ! \App\Support\DomainName::urlBelongsToSite($url, $domain)
                    || ! $this->looksLikeProductPath($url)) {
                    continue;
                }
                $urls[$url] = true;
            }

            return array_keys($urls);
        } catch (\Throwable $e) {
            Log::debug('content_catalog.sitemap_discovery_failed', ['website_id' => $website->id, 'error' => mb_substr($e->getMessage(), 0, 150)]);

            return [];
        }
    }

    /**
     * Expand top-level sitemap INDEXES ourselves, product-named children
     * first. Shopify-style indexes list an "agentic discovery" index with
     * hundreds of children BEFORE the products sitemap, and the shared
     * extractor's global fetch cap exhausted on them without ever reaching
     * the products child (carmenperfumes 2026-09-08: 87 sitemap products,
     * the blind index walk surfaced 4). Non-index candidates pass through.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function expandIndexesProductFirst(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            try {
                $res = app(\App\Services\Crawler\CrawlFetcher::class)->fetch($candidate, [], 20);
                $body = (string) ($res['body'] ?? '');
                if (($res['ok'] ?? false) && stripos($body, '<sitemapindex') !== false
                    && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $m)) {
                    $children = array_map(static fn ($u) => html_entity_decode(trim($u)), $m[1]);
                    usort($children, static fn ($a, $b) => (int) (stripos($b, 'product') !== false) <=> (int) (stripos($a, 'product') !== false));
                    $out = array_merge($out, array_slice($children, 0, 20));

                    continue;
                }
            } catch (\Throwable) {
                // fall through — keep the candidate as-is
            }
            $out[] = $candidate;
        }

        return array_values(array_unique($out));
    }

    private function looksLikeProductPath(string $url): bool
    {
        return \App\Services\Content\Catalog\ProductCatalogService::isProductPath($url);
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
