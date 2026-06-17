<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WebsiteSitemap;
use App\Services\Google\SearchConsoleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pull the list of sitemaps Google Search Console knows about for a website
 * and store them locally (source = gsc). Manually-added sitemaps are left
 * untouched. Read-only against GSC — uses the webmasters.readonly scope.
 */
class SyncSitemaps implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $websiteId,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function handle(SearchConsoleService $service): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::findOrFail($this->websiteId);

        if ($website->isFrozen()) {
            Log::info("SyncSitemaps: skipping frozen website {$this->websiteId}");
            return;
        }

        if ($website->gsc_site_url === null || $website->gsc_site_url === '') {
            return;
        }

        $account = $website->gscAccountResolved();
        if (! $account) {
            Log::warning("SyncSitemaps: No Google account for website {$this->websiteId}");
            return;
        }

        try {
            $sitemaps = $service->listSitemaps($account, $website->gsc_site_url);
        } catch (\Throwable $e) {
            Log::warning("SyncSitemaps: GSC fetch failed for website {$this->websiteId}: {$e->getMessage()}");
            return;
        }

        $now = now();

        foreach ($sitemaps as $row) {
            if ($row['path'] === '') {
                continue;
            }

            WebsiteSitemap::updateOrCreate(
                ['website_id' => $this->websiteId, 'path' => $row['path']],
                [
                    'source' => WebsiteSitemap::SOURCE_GSC,
                    'type' => $row['type'],
                    'is_pending' => $row['is_pending'],
                    'is_sitemaps_index' => $row['is_sitemaps_index'],
                    'errors' => $row['errors'],
                    'warnings' => $row['warnings'],
                    'submitted_urls' => $row['submitted_urls'],
                    'indexed_urls' => $row['indexed_urls'],
                    'last_submitted_at' => $this->parseTimestamp($row['last_submitted']),
                    'last_downloaded_at' => $this->parseTimestamp($row['last_downloaded']),
                    'last_synced_at' => $now,
                ]
            );
        }
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
