<?php

namespace App\Jobs;

use App\Models\LinkCrawlFrontier;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\DomainRateLimiter;
use App\Services\Crawler\ProxyPool;
use App\Services\LinkGraph\EdgeRecorder;
use App\Services\LinkGraph\LinkCrawlBudget;
use App\Support\Audit\HtmlAuditor;
use App\Support\Crawler\BlockDetector;
use App\Support\Crawler\RobotsTxtParser;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fetches a batch of frontier URLs and deposits their OUTBOUND links into the
 * permanent link graph. Per URL: robots.txt check → per-host politeness
 * (DomainRateLimiter) → SSRF-guarded fetch (proxy-first) → extract external
 * links → EdgeRecorder::record. Depth-0 (homepage) rows also enqueue a few
 * internal links (bounded per host) so we see more of the site's outbound
 * links. Every page charges the shared daily budget.
 *
 * Reuses the site-audit crawler's whole toolchain — this job only adds the
 * frontier bookkeeping.
 */
class LinkCrawlBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  list<int>  $ids  link_crawl_frontier row ids */
    public function __construct(public array $ids)
    {
    }

    public function handle(
        CrawlFetcher $fetcher,
        DomainRateLimiter $rateLimiter,
        ProxyPool $pool,
        EdgeRecorder $edges,
        LinkCrawlBudget $budget,
        BlockDetector $blockDetector,
    ): void {
        if (! \App\Support\LinkCrawlToggle::enabled() || $budget->exhausted()) {
            return;
        }

        $rows = LinkCrawlFrontier::query()->whereIn('id', $this->ids)->where('status', 'pending')->get();
        $maxAttempts = (int) config('crawler.link_crawl.max_attempts', 3);
        $retryAfter = (int) config('crawler.link_crawl.retry_after_hours', 72);
        $recrawlDays = (int) config('crawler.link_crawl.recrawl_days', 30);
        $delayMs = (int) config('crawler.delay_ms', 250);

        foreach ($rows as $row) {
            if ($budget->exhausted()) {
                break;
            }

            try {
                if ($this->robotsBlocks($fetcher, $row->host, $row->url)) {
                    $row->update(['status' => 'blocked']);

                    continue;
                }

                [$res, $blocked] = $this->fetchWithPolicy($fetcher, $rateLimiter, $pool, $blockDetector, $row);

                if ($blocked !== null) {
                    // WAF/Cloudflare/anti-bot ban on this domain. Never spend
                    // more of the crawl on it — mark blocked (revisited on the
                    // recrawl cycle, when its cooldown has long expired), don't
                    // burn budget/retries chasing a wall.
                    $row->update(['status' => 'blocked']);

                    continue;
                }

                if (! ($res['ok'] ?? false) || ! is_string($res['body'] ?? null) || $res['body'] === '') {
                    $this->reschedule($row, $maxAttempts, $retryAfter);

                    continue;
                }

                $links = (new HtmlAuditor($res['body'], $row->url))->links();
                $edges->record($row->url, $links['external'] ?? [], EdgeRecorder::SOURCE_OWN_CRAWL);
                $budget->consume(1);

                // Homepage → seed a few internal pages to find more outbound links.
                if ((int) $row->depth === 0) {
                    $this->enqueueInternal($row->host, $links['internal'] ?? []);
                }

                $row->update([
                    'status' => 'done',
                    'attempts' => $row->attempts + 1,
                    'next_at' => now()->addDays($recrawlDays),
                ]);
            } catch (\Throwable $e) {
                $this->reschedule($row, $maxAttempts, $retryAfter);
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
    }

    /**
     * Fetch with the full anti-block policy (mirrors PageCrawlProcessor::
     * fetchWithPolicy): per-domain rate throttle, proxy-first (never expose
     * the box IP if the pool has one), classify the response for
     * Cloudflare/WAF/anti-bot blocks, engage WAF slow-mode, record blocks
     * fleet-wide so every box backs off that domain, and one proxy retry.
     *
     * @return array{0: array<string,mixed>, 1: ?string}  [response, blockReason|null]
     */
    private function fetchWithPolicy(
        CrawlFetcher $fetcher,
        DomainRateLimiter $rateLimiter,
        ProxyPool $pool,
        BlockDetector $blockDetector,
        LinkCrawlFrontier $row,
    ): array {
        $timeout = (int) config('crawler.timeout', 20);
        $domain = $row->host;

        // Per-server per-domain politeness; also eases to slow-mode if the
        // domain is already flagged blocked/WAF fleet-wide.
        $rateLimiter->throttle($domain);

        $useProxies = (bool) config('crawler.use_proxies', true);
        $proxy = ($useProxies && $pool->available()) ? $pool->pick() : null;

        $t0 = microtime(true);
        $res = $fetcher->fetch($row->url, [], $timeout, $proxy);
        $rateLimiter->recordFetch($domain, (int) round((microtime(true) - $t0) * 1000));

        $blocked = ($res['ok'] ?? false) ? $blockDetector->classify([
            'status' => (int) ($res['status'] ?? 0), 'body' => (string) ($res['body'] ?? ''), 'headers' => $res['headers'] ?? [],
        ]) : null;

        if ($blocked === null) {
            if ($proxy !== null && ($res['ok'] ?? false)) {
                $pool->markSuccess($proxy);
            }
            // Detect Cloudflare/Akamai/etc on a SUCCESSFUL fetch → slow-mode
            // (1 req / 2s) so we stay under the WAF's radar next time.
            if (($res['ok'] ?? false) && ($waf = $blockDetector->detectWaf(['headers' => $res['headers'] ?? []])) !== null
                && ! $rateLimiter->isWafProtected($domain)) {
                $rateLimiter->recordWaf($domain, $waf);
            }

            return [$res, null];
        }

        // Blocked. Record fleet-wide so other boxes go cautious on this domain.
        $rateLimiter->recordBlock($domain);
        if ($proxy !== null) {
            $pool->markFailure($proxy);
        }

        // One proxy retry if we went direct and the pool has an IP (a fresh
        // egress IP often clears a soft block).
        if ($useProxies && $proxy === null && $pool->available()) {
            $retryProxy = $pool->pick();
            if ($retryProxy !== null) {
                $res2 = $fetcher->fetch($row->url, [], $timeout, $retryProxy);
                $blocked2 = ($res2['ok'] ?? false) ? $blockDetector->classify([
                    'status' => (int) ($res2['status'] ?? 0), 'body' => (string) ($res2['body'] ?? ''), 'headers' => $res2['headers'] ?? [],
                ]) : null;
                if ($blocked2 === null && ($res2['ok'] ?? false)) {
                    $pool->markSuccess($retryProxy);

                    return [$res2, null];
                }
                $pool->markFailure($retryProxy);
            }
        }

        return [$res, $blocked];
    }

    /** robots.txt for a host, fetched once and cached fleet-wide (6h). */
    private function robotsBlocks(CrawlFetcher $fetcher, string $host, string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $robots = Cache::remember('linkcrawl:robots:'.$host, now()->addHours(6), function () use ($fetcher, $host) {
            $res = $fetcher->fetch('https://'.$host.'/robots.txt', [], 10);
            return ($res['ok'] ?? false) && (int) ($res['status'] ?? 0) === 200 ? (string) ($res['body'] ?? '') : '';
        });

        return $robots !== '' && RobotsTxtParser::isBlocked($robots, $path);
    }

    /**
     * @param  list<array{href: string, anchor?: string, nofollow?: bool}>  $internal
     */
    private function enqueueInternal(string $host, array $internal): void
    {
        $cap = (int) config('crawler.link_crawl.internal_links_followed', 6);
        $perHost = (int) config('crawler.link_crawl.max_pages_per_host', 12);
        if ($cap <= 0 || $internal === []) {
            return;
        }

        $already = LinkCrawlFrontier::query()->where('host', $host)->count();
        if ($already >= $perHost) {
            return;
        }

        $now = now();
        $insert = [];
        foreach ($internal as $link) {
            if (count($insert) >= min($cap, $perHost - $already)) {
                break;
            }
            $u = (string) ($link['href'] ?? '');
            // Same-host http(s) pages only; skip the homepage we already have.
            $h = strtolower((string) parse_url($u, PHP_URL_HOST));
            if ($u === '' || $h !== strtolower($host) || rtrim($u, '/') === 'https://'.$host) {
                continue;
            }
            $insert[] = [
                'host' => strtolower($host),
                'url' => mb_substr($u, 0, 2048),
                'url_hash' => LinkCrawlFrontier::hashFor($u),
                'depth' => 1,
                'status' => 'pending',
                'attempts' => 0,
                'next_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($insert !== []) {
            DB::table('link_crawl_frontier')->insertOrIgnore($insert);
        }
    }

    private function reschedule(LinkCrawlFrontier $row, int $maxAttempts, int $retryAfterHours): void
    {
        $attempts = $row->attempts + 1;
        $row->update($attempts >= $maxAttempts
            ? ['status' => 'failed', 'attempts' => $attempts]
            : ['attempts' => $attempts, 'next_at' => now()->addHours($retryAfterHours)]);
    }
}
