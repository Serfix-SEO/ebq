<?php

namespace App\Services\Spend;

use Illuminate\Support\Facades\Redis;

/**
 * Reusable global monthly spend circuit-breaker: a Redis fixed-window counter
 * of real billed USD, keyed by calendar month (UTC). Same behavior contract
 * as {@see \App\Services\Reports\DataForSeoSpendMeter} (the original):
 *
 *  - null/0 cap  = breaker disabled (unlimited),
 *  - fail-open on Redis errors — the guarded feature must not fail because
 *    the counter is unreachable,
 *  - ADMIN-ONLY knowledge: warnings go to the ops digest and /admin/ops;
 *    client-facing surfaces never mention budgets or limits.
 *
 * Subclasses pin the Redis key prefix and the config key holding the cap.
 */
abstract class MonthlySpendMeter
{
    /** Redis key prefix, e.g. 'ideogram:spend:'. */
    abstract protected function prefix(): string;

    /** Config key holding the monthly cap in USD (nullable float). */
    abstract protected function capConfigKey(): string;

    /** The configured monthly cap in USD, or null when the breaker is disabled. */
    public function cap(): ?float
    {
        $cap = config($this->capConfigKey());

        return is_numeric($cap) && (float) $cap > 0 ? (float) $cap : null;
    }

    /** Real billed spend so far this month (USD). */
    public function spent(?string $month = null): float
    {
        try {
            return round((float) (Redis::connection()->get($this->prefix().($month ?? $this->month())) ?? 0), 4);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function exhausted(): bool
    {
        $cap = $this->cap();

        return $cap !== null && $this->spent() >= $cap;
    }

    /** True from 80% of the cap — drives the admin ops digest warning. */
    public function nearCap(): bool
    {
        $cap = $this->cap();

        return $cap !== null && $this->spent() >= $cap * 0.8;
    }

    /** Charge real billed dollars against this month; returns the new total. */
    public function add(float $usd): float
    {
        if ($usd <= 0) {
            return $this->spent();
        }
        try {
            $conn = Redis::connection();
            $key = $this->prefix().$this->month();
            $total = (float) $conn->incrbyfloat($key, round($usd, 4));
            // First write of the month sets the TTL (~40 days = month + slack
            // for the admin to inspect last month's final number).
            if (abs($total - $usd) < 0.00005) {
                $conn->expire($key, 60 * 60 * 24 * 40);
            }

            return round($total, 4);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function month(): string
    {
        return now()->utc()->format('Y-m');
    }
}
