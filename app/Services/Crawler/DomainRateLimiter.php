<?php

namespace App\Services\Crawler;

use App\Models\CrawlSite;
use App\Models\WorkerNode;
use App\Support\AutoscalerConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/**
 * Per-domain crawl politeness + fleet block coordination.
 *
 * RATE is per-SERVER, not fleet-global: each worker box fetches a domain from its OWN
 * public IP, so the target sees N distinct sources — politeness is per source-IP. Each
 * box gets its own `per_domain_rate` req/s/domain budget (keyed by FLEET_NODE_ID), so
 * fleet throughput for one domain = `per_domain_rate` × boxes (the point of >1 server).
 *
 * BLOCK coordination is PER-DOMAIN (never global): when a box is blocked on a domain it
 * records itself fleet-wide (a set of blocked server ids, with a cooldown). Then:
 *   - some (not all) boxes blocked  -> the rest go CAUTIOUS (quarter rate) so their IPs
 *     don't get blocked too;
 *   - ALL active boxes blocked      -> direct is hopeless, fall back to PROXIES for that
 *     domain until the cooldown clears and direct is retried.
 * Proxies are otherwise OFF (crawler.use_proxies) so each box uses its own IP.
 *
 * Redis-backed (RateLimiter + the shared cache store). See infra/crawler/autoscaling.md.
 */
class DomainRateLimiter
{
    /** Block until a token for this domain (on THIS server) is free (or max wait elapses). */
    public function throttle(?string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }
        $rate = max(1, AutoscalerConfig::perDomainRate());
        // Some servers already blocked here → ease off so we don't get blocked too.
        if ($this->blockState($domain) === 'cautious') {
            $rate = max(1, (int) ceil($rate / 4));
        }
        // Per SERVER (this box) — each box has its own per-domain budget.
        $server = $this->server();
        $key = 'crawl-rate:'.$domain.':'.$server;
        $maxWaitMs = max(0, (int) config('crawler.rate_max_wait_ms', 5000));
        $waited = 0;

        while (true) {
            if (! RateLimiter::tooManyAttempts($key, $rate)) {
                RateLimiter::hit($key, 1); // 1-second decay window → $rate per second
                return;
            }
            if ($waited >= $maxWaitMs) {
                Log::info('DomainRateLimiter: max wait reached, proceeding', ['domain' => $domain, 'rate' => $rate]);

                return;
            }
            usleep(100_000); // 100ms
            $waited += 100;
        }
    }

    /** Record that THIS server got blocked on the domain (fleet-wide, for the cooldown window). */
    public function recordBlock(?string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return;
        }
        $cooldown = max(60, (int) config('crawler.block_cooldown_s', 600));
        $set = 'crawl-blocked-servers:'.$domain;
        $r = Redis::connection();
        $r->sadd($set, $this->server());
        $r->expire($set, $cooldown);
        Log::info('DomainRateLimiter: block recorded', ['domain' => $domain, 'server' => $this->server()]);
    }

    /** Should this domain be fetched through a pool proxy right now? (= every box is blocked). */
    public function shouldUseProxy(?string $domain): bool
    {
        return $this->blockState($domain) === 'proxy';
    }

    /** 'direct' (default) | 'cautious' (some boxes blocked) | 'proxy' (all active boxes blocked). */
    public function blockState(?string $domain): string
    {
        $domain = $this->normalize($domain);
        if ($domain === '') {
            return 'direct';
        }
        $blocked = (int) Redis::connection()->scard('crawl-blocked-servers:'.$domain);
        if ($blocked <= 0) {
            return 'direct';
        }
        $active = $this->activeBoxes();

        return ($active > 0 && $blocked >= $active) ? 'proxy' : 'cautious';
    }

    /** Active worker boxes, cached briefly so the crawl hot-path doesn't hit the DB per fetch. */
    private function activeBoxes(): int
    {
        return (int) Cache::remember('fleet:active-box-count', 30, fn (): int => WorkerNode::billable()->count());
    }

    /** This box's identity (its own public IP ≈ FLEET_NODE_ID); falls back to hostname. */
    private function server(): string
    {
        return (string) (config('fleet.node_id') ?: gethostname());
    }

    /** Collapse a host or URL to the CrawlSite normalized form so all hosts of a domain share one bucket. */
    private function normalize(?string $domain): string
    {
        $d = trim((string) $domain);

        return $d === '' ? '' : CrawlSite::normalizeDomain($d);
    }
}
