<?php

namespace App\Services\Crawler;

use App\Support\Audit\SafeHttpGuard;
use App\Support\Crawler\LinkStatus;
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

    /**
     * A HEAD result we refuse to trust on its own. EVERY 4xx is here, 404
     * included: `support.google.com/ads/answer/1634057` answers HEAD with 404
     * and GET with 200, and that single pattern produced 136 of the 173 "404"
     * findings on prod (2026-08-16) — every one of them a live page. Also 501
     * ("method not implemented" = the host simply doesn't do HEAD).
     */
    private const FALLBACK_STATUSES = [
        400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410,
        411, 412, 413, 414, 415, 416, 417, 418, 421, 422, 423,
        424, 425, 426, 428, 429, 431, 451, 501,
    ];

    /** Max links per run we escalate to the render server (bounded cost). */
    private const RENDER_ADJUDICATIONS = 25;

    private int $renderCalls = 0;

    public function __construct(
        private readonly SafeHttpGuard $guard,
        private readonly ProxyPool $proxies,
        private readonly FirecrawlClient $firecrawl,
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
                $reason = (string) ($check['reason'] ?? 'blocked');
                // `dns_resolution_failed` is NOT a verdict about the link: our
                // resolver can fail on geo-restricted or slow authoritative DNS
                // for a site that is perfectly alive (emart.ssg.com, charms.kr —
                // both reachable through the render server, 2026-08-16). Ask the
                // render server before giving up; if that can't decide either,
                // fall through as INCONCLUSIVE (guard_blocked stays false).
                if ($reason === 'dns_resolution_failed') {
                    $rendered = $this->renderStatus($link['href']);
                    if ($rendered !== null && ! LinkStatus::isDead($rendered)) {
                        continue; // alive → not a problem at all
                    }
                    $problems[] = $this->row($link, $rendered, $reason, false, null, 0, false);

                    continue;
                }

                // Everything else here is a DETERMINISTIC shape problem
                // (malformed URL, unsupported scheme, literal/private IP) — no
                // network guessing involved, so it is a reliable verdict.
                $problems[] = $this->row($link, null, $reason, false, null, 0, true);

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

                // Last resort: still looks dead after HEAD → GET → proxy GET.
                // Datacenter IPs get blocked or fed error pages by plenty of
                // live hosts, so before calling a link broken we ask the render
                // server (headless browser + residential exit) for the real
                // status. Bounded per run; a null answer changes nothing.
                if ($status !== null && LinkStatus::isDead($status)) {
                    $rendered = $this->renderStatus($link['href']);
                    if ($rendered !== null) {
                        $status = $rendered;
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

    /**
     * Real status from the self-hosted render server (headless browser through
     * a residential exit) — the only check that sees what a human browser sees.
     * Null when disabled, out of budget, or unable to decide.
     */
    private function renderStatus(string $url): ?int
    {
        if (! $this->firecrawl->enabled() || $this->renderCalls >= self::RENDER_ADJUDICATIONS) {
            return null;
        }
        $this->renderCalls++;

        return $this->firecrawl->status($url);
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
