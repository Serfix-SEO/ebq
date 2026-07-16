<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for "is the Tier-1.5 link crawler running right now".
 * Two layers:
 *   - `LINK_CRAWL_ENABLED` env (config) — the master kill switch, per box.
 *   - a runtime cache override the admin dashboard flips — lets an operator
 *     pause/resume without an SSH env edit + Horizon restart.
 * Enabled = env on AND not runtime-paused.
 */
class LinkCrawlToggle
{
    private const RUNTIME_KEY = 'linkcrawl:runtime_enabled';

    public static function enabled(): bool
    {
        if (! (bool) config('crawler.link_crawl.enabled')) {
            return false;
        }

        return (bool) Cache::get(self::RUNTIME_KEY, true);
    }

    public static function pause(): void
    {
        Cache::forever(self::RUNTIME_KEY, false);
    }

    public static function resume(): void
    {
        Cache::forever(self::RUNTIME_KEY, true);
    }

    public static function runtimePaused(): bool
    {
        return (bool) config('crawler.link_crawl.enabled') && ! (bool) Cache::get(self::RUNTIME_KEY, true);
    }
}
