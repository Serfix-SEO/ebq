<?php

namespace App\Services\Crawler;

use App\Models\Proxy;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The crawler's outbound proxy pool. Merges admin-managed proxies (the `proxies`
 * table) with proxies parsed from proxylist.txt in the project root, normalises
 * them to Guzzle proxy URLs, and hands out one at a time for anti-blocking
 * retries. DB-backed proxies get basic health tracking — `markFailure()` (called
 * from real production use: `PageAuditService`, `LinkChecker`, `PageCrawlProcessor`)
 * auto-DELETES a proxy after repeated real-world failures. The separate
 * `ebq:proxy-list-refresh` synthetic health check never deletes/deactivates —
 * see that command's docblock.
 */
class ProxyPool
{
    private const CACHE_KEY = 'crawler:proxypool:urls';
    private const CACHE_TTL = 60;
    private const HEALTH_TEST_URL = 'https://api.ipify.org';

    public function enabled(): bool
    {
        return (bool) config('crawler.proxy.enabled', false)
            && config('crawler.proxy.mode', 'off') !== 'off';
    }

    public function available(): bool
    {
        return $this->enabled() && $this->urls() !== [];
    }

    /** Should this site's pages be proxied from the first attempt? */
    public function preemptiveFor(?string $crawlProtection): bool
    {
        if (! $this->available()) {
            return false;
        }
        $mode = config('crawler.proxy.mode', 'on_block');

        return $mode === 'always'
            || ($mode === 'on_block' && in_array($crawlProtection, ['cloudflare', 'blocked'], true));
    }

    /** Pick a proxy URL at random, or null if the pool is empty/disabled. */
    public function pick(): ?string
    {
        $urls = $this->urls();
        if ($urls === []) {
            return null;
        }

        return $urls[array_rand($urls)];
    }

    /** @return list<string> merged, normalised proxy URLs (cached briefly) */
    public function urls(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $urls = [];
            foreach (Proxy::query()->where('active', true)->pluck('url') as $u) {
                $n = $this->normalise((string) $u);
                if ($n !== null) {
                    $urls[$n] = true;
                }
            }

            return array_keys($urls);
        });
    }

    public function markSuccess(?string $url): void
    {
        if ($url === null) {
            return;
        }
        Proxy::where('url_hash', Proxy::hashUrl($url))->update([
            'fail_count' => 0,
            'success_count' => DB::raw('success_count + 1'),
            'last_used_at' => now(),
            'last_ok_at' => now(),
        ]);
    }

    /**
     * Records a failure from REAL production use (audit link-check, crawler
     * fetch/link-check) — not a synthetic health-check ping.
     *
     * TELEMETRY ONLY — never deletes, never deactivates (owner 2026-08-20).
     * The pool is PAID residential proxies: the old 5-strike auto-delete wiped
     * all four of them in minutes when a blocked SITE (cocomii's captcha wall)
     * was mis-charged against proxy health — every wall response through a
     * proxy counted as a proxy failure. Paid credentials must survive both
     * transient provider hiccups and hostile sites; fail_count is a health
     * signal on the admin screen, nothing more.
     */
    public function markFailure(?string $url): void
    {
        if ($url === null) {
            return;
        }
        $proxy = Proxy::where('url_hash', Proxy::hashUrl($url))->first();
        if (! $proxy) {
            return; // file-sourced proxy — not health-tracked here
        }
        $proxy->fail_count++;
        $proxy->last_used_at = now();
        $proxy->save();
    }

    /**
     * Concurrently live-tests proxy URLs via a real HTTPS round-trip (cert
     * verification ON — catches MITM, see `ebq:proxy-list-refresh` docblock).
     * Shared by the import command and the dead-proxy pruner so both use the
     * exact same test semantics.
     *
     * @param  list<string>  $urls
     * @return array<string,bool> url => reachable
     */
    public function testBatch(array $urls, int $concurrency = 25, int $timeout = 6): array
    {
        $results = [];
        foreach (array_chunk(array_values(array_unique($urls)), max(1, $concurrency)) as $batch) {
            $responses = Http::pool(function (Pool $httpPool) use ($batch, $timeout) {
                $calls = [];
                foreach ($batch as $i => $url) {
                    $calls[] = $httpPool->as((string) $i)
                        ->timeout($timeout)
                        ->connectTimeout($timeout)
                        ->withOptions(['proxy' => $url])
                        ->get(self::HEALTH_TEST_URL);
                }

                return $calls;
            });

            foreach ($batch as $i => $url) {
                $resp = $responses[(string) $i] ?? null;
                $results[$url] = $resp instanceof Response
                    && $resp->successful()
                    && (bool) filter_var(trim($resp->body()), FILTER_VALIDATE_IP);
            }
        }

        return $results;
    }

    /**
     * Normalise a proxy entry to a Guzzle proxy URL: scheme://[user:pass@]host:port.
     * Accepts: host:port | host:port:user:pass | user:pass@host:port |
     * scheme://host:port | scheme://user:pass@host:port. Default scheme http.
     */
    public function normalise(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        $scheme = 'http';
        if (preg_match('#^(https?|socks5h?|socks4)://(.*)$#i', $entry, $m)) {
            $scheme = strtolower($m[1]);
            $entry = $m[2];
        }

        $user = $pass = null;
        if (str_contains($entry, '@')) {
            [$creds, $entry] = explode('@', $entry, 2);
            if (str_contains($creds, ':')) {
                [$user, $pass] = explode(':', $creds, 2);
            }
        }

        $parts = explode(':', $entry);
        // host:port:user:pass (common flat-file form)
        if ($user === null && count($parts) === 4) {
            [$host, $port, $user, $pass] = $parts;
        } elseif (count($parts) === 2) {
            [$host, $port] = $parts;
        } else {
            return null;
        }

        $host = trim($host);
        $port = (int) $port;
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $auth = ($user !== null && $user !== '') ? rawurlencode($user).':'.rawurlencode((string) $pass).'@' : '';

        return "{$scheme}://{$auth}{$host}:{$port}";
    }
}
