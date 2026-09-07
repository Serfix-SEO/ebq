<?php

namespace App\Jobs\Content;

use App\Models\ContentProductRun;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Strict Product Mode, partial refresh: re-extract a KNOWN set of product
 * URLs (new sitemap products, SimHash-changed catalog pages) without a full
 * discovery pass. Creates a `refresh` run — FinalizeProductCatalogJob never
 * gone-marks on refresh triggers, so a partial URL list can't nuke the rest
 * of the catalog.
 */
class RefreshProductPagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    private const CHUNK = 25;

    /** Partial refreshes stay small — the monthly full run covers the rest. */
    private const MAX_URLS = 200;

    /** @param list<string> $urls */
    public function __construct(public string $websiteId, public array $urls)
    {
        $this->onQueue(Queues::CRAWL);
    }

    public function handle(): void
    {
        $urls = array_slice(array_values(array_unique($this->urls)), 0, self::MAX_URLS);
        if ($urls === []) {
            return;
        }

        // Never race a full run — its finalize would see partial last_seen_at.
        $inFlight = ContentProductRun::query()
            ->where('website_id', $this->websiteId)
            ->whereIn('status', ContentProductRun::IN_FLIGHT)
            ->exists();
        if ($inFlight) {
            return;
        }

        $run = ContentProductRun::query()->create([
            'website_id' => $this->websiteId,
            'trigger' => 'refresh',
            'status' => ContentProductRun::STATUS_EXTRACTING,
            'pages_found' => count($urls),
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

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

        Log::info('content_catalog.refresh_started', ['website_id' => $this->websiteId, 'pages' => count($urls)]);
    }
}
