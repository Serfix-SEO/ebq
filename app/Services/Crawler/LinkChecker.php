<?php

namespace App\Services\Crawler;

use App\Support\Audit\SafeHttpGuard;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Concurrent link-status checker. Mirrors PageAuditService::checkLinks() (HEAD
 * with GET fallback for 403/405/501, SSRF re-guard, pooled concurrency) but
 * returns full per-link results — broken AND redirected — so the crawler's
 * issue detector can classify (broken_link vs redirect_chain).
 */
class LinkChecker
{
    private const TIMEOUT = 8;

    /** Last-chance GET is given more room: a slow-but-alive host (e.g. a
     *  government/enterprise site behind heavy TLS) routinely exceeds the fast
     *  bulk HEAD timeout without being dead. */
    private const GET_TIMEOUT = 15;

    private const CONCURRENCY = 10;

    /** Statuses that can be a block/rate-limit false positive rather than a real dead link. */
    private const FALLBACK_STATUSES = [403, 405, 429, 501];

    public function __construct(
        private readonly SafeHttpGuard $guard,
        private readonly ProxyPool $proxies,
    ) {}

    /**
     * @param  array<int,array{href:string,anchor?:string}>  $links
     * @return array<int,array{href:string,anchor:string,status:?int,error:?string,redirected:bool,final_url:?string,chain:int,guard_blocked:bool}>
     *         Only problematic links (status>=400, transport error, or redirected) are returned.
     *         A null status means "could not verify" (timeout/transport error), NOT
     *         "confirmed dead" — unless guard_blocked is true (deterministic bad URL).
     */
    public function check(array $links, int $max = 200): array
    {
        if ($links === []) {
            return [];
        }

        $unique = [];
        foreach ($links as $l) {
            $href = (string) ($l['href'] ?? '');
            if ($href === '' || isset($unique[$href])) {
                continue;
            }
            $unique[$href] = ['href' => $href, 'anchor' => (string) ($l['anchor'] ?? '')];
            if (count($unique) >= $max) {
                break;
            }
        }

        $problems = [];
        $toCheck = [];
        foreach ($unique as $link) {
            $check = $this->guard->check($link['href']);
            if (! $check['ok']) {
                // Mailto/tel/relative would have been filtered upstream; a guard
                // failure here means a genuinely unfetchable/unsafe target. This is a
                // DETERMINISTIC verdict (malformed/unsafe URL), not a network guess —
                // mark it so the caller can distinguish it from an inconclusive timeout.
                $problems[] = $this->row($link, null, $check['reason'] ?? 'blocked', false, null, 0, true);

                continue;
            }
            $toCheck[] = $link;
        }

        $batchIndex = 0;
        foreach (array_chunk($toCheck, self::CONCURRENCY) as $batch) {
            if ($batchIndex++ > 0) {
                usleep(500_000); // 500ms between concurrent batches — external-link check runs on finalize, not time-critical
            }
            $responses = Http::pool(function (Pool $pool) use ($batch) {
                $calls = [];
                foreach ($batch as $i => $link) {
                    $calls[] = $pool->as((string) $i)
                        ->timeout(self::TIMEOUT)
                        ->connectTimeout(self::TIMEOUT)
                        ->withUserAgent(CrawlFetcher::UA)
                        ->withOptions([
                            'allow_redirects' => [
                                'max' => 5,
                                'strict' => true,
                                'referer' => false,
                                'protocols' => ['http', 'https'],
                                'track_redirects' => true,
                                'on_redirect' => function ($request, $response, $uri) {
                                    $check = $this->guard->check((string) $uri);
                                    if (! $check['ok']) {
                                        throw new \RuntimeException('blocked redirect: '.($check['reason'] ?? 'unsafe_url'));
                                    }
                                },
                            ],
                        ])
                        ->head($link['href']);
                }

                return $calls;
            });

            foreach ($batch as $i => $link) {
                $resp = $responses[(string) $i] ?? null;
                $status = null;
                $error = null;
                $redirected = false;
                $finalUrl = null;
                $chain = 0;

                if ($resp instanceof Response) {
                    $status = $resp->status();
                    if (in_array($status, self::FALLBACK_STATUSES, true)) {
                        $status = $this->getFallback($link['href']) ?? $status;
                    }
                    $history = array_filter(array_map('trim', explode(',', (string) $resp->header('X-Guzzle-Redirect-History'))));
                    $chain = count($history);
                    $redirected = $chain > 0;
                    $finalUrl = $redirected ? (string) end($history) : null;
                } else {
                    // HEAD failed at the transport layer (timeout, connection reset,
                    // TLS, DNS). That is NOT proof of a dead link: many hosts reject or
                    // hang on HEAD but serve GET fine, and a slow-but-alive host can
                    // simply exceed our fast HEAD timeout. Give the URL a real GET
                    // (direct, then proxied) before trusting the failure. If GET yields
                    // a definitive status, the HEAD error is moot; if GET is also
                    // unreachable, $status stays null (inconclusive, not "broken").
                    $error = $resp instanceof \Throwable ? $resp->getMessage() : 'unknown';
                    $status = $this->getFallback($link['href']);
                    if ($status !== null) {
                        $error = null;
                    }
                }

                if ($status === null || $status >= 400 || $redirected) {
                    $problems[] = $this->row($link, $status, $error, $redirected, $finalUrl, $chain, false);
                }
            }
        }

        return $problems;
    }

    private function getFallback(string $url): ?int
    {
        $status = $this->fetchGetStatus($url, null);
        if ($status !== null && $status < 400) {
            return $status;
        }

        // Direct GET still looks dead — could be a real 404, or the host
        // blocking our IP/UA (anti-bot, rate-limit). Retry once via the
        // proxy pool before trusting the direct result.
        if ($this->proxies->enabled()) {
            $proxy = $this->proxies->pick();
            if ($proxy !== null) {
                $proxied = $this->fetchGetStatus($url, $proxy);
                if ($proxied !== null && $proxied < 400) {
                    $this->proxies->markSuccess($proxy);

                    return $proxied;
                }
                $this->proxies->markFailure($proxy);
            }
        }

        return $status;
    }

    private function fetchGetStatus(string $url, ?string $proxy): ?int
    {
        try {
            return Http::timeout(self::GET_TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->withUserAgent(CrawlFetcher::UA)
                ->withOptions(array_filter(['proxy' => $proxy], static fn ($v) => $v !== null))
                ->get($url)
                ->status();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{href:string,anchor:string}  $link
     * @param  bool  $guardBlocked  True only for a deterministic pre-flight guard
     *                              rejection (malformed/unsafe URL) — a reliable
     *                              "broken" verdict, unlike an inconclusive null
     *                              status from a network timeout/transport error.
     * @return array{href:string,anchor:string,status:?int,error:?string,redirected:bool,final_url:?string,chain:int,guard_blocked:bool}
     */
    private function row(array $link, ?int $status, ?string $error, bool $redirected, ?string $finalUrl, int $chain, bool $guardBlocked): array
    {
        return [
            'href' => $link['href'],
            'anchor' => $link['anchor'],
            'status' => $status,
            'error' => $error,
            'redirected' => $redirected,
            'final_url' => $finalUrl,
            'chain' => $chain,
            'guard_blocked' => $guardBlocked,
        ];
    }
}
