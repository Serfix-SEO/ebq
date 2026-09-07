<?php

namespace App\Services\Content;

use App\Services\Crawler\FirecrawlClient;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pre-flight HTTP verification for every URL that gets handed to (or kept in)
 * an article: internal page candidates, catalog product links, and external
 * citations. A link that 404s in a client's article is worse than no link.
 *
 * Only a DEFINITIVE 404/410 counts as dead — timeouts, DNS blips, 403
 * bot-walls and 5xx keep the URL (a transient failure must never drop a
 * legitimate target). A plain-fetch 404/410 is additionally ADJUDICATED
 * through the self-hosted Firecrawl render server (headless browser via a
 * residential exit) before the verdict sticks: many hosts feed datacenter
 * IPs a fake 404 while serving the page fine to browsers (LinkChecker
 * precedent, prod 2026-08-16). Firecrawl says <400 → alive; Firecrawl
 * confirms 404/410 (or is unavailable) → dead stands. Verdicts are cached
 * (alive 1 day, dead 7 days) so the produce → revise → strip chain
 * re-checks nothing.
 *
 * `features.article_link_verify` is the kill-switch; the test suite pins it
 * off in phpunit.xml so pipeline tests never issue real HTTP.
 */
class LinkVerifier
{
    private const DEAD_STATUSES = [404, 410];

    private const MAX_CHECKS = 25;

    /** Render adjudications per deadSet() call — suspected-deads are rare. */
    private const MAX_RENDER_CHECKS = 5;

    public function __construct(private readonly FirecrawlClient $firecrawl) {}

    /**
     * Return only the URLs that are not definitively dead, preserving order.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    public function filterAlive(array $urls): array
    {
        $dead = $this->deadSet($urls);

        return array_values(array_filter($urls, static fn ($u) => ! isset($dead[$u])));
    }

    public function isDead(string $url): bool
    {
        return $this->deadSet([$url]) !== [];
    }

    /**
     * The definitively-dead subset, as a set keyed by URL.
     *
     * @param  list<string>  $urls
     * @return array<string, true>
     */
    public function deadSet(array $urls): array
    {
        if (! (bool) config('features.article_link_verify', true)) {
            return [];
        }

        $urls = array_values(array_unique(array_filter($urls, static fn ($u) => (bool) preg_match('#^https?://#i', (string) $u))));
        $urls = array_slice($urls, 0, self::MAX_CHECKS);

        // Cache first — the whole produce/revise/strip chain shares verdicts.
        $unknown = [];
        $dead = [];
        foreach ($urls as $url) {
            $cached = Cache::get($this->key($url));
            if ($cached === 'dead') {
                $dead[$url] = true;
            } elseif ($cached !== 'alive') {
                $unknown[] = $url;
            }
        }

        $renderChecks = 0;
        foreach (array_chunk($unknown, 10) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn ($url) => $pool->as($url)
                        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SerfixBot)'])
                        ->timeout(10)->connectTimeout(5)->withoutVerifying()
                        ->get($url),
                    $chunk,
                ));
            } catch (\Throwable $e) {
                Log::debug('content_link_verify.pool_failed', ['error' => mb_substr($e->getMessage(), 0, 120)]);

                continue; // benefit of the doubt for the whole chunk
            }

            foreach ($chunk as $url) {
                $response = $responses[$url] ?? null;
                // A throwable in the pool slot (timeout/DNS) → keep the link.
                $isDead = $response instanceof \Illuminate\Http\Client\Response
                    && in_array($response->status(), self::DEAD_STATUSES, true);
                if ($isDead && $this->renderSaysAlive($url, $renderChecks)) {
                    $isDead = false;
                }
                if ($isDead) {
                    $dead[$url] = true;
                    Cache::put($this->key($url), 'dead', now()->addDays(7));
                    Log::info('content_link_verify.dead', ['url' => $url]);
                } else {
                    Cache::put($this->key($url), 'alive', now()->addDay());
                }
            }
        }

        return $dead;
    }

    /**
     * Firecrawl adjudication for a suspected-dead URL: the render server sees
     * what a real browser sees, so a bot-walled host that fakes 404 to our
     * datacenter IP answers honestly. TRUE = the page is actually alive.
     * Inconclusive (disabled, budget spent, render error) → false: the
     * plain-fetch verdict stands.
     */
    private function renderSaysAlive(string $url, int &$renderChecks): bool
    {
        if ($renderChecks >= self::MAX_RENDER_CHECKS || ! $this->firecrawl->enabled()) {
            return false;
        }
        $renderChecks++;
        $status = $this->firecrawl->status($url);
        if ($status !== null && $status < 400) {
            Log::info('content_link_verify.render_overruled', ['url' => $url, 'status' => $status]);

            return true;
        }

        return false;
    }

    private function key(string $url): string
    {
        return 'content:linkcheck:'.sha1($url);
    }
}
