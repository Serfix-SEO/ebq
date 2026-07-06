<?php

namespace App\Services\Crawler;

use App\Models\CrawlSite;
use App\Support\AutoscalerConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Adaptive PER-DOMAIN crawl rate. Each domain has its own req/sec ceiling that ramps:
 * starts at `per_domain_rate` (admin floor, e.g. 10/s), and `ebq:ramp-crawl-rates` bumps
 * it by `crawler.rate_step` (10) every clean interval up to `crawler.rate_max` (100) while
 * the domain isn't pushing back. The moment a fetch is blocked, the domain RESETS to the
 * floor (recordBlock) and a cooldown stops it ramping again for a bit — so we probe faster
 * and faster until the site complains, then back off. Per domain, never global-across-domains.
 *
 * Fetches go through the proxy pool first (PageCrawlProcessor), so this is the DOMAIN's total
 * rate (across whatever IPs), which is the right politeness unit. Redis-backed (RateLimiter +
 * shared cache). See infra/crawler/autoscaling.md.
 */
class DomainRateLimiter
{
    private const RATE_KEY = 'crawl-rate-cur:';     // current adaptive rate for a domain
    private const BLOCK_KEY = 'crawl-blocked:';     // set during a domain's block cooldown
    private const LAT_KEY = 'crawl-lat:';           // recent fetch-latency samples (capped list)
    private const BASE_KEY = 'crawl-lat-base:';     // the domain's "healthy" baseline latency
    private const WAF_KEY = 'crawl-waf:';           // WAF/CDN vendor detected for a domain

    /**
     * Block until a token for this domain is free (or the max wait elapses).
     *
     * Uses Redis INCR on a per-second (or per-2s for WAF) slot key — atomic across
     * ALL workers on ALL boxes. The previous implementation used Laravel's RateLimiter
     * facade, which defaults to the `database` cache store on this app, so workers on
     * different boxes never shared the same window and the per-domain rate was never
     * actually enforced fleet-wide.
     */
    public function throttle(?string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }

        $maxWaitMs = max(0, (int) config('crawler.rate_max_wait_ms', 5000));

        if ($this->isWafProtected($domain)) {
            // WAF: 1 req / 2-second slot
            $this->acquireToken('crawl-waf-rl:'.$domain, 1, 2, $maxWaitMs);
        } else {
            // Normal: $rate req / 1-second slot
            $this->acquireToken('crawl-rl:'.$domain, $this->currentRate($domain), 1, $maxWaitMs);
        }
    }

    /**
     * Acquire one token using a Redis INCR fixed-window counter.
     * Atomic across all workers/boxes — no Laravel cache store dependency.
     *
     * @param string $key    Redis key prefix (domain-scoped)
     * @param int    $rate   max requests per window
     * @param int    $windowS window size in seconds
     * @param int    $maxWaitMs fail-open after this many ms of waiting
     */
    private function acquireToken(string $key, int $rate, int $windowS, int $maxWaitMs): void
    {
        $r = Redis::connection();
        $waited = 0;

        while (true) {
            $slot = (int) floor(microtime(true) / $windowS);
            $slotKey = $key.':'.$slot;
            $count = (int) $r->incr($slotKey);
            $r->expire($slotKey, $windowS + 1);

            if ($count <= $rate) {
                return; // token acquired
            }

            // Slot full — undo our increment so we don't inflate the count,
            // then sleep until the next slot opens.
            $r->decr($slotKey);

            if ($waited >= $maxWaitMs) {
                return; // fail-open: rare, avoids a permanently stuck worker
            }

            // Sleep until the next slot boundary — full wait, no premature retry.
            $msUntilNextSlot = (int) ceil(($windowS - fmod(microtime(true), (float) $windowS)) * 1000);
            $sleepMs = max(10, $msUntilNextSlot);
            usleep($sleepMs * 1000);
            $waited += $sleepMs;
        }
    }

    /** This domain's current adaptive rate (req/sec). Defaults to the floor. */
    public function currentRate(?string $domain): int
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return $this->floor();
        }
        $v = Redis::connection()->get(self::RATE_KEY.$domain);

        return $v !== null ? max($this->floor(), (int) $v) : $this->floor();
    }

    /** Persist a domain's adaptive rate (clamped floor..max). Used by the ramp command. */
    public function setRate(?string $domain, int $rate): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }
        $rate = max($this->floor(), min($this->max(), $rate));
        // TTL so a domain that stops being crawled forgets its rate and starts fresh.
        Redis::connection()->setex(self::RATE_KEY.$domain, 3600, $rate);
    }

    /** A fetch on this domain got blocked → drop to the floor + hold the ramp for the cooldown. */
    public function recordBlock(?string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }
        $cooldown = max(30, (int) config('crawler.block_cooldown_s', 600));
        $r = Redis::connection();
        $r->setex(self::RATE_KEY.$domain, 3600, $this->floor());
        $r->setex(self::BLOCK_KEY.$domain, $cooldown, 1);
        Log::info('DomainRateLimiter: blocked → rate reset to floor', ['domain' => $domain, 'floor' => $this->floor()]);
    }

    /** Is this domain in a post-block cooldown (don't ramp it up yet)? */
    public function inBlockCooldown(?string $domain): bool
    {
        $domain = $this->normalize($domain);

        return $domain !== '' && (bool) Redis::connection()->exists(self::BLOCK_KEY.$domain);
    }

    /**
     * Record that a WAF/CDN was detected on this domain (persisted 48h, refreshed each crawl).
     * Once flagged, throttle() enforces 1 req/2s instead of the adaptive ramp.
     */
    public function recordWaf(?string $domain, string $vendor): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }
        Redis::connection()->setex(self::WAF_KEY.$domain, 172_800, $vendor); // 48h TTL
        Log::info('DomainRateLimiter: WAF detected → slow mode (1 req/2s)', [
            'domain' => $domain,
            'waf' => $vendor,
        ]);
    }

    /** Is this domain known to be behind a WAF/CDN? */
    public function isWafProtected(?string $domain): bool
    {
        $domain = $this->normalize($domain);

        return $domain !== '' && (bool) Redis::connection()->exists(self::WAF_KEY.$domain);
    }

    /** Which WAF vendor was detected (null if none). */
    public function wafVendor(?string $domain): ?string
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return null;
        }
        $v = Redis::connection()->get(self::WAF_KEY.$domain);

        return $v !== null ? (string) $v : null;
    }

    /** Record a fetch's latency (ms) so the smart policy can spot the site slowing down. */
    public function recordFetch(?string $domain, int $latencyMs): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '' || $latencyMs <= 0) {
            return;
        }
        $r = Redis::connection();
        $k = self::LAT_KEY.$domain;
        $r->lpush($k, $latencyMs);
        $r->ltrim($k, 0, 49);   // keep the last 50 samples
        $r->expire($k, 300);
    }

    /**
     * SMART adaptive step (AIMD), called per crawling domain by ebq:ramp-crawl-rates:
     *  - just blocked  → hold at the floor (recordBlock already reset it);
     *  - site SLOWING  → recent latency well above the healthy baseline → multiplicative
     *    DECREASE (halve) BEFORE we get hard-blocked;
     *  - healthy       → additive INCREASE (+step) toward the max, and refresh the baseline.
     *
     * @return array{action:string,rate:int,lat:?int,base:?int}
     */
    public function adjust(?string $domain): array
    {
        $domain = $this->normalize($domain);
        $cur = $this->currentRate($domain);
        if ($domain === '' || $this->inBlockCooldown($domain)) {
            return ['action' => 'cooldown', 'rate' => $cur, 'lat' => null, 'base' => null];
        }

        // WAF-protected domains stay at 1 req/2s — never ramp them up.
        if ($this->isWafProtected($domain)) {
            return ['action' => 'waf_hold', 'rate' => $cur, 'lat' => null, 'base' => null];
        }

        $r = Redis::connection();
        $samples = array_map('intval', $r->lrange(self::LAT_KEY.$domain, 0, -1));
        $minSamples = max(1, (int) config('crawler.rate_min_samples', 8));

        if (count($samples) < $minSamples) {
            // Not enough signal yet — gentle additive increase.
            $new = min($this->max(), $cur + $this->step());
            $this->setRate($domain, $new);

            return ['action' => $new > $cur ? 'up' : 'hold', 'rate' => $new, 'lat' => null, 'base' => null];
        }

        $avg = (int) round(array_sum($samples) / count($samples));
        $base = (int) ($r->get(self::BASE_KEY.$domain) ?: 0);
        if ($base <= 0) {
            $base = $avg; // first reading establishes the healthy baseline
            $r->setex(self::BASE_KEY.$domain, 3600, $base);
        }
        $degradeFactor = (float) config('crawler.rate_degrade_factor', 1.5);

        if ($avg > $base * $degradeFactor) {
            // Site is slowing under load → back off HARD (multiplicative decrease).
            $new = max($this->floor(), (int) floor($cur / 2));
            $action = 'down(slow)';
        } else {
            // Healthy → additive increase, and track the lowest avg as the baseline.
            $new = min($this->max(), $cur + $this->step());
            if ($avg < $base) {
                $r->setex(self::BASE_KEY.$domain, 3600, $avg);
            }
            $action = $new > $cur ? 'up' : 'hold';
        }
        $this->setRate($domain, $new);

        return ['action' => $action, 'rate' => $new, 'lat' => $avg, 'base' => $base];
    }

    /** Ramp floor = the admin per_domain_rate setting (the starting rate). */
    private function floor(): int
    {
        return max(1, AutoscalerConfig::perDomainRate());
    }

    private function max(): int
    {
        return max($this->floor(), (int) config('crawler.rate_max', 100));
    }

    private function step(): int
    {
        return max(1, (int) config('crawler.rate_step', 10));
    }

    /** Collapse a host or URL to the CrawlSite normalized form so all hosts of a domain share one bucket. */
    private function normalize(?string $domain): string
    {
        $d = trim((string) $domain);

        return $d === '' ? '' : CrawlSite::normalizeDomain($d);
    }
}
