<?php

namespace App\Services\Crawler;

use App\Support\Audit\SafeHttpGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the self-hosted Firecrawl render server
 * (infra/reference/firecrawl-server.md, private http://10.0.0.4:3002). Renders a
 * URL with a headless browser through the residential proxy and returns its HTML
 * — the last-resort fetch for JS / Cloudflare-challenged sites the HTTP crawler
 * can't read.
 *
 * Off unless `services.firecrawl.enabled` + `url` are set, so nothing changes
 * where it isn't configured. SSRF-guards the target BEFORE calling (we hand
 * Firecrawl arbitrary client URLs). Retries once on an upstream 5xx (the
 * occasional transient Cloudflare 520). Never throws.
 */
class FirecrawlClient
{
    public function __construct(private readonly SafeHttpGuard $guard) {}

    public function enabled(): bool
    {
        return (bool) config('services.firecrawl.enabled')
            && trim((string) config('services.firecrawl.url')) !== '';
    }

    /**
     * The REAL HTTP status a browser sees for this URL — the render server
     * fetches through a residential exit with a full browser, so a host that
     * feeds our datacenter IP a 403/404 answers honestly here.
     *
     * Used as the final adjudicator in the external-link check (LinkChecker):
     * only links that still look dead after HEAD → GET → proxy-GET get this
     * far, so the cost stays bounded. Null = disabled / guard-blocked /
     * couldn't decide, which callers must treat as INCONCLUSIVE.
     */
    public function status(string $url): ?int
    {
        if (! $this->enabled()) {
            return null;
        }
        if (! ($this->guard->check($url)['ok'] ?? false)) {
            // A guard failure here is a URL-shape problem, except DNS: the
            // caller handles that case and asks us precisely because its own
            // resolver failed, so re-checking DNS would defeat the purpose.
            if (($this->guard->check($url)['reason'] ?? '') !== 'dns_resolution_failed') {
                return null;
            }
        }

        $base = rtrim((string) config('services.firecrawl.url'), '/');
        $key = (string) config('services.firecrawl.key');
        $timeout = max(10, (int) config('services.firecrawl.timeout_s', 45));

        try {
            $req = Http::timeout($timeout + 10)->asJson();
            if ($key !== '') {
                $req = $req->withToken($key);
            }
            $json = $req->post($base.'/v1/scrape', [
                'url' => $url,
                'formats' => ['html'],
                'timeout' => $timeout * 1000,
            ])->json();

            $upstream = $json['data']['metadata']['statusCode'] ?? null;

            return is_numeric($upstream) ? (int) $upstream : null;
        } catch (\Throwable $e) {
            Log::info('firecrawl.status_error', ['url' => $url, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    /** Rendered HTML, or null if disabled / guard-blocked / render failed. */
    public function html(string $url): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        if (! ($this->guard->check($url)['ok'] ?? false)) {
            return null;
        }

        $base = rtrim((string) config('services.firecrawl.url'), '/');
        $key = (string) config('services.firecrawl.key');
        $timeout = max(10, (int) config('services.firecrawl.timeout_s', 45));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $req = Http::timeout($timeout + 10)->asJson();
                if ($key !== '') {
                    $req = $req->withToken($key);
                }
                $resp = $req->post($base.'/v1/scrape', [
                    'url' => $url,
                    'formats' => ['html'],
                    'timeout' => $timeout * 1000,
                ]);

                $json = $resp->json();
                $upstream = (int) ($json['data']['metadata']['statusCode'] ?? $resp->status());
                $html = (string) ($json['data']['html'] ?? '');

                if (($json['success'] ?? false) === true && $upstream < 500 && trim($html) !== '') {
                    return $html;
                }
                // Transient upstream 5xx (e.g. Cloudflare 520) → one retry.
                if ($upstream >= 500 && $attempt === 1) {
                    continue;
                }
                Log::info('firecrawl.miss', ['url' => $url, 'upstream' => $upstream, 'success' => $json['success'] ?? null]);

                return null;
            } catch (\Throwable $e) {
                Log::warning('firecrawl.error', ['url' => $url, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                if ($attempt === 1) {
                    continue;
                }

                return null;
            }
        }

        return null;
    }
}
