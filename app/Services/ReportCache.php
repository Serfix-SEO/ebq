<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Event-driven cache invalidation for read-heavy HQ + Dashboard payloads.
 *
 * Strategy: per-website "data version" integer, mixed into every cache key
 * the report stack writes. Bumping the version atomically orphans every
 * cached payload for that website without enumerating keys. Works on any
 * cache driver (database, file, redis), no tag support required.
 *
 * Cached payloads use a 24-hour sanity TTL so orphaned versions age out on
 * their own; real freshness comes from `flushWebsite()` being called when
 * new GSC rows or rank-tracker results land.
 *
 * Callers:
 *   - PluginHqController + ReportDataService read `version()` when building
 *     cache keys.
 *   - SyncDailyData (nightly GSC sync) calls `flushWebsite()` per website
 *     after rows are inserted.
 *   - TrackKeywordRankJob calls `flushWebsite()` after a rank check
 *     updates `current_position` on a tracked keyword.
 */
class ReportCache
{
    private const KEY_PREFIX = 'ws:dataver:';

    public static function version(int $websiteId): int
    {
        return (int) Cache::rememberForever(
            self::KEY_PREFIX.$websiteId,
            static fn () => 1,
        );
    }

    public static function flushWebsite(int $websiteId): void
    {
        $key = self::KEY_PREFIX.$websiteId;
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }
}
