<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Redis;

/**
 * Global monthly DataForSEO spend circuit-breaker — a Redis fixed-window
 * counter of REAL billed cost (each generation adds `tasks[0].cost` sums via
 * {@see \App\Services\DataForSeoBacklinkClient::totalCost()}), keyed by
 * calendar month (UTC). When the month's spend reaches the configured cap
 * (`services.dataforseo.monthly_cap_usd`, env DATAFORSEO_MONTHLY_CAP_USD):
 *
 *  - arbitrary-domain LOOKUPS degrade to the free-signal partial-report path
 *    (OPR/Moz/CC ranks/own link graph — the young-site flow, neutral copy),
 *  - TTL refreshes + schema self-heals keep serving the cached snapshot,
 *  - OWN attached-site first reports still generate (core promise; bounded
 *    by signup rate),
 *  - anchor drill-downs stay allowed (plan-gated, ~$0.03) but their cost
 *    IS counted here.
 *
 * All of this is ADMIN-ONLY knowledge: warnings go to the ops digest and
 * /admin/ops; client-facing surfaces never mention budgets or limits
 * (client-facing copy rules). Null/0 cap = breaker disabled (unlimited).
 * Fail-open on Redis errors — a report must not fail because the counter is
 * unreachable. Mirrors the LinkCrawlBudget pattern.
 */
class DataForSeoSpendMeter
{
    private const PREFIX = 'dfs:spend:';

    /** The configured monthly cap in USD, or null when the breaker is disabled. */
    public function cap(): ?float
    {
        $cap = config('services.dataforseo.monthly_cap_usd');

        return is_numeric($cap) && (float) $cap > 0 ? (float) $cap : null;
    }

    /** Real billed spend so far this month (USD). */
    public function spent(?string $month = null): float
    {
        try {
            return round((float) (Redis::connection()->get(self::PREFIX.($month ?? $this->month())) ?? 0), 4);
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
            $key = self::PREFIX.$this->month();
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
