<?php

namespace App\Services\Crawler;

use App\Support\Audit\SafeHttpGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Single outbound-fetch path for the crawler. Mirrors the Googlebot UA /
 * manual-redirect / per-hop SSRF re-guard / 5MB-cap behaviour of
 * PageAuditService::fetch(), and adds conditional GET (If-None-Match /
 * If-Modified-Since) plus ETag / Last-Modified / redirect-chain capture so
 * recrawls can cheaply skip unchanged pages.
 *
 * Kept separate from PageAuditService so the audit hot path is untouched.
 */
class CrawlFetcher
{
    private const MAX_BODY_BYTES = 5_000_000;

    public const UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/124.0.6367.207 Safari/537.36';

    public function __construct(private readonly SafeHttpGuard $guard) {}

    /**
     * Fetch a URL. $conditional carries previously-stored validators:
     *   ['etag' => ?string, 'last_modified' => ?string]
     *
     * @return array{
     *   ok: bool, blocked: bool, status: ?int, not_modified: bool, body: string,
     *   etag: ?string, last_modified: ?string, content_type: ?string,
     *   headers: array<string,string>, redirected: bool, redirect_target: ?string,
     *   redirect_chain: int, ttfb_ms: int, error: ?string
     * }
     */
    public function fetch(string $url, array $conditional = [], int $timeout = 20, ?string $proxy = null): array
    {
        $startedAt = microtime(true);
        $base = [
            'ok' => false, 'blocked' => false, 'status' => null, 'not_modified' => false,
            'body' => '', 'etag' => null, 'last_modified' => null, 'content_type' => null,
            'headers' => [], 'redirected' => false, 'redirect_target' => null,
            'redirect_chain' => 0, 'ttfb_ms' => 0, 'error' => null,
            'proxy_used' => $proxy,
        ];

        $guardCheck = $this->guard->check($url);
        if (! $guardCheck['ok']) {
            return array_merge($base, [
                'blocked' => true,
                'error' => 'blocked: '.($guardCheck['reason'] ?? 'unsafe_url'),
            ]);
        }

        $headers = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
        if (! empty($conditional['etag'])) {
            $headers['If-None-Match'] = (string) $conditional['etag'];
        }
        if (! empty($conditional['last_modified'])) {
            $headers['If-Modified-Since'] = (string) $conditional['last_modified'];
        }

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(10)
                ->withUserAgent(self::UA)
                ->withHeaders($headers)
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
                    // Anti-blocking: route this fetch through a pool proxy when set.
                    ...($proxy !== null ? ['proxy' => $proxy] : []),
                ])
                ->get($url);

            $ttfb = (int) round((microtime(true) - $startedAt) * 1000);
            $status = $response->status();

            if ($status === 304) {
                return array_merge($base, ['ok' => true, 'status' => 304, 'not_modified' => true, 'ttfb_ms' => $ttfb]);
            }

            $fullBody = (string) $response->body();
            $body = strlen($fullBody) > self::MAX_BODY_BYTES ? substr($fullBody, 0, self::MAX_BODY_BYTES) : $fullBody;

            // Redirect chain (Guzzle RedirectMiddleware history headers).
            $history = array_filter(array_map('trim', explode(',', (string) $response->header('X-Guzzle-Redirect-History'))));
            $redirected = $history !== [];
            $redirectTarget = $redirected ? (string) end($history) : null;

            $keep = [];
            foreach (['server', 'x-powered-by', 'x-generator', 'via', 'cf-ray', 'cf-cache-status', 'cf-mitigated', 'x-robots-tag', 'content-type',
                      'x-sucuri-id', 'x-sucuri-cache', 'x-sg-cdn', 'x-iinfo', 'x-amz-cf-id', 'x-akamai-transformed'] as $h) {
                $v = (string) $response->header($h);
                if ($v !== '') {
                    $keep[$h] = $v;
                }
            }

            return array_merge($base, [
                'ok' => true,
                'status' => $status,
                'body' => $body,
                'etag' => ($e = (string) $response->header('ETag')) !== '' ? $e : null,
                'last_modified' => ($lm = (string) $response->header('Last-Modified')) !== '' ? $lm : null,
                'content_type' => $keep['content-type'] ?? null,
                'headers' => $keep,
                'redirected' => $redirected,
                'redirect_target' => $redirectTarget,
                'redirect_chain' => count($history),
                'ttfb_ms' => $ttfb,
            ]);
        } catch (\Throwable $e) {
            return array_merge($base, [
                'error' => $e->getMessage(),
                'ttfb_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }
}
