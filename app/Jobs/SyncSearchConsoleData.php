<?php

namespace App\Jobs;

use App\Models\KeywordMetric;
use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Google\SearchConsoleService;
use App\Services\ReportCache;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncSearchConsoleData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(
        public string $websiteId,
        public int $days = 30,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
    }

    public function handle(SearchConsoleService $service): void
    {
        app(\App\Support\ShardContext::class)->forWebsite((string) $this->websiteId);
        $website = Website::findOrFail($this->websiteId);
        // Plan-limit freeze: when the website is past the owning user's
        // plan limit, skip the GSC fetch. Avoids burning Google quota
        // on sites the user can't actually see in EBQ until they upgrade.
        if ($website->isFrozen()) {
            Log::info("SyncSearchConsoleData: skipping frozen website {$this->websiteId}");
            return;
        }
        // No GSC source configured (GA-only or PageSpeed-only site): nothing to sync.
        if ($website->gsc_site_url === null || $website->gsc_site_url === '') {
            return;
        }
        $account = $website->gscAccountResolved();

        if (! $account) {
            Log::warning("SyncSearchConsoleData: No Google account for website {$this->websiteId}");
            return;
        }

        $end = Carbon::now();
        $cursor = Carbon::now()->subDays($this->days);

        while ($cursor->lt($end)) {
            $windowEnd = $cursor->copy()->addDays(6)->min($end);

            $rows = $service->fetchSearchAnalytics(
                $account,
                $website->gsc_site_url,
                $cursor->toDateString(),
                $windowEnd->toDateString()
            );

            $this->upsertRows($rows);

            $cursor->addDays(7);
        }

        Website::whereKey($this->websiteId)->update(['last_search_console_sync_at' => now()]);

        // Fresh GSC rows landed: bump the per-website data version so HQ
        // Overview + Dashboard caches refresh on the next read.
        ReportCache::flushWebsite($this->websiteId);

        $this->queueKeywordMetricsRefresh();
    }

    /**
     * After a GSC upsert, queue Keywords Everywhere lookups for queries that
     * cleared the 100-impression gate in the sync window and aren't already
     * fresh in the keyword_metrics cache. Budget-safe (no lookups if the API
     * key is unconfigured — the job no-ops via the client).
     */
    private function queueKeywordMetricsRefresh(): void
    {
        $since = Carbon::now()->subDays($this->days)->toDateString();

        $candidates = SearchConsoleData::query()
            ->where('website_id', $this->websiteId)
            ->whereDate('date', '>=', $since)
            ->where('query', '!=', '')
            ->selectRaw('query, SUM(impressions) as total_impressions')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ?', [100])
            ->orderByDesc('total_impressions')
            ->limit(500)
            ->pluck('query')
            ->all();

        if ($candidates === []) {
            return;
        }

        $freshHashes = array_flip(KeywordMetric::query()
            ->whereIn('keyword_hash', array_map(fn ($k) => KeywordMetric::hashKeyword((string) $k), $candidates))
            ->where('country', 'global')
            ->where('expires_at', '>', now())
            ->pluck('keyword_hash')
            ->all());

        $needed = [];
        foreach ($candidates as $kw) {
            if (! isset($freshHashes[KeywordMetric::hashKeyword((string) $kw)])) {
                $needed[] = (string) $kw;
            }
        }

        if ($needed === []) {
            return;
        }

        foreach (array_chunk($needed, 100) as $chunk) {
            FetchKeywordMetricsJob::dispatch(array_values($chunk), 'global');
        }
    }

    private function upsertRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $prepared = array_map(function (array $row) {
                $row['website_id'] = $this->websiteId;
                $row['page'] = UrlNormalizer::normalize($row['page']);
                $row['country'] = $row['country'] ?? '';
                $row['device'] = $row['device'] ?? '';
                return $row;
            }, $chunk);

            DB::table('search_console_data')->upsert(ulid_rows($prepared),
                ['website_id', 'date', 'query', 'page', 'country', 'device'],
                ['clicks', 'impressions', 'position', 'ctr', 'updated_at']
            );
        }
    }
}
