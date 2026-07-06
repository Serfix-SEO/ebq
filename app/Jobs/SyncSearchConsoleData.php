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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncSearchConsoleData implements ShouldQueue
{
    use Queueable;

    // High-volume GSC accounts (tens of thousands of query/page/country/device rows
    // per week) can take well over 600s to page through + upsert a 30-day window.
    // 3600s mirrors AnalyzeSiteJob's large-site ceiling; redis-long's retry_after
    // (3900s, config/queue.php) stays above it so a still-running sync is never
    // re-reserved mid-run.
    public int $timeout = 3600;
    public int $tries = 2;

    // Wait between attempts so a contended DB or a transient Google API hiccup has
    // time to settle before the retry.
    public int $backoff = 120;

    public function __construct(
        public string $websiteId,
        public int $days = 30,
    ) {
        $this->onQueue(\App\Support\Queues::SYNC);
        // ...on the redis-long connection (NOT a $connection property — that clashes
        // with the Queueable trait's typed property). See config/queue.php +
        // AnalyzeSiteJob for the same pattern.
        $this->onConnection('redis-long');
    }

    /**
     * Without this, a run that legitimately outlives redis-long's retry_after
     * (3900s) gets a duplicate dispatched right on top of it — confirmed in
     * production (two concurrent reservations for the same website fighting
     * over the same upserts). Same pattern as AnalyzeSiteJob.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sync-search-console:'.$this->websiteId))
                ->dontRelease()
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(SearchConsoleService $service): void
    {
        // Horizon workers inherit PHP's CLI default memory_limit (128M). A high-volume
        // GSC account can return 100k+ rows for a single 7-day window, and the Google
        // API client's response deserialization (google/apiclient Model objects) OOMs
        // at 128M well before our own array handling does — confirmed live: "Allowed
        // memory size of 134217728 bytes exhausted in .../google/apiclient/src/Model.php".
        // Same pattern as CrawlPageBatchJob/AnalyzeSiteJob (config/crawler.php).
        ini_set('memory_limit', '1024M');

        if (\App\Support\ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
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

        Log::info("SyncSearchConsoleData: starting website {$this->websiteId} ({$this->days}d window)");

        while ($cursor->lt($end)) {
            $windowEnd = $cursor->copy()->addDays(6)->min($end);
            $windowStart = $cursor->toDateString();
            $windowEndStr = $windowEnd->toDateString();

            $rows = $service->fetchSearchAnalytics(
                $account,
                $website->gsc_site_url,
                $windowStart,
                $windowEndStr
            );

            $this->upsertRows($rows);

            // Watermark + cache-bust per window (not just at the end): if this run
            // dies partway through on a high-volume account, the rows already
            // upserted are still visible immediately rather than waiting on a
            // timestamp that never gets set.
            Website::whereKey($this->websiteId)->update(['last_search_console_sync_at' => now()]);
            ReportCache::flushWebsite($this->websiteId);

            Log::info("SyncSearchConsoleData: website {$this->websiteId} window {$windowStart}..{$windowEndStr} — ".count($rows).' rows');

            $cursor->addDays(7);
        }

        Log::info("SyncSearchConsoleData: completed website {$this->websiteId}");

        $this->queueKeywordMetricsRefresh();

        // Fresh rows just orphaned every version-keyed dashboard cache — warm
        // them now so the first dashboard/statistics visit is instant instead
        // of paying the cold aggregate (~2min on the largest accounts).
        // ShouldBeUnique(5min) collapses this with the GA sync's dispatch.
        \App\Jobs\WarmDashboardCaches::dispatch((string) $this->websiteId);
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
                // `query`/`page` are varchar(255) and part of the 6-column unique key —
                // widening either risks the composite index exceeding InnoDB's key-length
                // cap. Google occasionally returns pathological long queries (e.g. boolean
                // search-operator strings from third-party tools); truncate rather than
                // let one outlier row fail the whole 500-row upsert chunk.
                $row['query'] = mb_substr((string) $row['query'], 0, 255);
                $row['page'] = mb_substr((string) $row['page'], 0, 255);
                return $row;
            }, $chunk);

            DB::table('search_console_data')->upsert(ulid_rows($prepared),
                ['website_id', 'date', 'query', 'page', 'country', 'device'],
                ['clicks', 'impressions', 'position', 'ctr', 'updated_at']
            );
        }
    }
}
