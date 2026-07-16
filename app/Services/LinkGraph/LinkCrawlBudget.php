<?php

namespace App\Services\LinkGraph;

use Illuminate\Support\Facades\Redis;

/**
 * Hard daily page budget for the Tier-1.5 link crawler — a Redis fixed-window
 * counter keyed by calendar day (UTC), shared across both worker boxes so the
 * fleet can't collectively overshoot. Fail-open on Redis errors (a crawl
 * shouldn't hard-stop because the counter is unreachable) but the pass-level
 * guard still bounds work.
 */
class LinkCrawlBudget
{
    private const PREFIX = 'linkcrawl:budget:';

    public function limit(): int
    {
        return max(0, (int) config('crawler.link_crawl.daily_budget', 150000));
    }

    public function spent(?string $day = null): int
    {
        try {
            return (int) (Redis::connection()->get(self::PREFIX.($day ?? $this->today())) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - $this->spent());
    }

    public function exhausted(): bool
    {
        return $this->remaining() <= 0;
    }

    /** Charge N pages against today's budget; returns the new total. */
    public function consume(int $pages = 1): int
    {
        if ($pages <= 0) {
            return $this->spent();
        }
        try {
            $conn = Redis::connection();
            $key = self::PREFIX.$this->today();
            $total = (int) $conn->incrby($key, $pages);
            if ($total === $pages) {
                $conn->expire($key, 60 * 60 * 26); // ~a day + slack
            }

            return $total;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function today(): string
    {
        return now()->utc()->format('Ymd');
    }
}
