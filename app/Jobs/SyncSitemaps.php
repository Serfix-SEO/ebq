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
        if (\App\Support\ShardLock::websiteLocked((string) $this->websiteId)) {
            $this->release(30);

            return;
        }
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

        // GSC scopes sitemaps.list to ONE property. A site stored under a
        // narrow URL-prefix property (e.g. https://falik.com/en/) misses
        // sitemaps that only exist on a broader property of the same domain
        // (https://falik.com/ or sc-domain:) — so query every accessible
        // property matching this website's domain and merge by path.
        $sitemaps = [];
        $fetchedAny = false;
        foreach ($this->candidateProperties($service, $account, $website) as $property) {
            try {
                $rows = $service->listSitemaps($account, $property);
                $fetchedAny = true;
            } catch (\Throwable $e) {
                Log::info("SyncSitemaps: GSC fetch failed for {$property} (website {$this->websiteId}): {$e->getMessage()}");
                continue;
            }
            foreach ($rows as $row) {
                $sitemaps[$row['path']] ??= $row;
            }
        }

        if (! $fetchedAny) {
            Log::warning("SyncSitemaps: GSC fetch failed for website {$this->websiteId} (all candidate properties)");
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

    /**
     * The stored property plus every other property on the account that
     * belongs to this website's domain (sc-domain: or any URL-prefix on the
     * same host, www tolerated). Stored property first so its rows win the
     * merge. Falls back to just the stored property if listing sites fails.
     *
     * @return array<int, string>
     */
    private function candidateProperties(SearchConsoleService $service, $account, Website $website): array
    {
        $domain = strtolower(ltrim((string) $website->domain, '.'));
        $out = [(string) $website->gsc_site_url];

        try {
            $sites = $service->listSites($account);
        } catch (\Throwable $e) {
            Log::info("SyncSitemaps: listSites failed for website {$this->websiteId}: {$e->getMessage()}");

            return $out;
        }

        foreach ($sites as $site) {
            $url = (string) $site['siteUrl'];
            $matches = str_starts_with($url, 'sc-domain:')
                ? strtolower(substr($url, 10)) === $domain
                : in_array(preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))), [$domain, preg_replace('/^www\./', '', $domain)], true);
            if ($matches && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
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
