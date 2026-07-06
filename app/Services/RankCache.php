<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Cache versioning for rank-tracker data only.
 *
 * Separate from ReportCache so hourly rank checks do not orphan the
 * expensive 24h GSC aggregation caches (cannibalization, strikingDistance,
 * topCountries, etc.) that are purely GSC-derived and change only when
 * SyncSearchConsoleData runs.
 *
 * Callers:
 *   - TrackKeywordRankJob calls flushWebsite() after a successful rank check.
 *   - PluginHqController::overview includes version() in its cache key because
 *     the HQ overview payload surfaces tracker_distribution + tracked_keywords.
 */
class RankCache
{
    private const KEY_PREFIX = 'ws:rankver:';

    public static function version(string $websiteId): int
    {
        return (int) Cache::rememberForever(
            self::KEY_PREFIX.$websiteId,
            static fn () => 1,
        );
    }

    public static function flushWebsite(string $websiteId): void
    {
        $key = self::KEY_PREFIX.$websiteId;
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }
}
