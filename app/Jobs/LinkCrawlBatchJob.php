<?php

namespace App\Jobs;

use App\Models\LinkCrawlFrontier;
use App\Services\Crawler\CrawlFetcher;
use App\Services\Crawler\DomainRateLimiter;
use App\Services\Crawler\ProxyPool;
use App\Services\LinkGraph\EdgeRecorder;
use App\Services\LinkGraph\FrontierClaimer;
use App\Services\LinkGraph\LinkCrawlBudget;
use App\Support\Audit\HtmlAuditor;
use App\Support\Crawler\BlockDetector;
use App\Support\Crawler\RobotsTxtParser;
use App\Support\LinkCrawlToggle;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One concurrent crawl worker: atomically CLAIMS a batch of due frontier URLs
 * (FrontierClaimer — no two batches ever grab the same URL) and deposits their
 * OUTBOUND links into the permanent link graph. Per URL: robots.txt → per-host
 * politeness (DomainRateLimiter) → block-aware proxy-first fetch → extract
 * external links → EdgeRecorder::record. Depth-0 homepages seed a few internal
 * pages. Every page charges the shared daily budget.
 *
 * SELF-REPLACING: when a batch claims work, it dispatches ONE replacement batch
 * before crawling — so the fleet stays saturated with no pass barrier (the
 * slowest domain only slows its own batch). The dispatcher tops up the rest.
 * Runs on the dedicated `link-crawl` queue.
 */
class LinkCrawlBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(Queues::LINK_CRAWL);
    }

    public function handle(
        CrawlFetcher $fetcher,
        DomainRateLimiter $rateLimiter,
        ProxyPool $pool,
        EdgeRecorder $edges,
        LinkCrawlBudget $budget,
        BlockDetector $blockDetector,
        FrontierClaimer $claimer,
    ): void {
        if (! LinkCrawlToggle::enabled() || $budget->exhausted()) {
            return;
        }

        $rows = $claimer->claim((int) config('crawler.link_crawl.batch_size', 20));
        if ($rows->isEmpty()) {
            return; // frontier drained — do NOT self-replace (pool winds down)
        }

        // Keep the fleet saturated: one working batch → one successor. Guarded
        // by enabled/budget/claim-empty above, so the loop self-terminates when
        // work or budget runs out. Dispatched up front so a mid-crawl crash
        // still leaves a successor (the crashed batch's rows get reaped).
        self::dispatch();

        $maxAttempts = (int) config('crawler.link_crawl.max_attempts', 3);
        $retryAfter = (int) config('crawler.link_crawl.retry_after_hours', 72);
        $recrawlDays = (int) config('crawler.link_crawl.recrawl_days', 30);
        $delayMs = (int) config('crawler.delay_ms', 250);

        foreach ($rows as $row) {
            if ($budget->exhausted()) {
                // Release the unfetched claim so it isn't stranded until reaping.
                $this->reschedule($row, $maxAttempts, 1);

                continue;
            }

            try {
                if ($this->robotsBlocks($fetcher, $row->host, $row->url)) {
                    $this->finish($row, 'blocked');

                    continue;
                }

                [$res, $blocked] = $this->fetchWithPolicy($fetcher, $rateLimiter, $pool, $blockDetector, $row);

                if ($blocked !== null) {
                    // WAF/Cloudflare/anti-bot ban on this domain — mark blocked
                    // (revisited on the recrawl cycle, cooldown long expired);
                    // don't burn budget/retries chasing a wall.
                    $this->finish($row, 'blocked');

                    continue;
                }

                if (! ($res['ok'] ?? false) || ! is_string($res['body'] ?? null) || $res['body'] === '') {
                    $this->reschedule($row, $maxAttempts, $retryAfter);

                    continue;
                }

                $links = (new HtmlAuditor($res['body'], $row->url))->links();
                $edges->record($row->url, $links['external'] ?? [], EdgeRecorder::SOURCE_OWN_CRAWL);
                $budget->consume(1);

                if ((int) $row->depth === 0) {
                    $this->enqueueInternal($row->host, $links['internal'] ?? []);
                }

                $this->finish($row, 'done', attempts: $row->attempts + 1, nextAt: now()->addDays($recrawlDays));
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
     * fetchWithPolicy): per-domain throttle, proxy-first, classify for
     * Cloudflare/WAF/anti-bot blocks, WAF slow-mode, fleet-wide block cooldown,
     * one proxy retry.
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
            if (($res['ok'] ?? false) && ($waf = $blockDetector->detectWaf(['headers' => $res['headers'] ?? []])) !== null
                && ! $rateLimiter->isWafProtected($domain)) {
                $rateLimiter->recordWaf($domain, $waf);
            }

            return [$res, null];
        }

        $rateLimiter->recordBlock($domain);
        if ($proxy !== null) {
            $pool->markFailure($proxy);
        }

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

    /** Terminal state for a claimed row — always clears the lease. */
    private function finish(LinkCrawlFrontier $row, string $status, ?int $attempts = null, ?Carbon $nextAt = null): void
    {
        $update = ['status' => $status, 'lease_id' => null, 'leased_until' => null];
        if ($attempts !== null) {
            $update['attempts'] = $attempts;
        }
        if ($nextAt !== null) {
            $update['next_at'] = $nextAt;
        }
        $row->update($update);
    }

    /** Transient failure: back to `pending` (lease cleared) for retry, or `failed` at the cap. */
    private function reschedule(LinkCrawlFrontier $row, int $maxAttempts, int $retryAfterHours): void
    {
        $attempts = $row->attempts + 1;
        if ($attempts >= $maxAttempts) {
            $this->finish($row, 'failed', attempts: $attempts);

            return;
        }
        $row->update([
            'status' => 'pending',
            'attempts' => $attempts,
            'next_at' => now()->addHours(max(1, $retryAfterHours)),
            'lease_id' => null,
            'leased_until' => null,
        ]);
    }
}
