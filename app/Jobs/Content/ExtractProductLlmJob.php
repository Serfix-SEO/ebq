<?php

namespace App\Jobs\Content;

use App\Models\ContentProduct;
use App\Models\ContentProductRun;
use App\Services\Content\Catalog\ProductExtractor;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Strict Product Mode: last-resort extraction for shops without JSON-LD/OG —
 * one cheap LLM call per page over the visible text. Runs on the content
 * queue (LLM latency stays off crawl workers); the per-run cap is enforced by
 * the dispatcher (ExtractProductBatchJob), so this job just processes what it
 * was given. Money is metered per call via the content pipeline's meter
 * conventions (unmetered label — internal product cost, never client-billed
 * dashboards).
 */
class ExtractProductLlmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param list<string> $urls */
    public function __construct(public string $runId, public array $urls)
    {
        $this->onQueue(Queues::CONTENT);
        $this->onConnection('redis-long');
    }

    public function handle(CrawlFetcher $fetcher, ProductExtractor $extractor): void
    {
        $run = ContentProductRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $model = ContentAutopilotConfig::modelFor('ideate'); // cheap tier
        $llm = LlmClientFactory::make($model['provider']);
        if (! $llm->isAvailable()) {
            return;
        }

        $extracted = 0;
        foreach ($this->urls as $url) {
            try {
                $result = $fetcher->fetch($url, timeout: 20);
                $text = trim(mb_substr(strip_tags((string) ($result['body'] ?? '')), 0, 6000));
                if ($text === '') {
                    continue;
                }

                $parsed = $llm->completeJson([
                    ['role' => 'system', 'content' => 'Extract the single product this store page sells. Respond ONLY with JSON: '
                        .'{"is_product": bool, "name": string, "description": string (<=300 chars), "brand": string|null, '
                        .'"price": number|null, "currency": string|null, "availability": "in_stock"|"out_of_stock"|"preorder"|"unknown", "category": string|null}. '
                        .'is_product=false when the page is not a single-product page (collection, blog, policy).'],
                    ['role' => 'user', 'content' => "URL: {$url}\n\nPAGE TEXT:\n".$text],
                ], [
                    'temperature' => 0,
                    'max_tokens' => 500,
                    'timeout' => 30,
                    '__source' => 'content_catalog.llm_extract',
                    '__unmetered' => true,
                ] + (empty($model['model']) ? [] : ['model' => $model['model']]));

                if (! is_array($parsed) || ! ($parsed['is_product'] ?? false) || trim((string) ($parsed['name'] ?? '')) === '') {
                    continue;
                }

                $record = $extractor->record(
                    name: mb_substr(trim((string) $parsed['name']), 0, 300),
                    description: ($d = trim((string) ($parsed['description'] ?? ''))) !== '' ? $d : null,
                    brand: ($b = trim((string) ($parsed['brand'] ?? ''))) !== '' ? mb_substr($b, 0, 160) : null,
                    priceCents: is_numeric($parsed['price'] ?? null) ? (int) round(((float) $parsed['price']) * 100) : null,
                    currency: ($c = trim((string) ($parsed['currency'] ?? ''))) !== '' ? mb_substr($c, 0, 8) : null,
                    availability: in_array($parsed['availability'] ?? '', ['in_stock', 'out_of_stock', 'preorder'], true) ? $parsed['availability'] : 'unknown',
                    imageUrl: null,
                    category: ($cat = trim((string) ($parsed['category'] ?? ''))) !== '' ? mb_substr($cat, 0, 200) : null,
                    sku: null,
                    canonicalUrl: null,
                    source: 'llm',
                );

                $product = ContentProduct::query()->firstOrNew(
                    ['website_id' => $run->website_id, 'url_hash' => hash('sha256', $url)],
                );
                $terms = $record['terms'];
                unset($record['terms']);
                $product->fill($record + [
                    'url' => mb_substr($url, 0, 700),
                    'terms' => $terms,
                    'status' => ContentProduct::STATUS_ACTIVE,
                    'last_seen_at' => now(),
                ]);
                $product->first_seen_at ??= now();
                $product->save();
                $extracted++;
            } catch (\Throwable) {
                // best-effort; the page simply stays uncatalogued
            }
        }

        if ($extracted > 0) {
            ContentProductRun::query()->whereKey($run->id)->update([
                'products_extracted' => DB::raw('products_extracted + '.$extracted),
                'heartbeat_at' => now(),
            ]);
        }
    }
}
