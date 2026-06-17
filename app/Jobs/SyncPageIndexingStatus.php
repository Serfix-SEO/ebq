<?php

namespace App\Jobs;

use App\Models\PageIndexingStatus;
use App\Models\Website;
use App\Services\Google\GoogleClientFactory;
use App\Services\PageAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPageIndexingStatus implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $websiteId,
        public int $maxPages = 25,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function handle(GoogleClientFactory $googleClientFactory): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::query()->find($this->websiteId);
        if (! $website || $website->gsc_site_url === null || $website->gsc_site_url === '') {
            return;
        }

        $account = $website->gscAccountResolved();
        if (! $account) {
            Log::warning("SyncPageIndexingStatus: No Google account for website {$this->websiteId}");

            return;
        }

        try {
            $client = $googleClientFactory->make($account);
            $accessToken = (string) ($client->getAccessToken()['access_token'] ?? '');
            if ($accessToken === '') {
                Log::warning("SyncPageIndexingStatus: Missing Google access token for website {$this->websiteId}");

                return;
            }
        } catch (\Throwable $e) {
            Log::warning("SyncPageIndexingStatus: Token build failed for website {$this->websiteId}: {$e->getMessage()}");

            return;
        }

        $pages = $website->searchConsoleData()
            ->selectRaw('page, SUM(clicks) as total_clicks, MAX(date) as last_seen_date')
            ->where('page', '!=', '')
            ->whereDate('date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('page')
            ->orderByDesc('total_clicks')
            ->limit(max(1, $this->maxPages))
            ->pluck('page');

        if ($pages->isEmpty()) {
            return;
        }

        foreach ($pages as $pageUrl) {
            try {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                        'inspectionUrl' => $pageUrl,
                        'siteUrl' => $website->gsc_site_url,
                        'languageCode' => 'en-US',
                    ]);

                if (! $response->successful()) {
                    Log::warning("SyncPageIndexingStatus: Google API error for website {$this->websiteId} page {$pageUrl}", [
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);

                    continue;
                }

                $indexStatus = (array) data_get($response->json(), 'inspectionResult.indexStatusResult', []);
                $lastCrawlAt = data_get($indexStatus, 'lastCrawlTime');

                $status = PageIndexingStatus::query()->updateOrCreate(
                    [
                        'website_id' => $this->websiteId,
                        'page' => $pageUrl,
                    ],
                    [
                        'last_google_status_checked_at' => now(),
                        'google_verdict' => data_get($indexStatus, 'verdict'),
                        'google_coverage_state' => data_get($indexStatus, 'coverageState'),
                        'google_indexing_state' => data_get($indexStatus, 'indexingState'),
                        'google_last_crawl_at' => is_string($lastCrawlAt) ? Carbon::parse($lastCrawlAt) : null,
                        'google_status_payload' => $indexStatus,
                    ]
                );

                if ($status->wasRecentlyCreated) {
                    try {
                        app(PageAuditService::class)->audit($this->websiteId, $pageUrl);
                    } catch (\Throwable $e) {
                        Log::warning("SyncPageIndexingStatus: Auto-audit failed for website {$this->websiteId} page {$pageUrl}: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("SyncPageIndexingStatus: Failed for website {$this->websiteId} page {$pageUrl}: {$e->getMessage()}");
            }
        }
    }
}
