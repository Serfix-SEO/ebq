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
}
