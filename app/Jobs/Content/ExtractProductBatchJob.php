<?php

namespace App\Jobs\Content;

use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use App\Services\Content\Catalog\ProductExtractor;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\FirecrawlClient;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Strict Product Mode, stage 2: fetch a chunk of product-page URLs and
 * extract normalized product records. Deterministic-first, money-last:
 *
 *   plain fetch (CrawlFetcher: SSRF-guarded, crawler UA)
 *     → JSON-LD / OG extraction (free)
 *     → Firecrawl re-render ONLY when the plain HTML had no product data
 *       (JS-rendered shops), under its own daily budget so catalog work can
 *       never starve the audit crawler's Firecrawl allowance
 *     → still nothing? queue the page for the capped LLM extractor.
 *
 * Idempotent: upserts by (website_id, url_hash); safe on retry/scale-down.
 */
class ExtractProductBatchJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 280;

    /** @param list<string> $urls */
    public function __construct(public string $runId, public array $urls)
    {
        $this->onQueue(Queues::CRAWL);
    }

    public function handle(CrawlFetcher $fetcher, FirecrawlClient $firecrawl, ProductExtractor $extractor): void
    {
        $run = ContentProductRun::query()->find($this->runId);
        if ($run === null || $run->status !== ContentProductRun::STATUS_EXTRACTING) {
            return; // run failed/cancelled elsewhere — stop quietly
        }
        $run->beat();

        $extracted = 0;
        $failed = 0;
        $llmCandidates = [];

        foreach ($this->urls as $url) {
            try {
                $result = $fetcher->fetch($url, timeout: 20);
                $html = (string) ($result['body'] ?? '');
                $record = $html !== '' ? $extractor->extract($html, $url) : null;

                // JS-rendered shop: the plain HTML carried nothing usable.
                if ($record === null && $this->firecrawlAllowed($run->website_id) && $firecrawl->enabled()) {
                    $rendered = $firecrawl->html($url);
                    if (is_string($rendered) && $rendered !== '') {
                        $record = $extractor->extract($rendered, $url);
                    }
                }

                if ($record === null) {
                    $llmCandidates[] = $url;

                    continue;
                }

                $this->store($run, $url, $record);
                $extracted++;
            } catch (\Throwable $e) {
                $failed++;
                Log::debug('content_catalog.extract_error', ['url' => $url, 'error' => mb_substr($e->getMessage(), 0, 150)]);
            }
        }

        // LLM fallback, capped per run — never let a schema-less shop turn
        // into an unbounded LLM bill.
        if ($llmCandidates !== []) {
            $cap = ContentAutopilotConfig::productLlmExtractCap();
            $used = (int) Cache::get('catalog:llm-used:'.$run->id, 0);
            $take = max(0, min(count($llmCandidates), $cap - $used));
            if ($take > 0) {
                Cache::put('catalog:llm-used:'.$run->id, $used + $take, 86400);
                ExtractProductLlmJob::dispatch($run->id, array_slice($llmCandidates, 0, $take));
            }
            $failed += count($llmCandidates) - $take;
        }

        if ($extracted > 0 || $failed > 0) {
            ContentProductRun::query()->whereKey($run->id)->update([
                'products_extracted' => \DB::raw('products_extracted + '.$extracted),
                'pages_failed' => \DB::raw('pages_failed + '.$failed),
                'budget_used' => \DB::raw('budget_used + '.count($this->urls)),
                'heartbeat_at' => now(),
            ]);
        }
    }

    /** Own Firecrawl budget key — separate from the audit crawler's. */
    private function firecrawlAllowed(string $websiteId): bool
    {
        $key = 'catalog:fc-budget:'.$websiteId.':'.now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);
        if ($used >= ContentAutopilotConfig::firecrawlProductDailyBudget()) {
            return false;
        }
        Cache::put($key, $used + 1, 86400 * 2);

        return true;
    }

    /** @param array<string, mixed> $record */
    private function store(ContentProductRun $run, string $url, array $record): void
    {
        $terms = $record['terms'];
        unset($record['terms']);

        $product = ContentProduct::query()->firstOrNew(
            ['website_id' => $run->website_id, 'url_hash' => hash('sha256', $url)],
        );
        $product->fill($record + [
            'url' => mb_substr($url, 0, 700),
            'terms' => $terms,
            'status' => ContentProduct::STATUS_ACTIVE,
            'content_hash' => sha1(json_encode([$record['name'], $record['description'] ?? '', $record['price_cents'] ?? null, $record['availability']])),
            'last_seen_at' => now(),
        ]);
        $product->first_seen_at ??= now();
        // is_excluded survives re-scrapes untouched (client's choice is durable).
        $product->save();
    }
}
