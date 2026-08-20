<?php

namespace App\Support\Crawler;

use Illuminate\Support\Facades\Cache;

/**
 * Per-crawl-site daily cap on Firecrawl-rendered fetches. Firecrawl runs a
 * real browser through a metered residential proxy on a single box — one
 * huge blocked site must not eat the whole allowance (or the box). Sites
 * under the cap crawl fully through the render path; beyond it, fetches fall
 * back to the normal (probably blocked) path and the run reports honestly.
 *
 * Cache-backed (Redis on the workers) so the counter is fleet-wide; the key
 * expires ~24h after first use, so the budget is a rolling daily one.
 */
class FirecrawlBudget
{
    public function limit(): int
    {
        return max(0, (int) config('crawler.firecrawl_daily_page_budget', 500));
    }

    public function allow(string $crawlSiteId): bool
    {
        return $this->limit() > 0 && (int) Cache::get($this->key($crawlSiteId), 0) < $this->limit();
    }

    public function consume(string $crawlSiteId): void
    {
        $key = $this->key($crawlSiteId);
        Cache::add($key, 0, now()->addDay());
        Cache::increment($key);
    }

    /** Fleet-wide marker: this site fetched pages via the render path today. */
    public function markRendered(string $crawlSiteId): void
    {
        Cache::put('crawler:rendered:'.$crawlSiteId, true, now()->addDay());
    }

    public function renderedRecently(string $crawlSiteId): bool
    {
        return (bool) Cache::get('crawler:rendered:'.$crawlSiteId, false);
    }

    private function key(string $crawlSiteId): string
    {
        return 'crawler:fc-budget:'.$crawlSiteId.':'.now()->format('Y-m-d');
    }

    // ── render concurrency semaphore ────────────────────────────────────
    // Firecrawl is ONE cx33 running real browsers: sixteen crawl workers all
    // rendering at once jam its queue, scrape latency balloons past the HTTP
    // client's ceiling, and whole batches die on the job timeout (2026-08-20
    // stall). Cap concurrent renders fleet-wide; a worker that can't get a
    // slot skips the render (normal fetch path) instead of queueing blind.

    private const SLOT_KEY = 'crawler:fc-slots';

    public function maxConcurrency(): int
    {
        return max(1, (int) config('crawler.firecrawl_max_concurrency', 4));
    }

    /** Try to take a render slot. Caller MUST releaseSlot() when done. */
    public function acquireSlot(): bool
    {
        $r = \Illuminate\Support\Facades\Redis::connection();
        $count = (int) $r->incr(self::SLOT_KEY);
        // TTL refresh on every acquire: if a worker is killed mid-render the
        // counter leaks, and the expiry (generously above one render's worst
        // case) heals it instead of starving the fleet forever.
        $r->expire(self::SLOT_KEY, 300);
        if ($count > $this->maxConcurrency()) {
            $r->decr(self::SLOT_KEY);

            return false;
        }

        return true;
    }

    public function releaseSlot(): void
    {
        $r = \Illuminate\Support\Facades\Redis::connection();
        if ((int) $r->decr(self::SLOT_KEY) < 0) {
            $r->del(self::SLOT_KEY); // never go negative (expired mid-flight)
        }
    }
}
