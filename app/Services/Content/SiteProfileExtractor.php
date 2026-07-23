<?php

namespace App\Services\Content;

use App\Models\ContentPlan;
use App\Models\Website;
use App\Support\Audit\SafeHttpGuard;
use App\Support\ContentAutopilotConfig;
use App\Support\ContentSiteTypeProfiles;
use App\Services\Llm\LlmClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Auto-detects the Content Autopilot business profile from data the crawler
 * already collected: ONE cheap LLM call over the homepage + top pages turns
 * crawl signals into {description, sell[], dont_sell[], site_type, audience}
 * for the setup wizard. The client edits the result — this is a pre-fill,
 * not a lock-in (site_type has an explicit confirm chip on step 1).
 *
 * Cached 7 days per website (repeat wizard visits must not re-bill).
 * Fails soft: no crawl data / no LLM => nulls, wizard falls back to the
 * homepage meta description and manual entry; a null site_type means the
 * whole pipeline behaves exactly as it did before site types existed.
 */
class SiteProfileExtractor
{
    /** @return array{description:?string, sell:list<string>, dont_sell:list<string>, site_type:?string, audience:?string} */
    public function extract(Website $website): array
    {
        $empty = ['description' => null, 'sell' => [], 'dont_sell' => [], 'site_type' => null, 'audience' => null, 'ymyl' => null];

        // v2: payload gained site_type + audience — old v1 entries lack the
        // keys, so the version bump (not a flush) retires them naturally.
        return Cache::remember(
            'content:site-profile:v2:'.$website->id,
            now()->addDays(7),
            function () use ($website, $empty): array {
                // Prefer crawled pages; but a freshly-added site (e.g. anonymous
                // onboarding) has no crawl yet — fall back to a live homepage
                // fetch so the wizard still pre-fills immediately.
                $signals = $this->crawlSignals($website);
                if ($signals === []) {
                    $signals = $this->liveSignals($website);
                }
                if ($signals === []) {
                    return $empty;
                }

                $llm = LlmClientFactory::make(ContentAutopilotConfig::modelFor('ideate')['provider']);
                if (! $llm->isAvailable()) {
                    // No LLM (e.g. staging) — still seed a plain description from
                    // the homepage title/meta so step 1 isn't blank.
                    return $this->rawProfile($signals, $empty);
                }

                $pagesBlock = implode("\n", array_map(
                    static fn ($p) => '- '.$p['title'].($p['meta'] !== '' ? ' — '.$p['meta'] : ''),
                    $signals
                ));

                $typeList = implode('|', ContentSiteTypeProfiles::TYPES);
                $response = $llm->completeJson([
                    ['role' => 'system', 'content' => 'You analyze websites for a content-marketing tool. Respond with valid JSON only.'],
                    ['role' => 'user', 'content' => <<<PROMPT
                    Based ONLY on these real pages from {$website->domain}, describe the business.

                    PAGES (title — meta description):
                    {$pagesBlock}

                    Return JSON:
                    {
                      "description": "2-3 sentences, plain language, what the site offers and for whom",
                      "sell": ["4-8 concrete products/services/tools the site clearly offers, most important first"],
                      "dont_sell": ["2-5 closely related things the site does NOT appear to offer (so articles never promise them)"],
                      "site_type": "one of: {$typeList} — brand = sells its OWN products; ecommerce_reseller = shop selling OTHER brands; affiliate = reviews/comparisons monetized via links; local_service = customers book/call locally; tool = free online tool/generator/calculator used right in the browser (no signup needed); saas = software people sign up/pay for; use 'other' when unsure",
                      "audience": "one sentence: who buys from or reads this site",
                      "ymyl": "boolean — does the site's subject affect readers' health, money, safety or legal standing?"
                    }
                    Be specific to THIS site. Never invent offerings the pages don't support.
                    PROMPT],
                ], [
                    'temperature' => 0.3,
                    'max_tokens' => 900,
                    'timeout' => 45,
                    '__source' => 'content_autopilot.site_profile',
                    '__unmetered' => true,
                ]);
                app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

                if (! is_array($response)) {
                    return $empty;
                }

                $clean = static fn ($list) => array_slice(array_values(array_filter(array_map(
                    static fn ($v) => trim((string) $v),
                    is_array($list) ? $list : []
                ))), 0, 8);

                $siteType = is_string($response['site_type'] ?? null) ? strtolower(trim($response['site_type'])) : null;

                return [
                    'description' => is_string($response['description'] ?? null)
                        ? mb_substr(trim($response['description']), 0, 1000) : null,
                    'sell' => $clean($response['sell'] ?? []),
                    'dont_sell' => $clean($response['dont_sell'] ?? []),
                    'site_type' => ContentSiteTypeProfiles::isValid($siteType) ? $siteType : null,
                    'audience' => is_string($response['audience'] ?? null)
                        ? mb_substr(trim($response['audience']), 0, 500) : null,
                    'ymyl' => is_bool($response['ymyl'] ?? null) ? $response['ymyl'] : null,
                ];
            }
        );
    }

    /**
     * Classify an ALREADY-ONBOARDED plan from its stored profile text — no
     * page fetches, one flash call. Used by the backfill command
     * (`ebq:content-classify-plans`) for plans created before site types
     * existed. Returns null on any failure; never throws.
     *
     * @return ?array{site_type:string, audience:?string}
     */
    public function classifyStoredProfile(ContentPlan $plan): ?array
    {
        $description = trim((string) $plan->business_description);
        if ($description === '') {
            return null;
        }

        try {
            $llm = LlmClientFactory::make(ContentAutopilotConfig::modelFor('ideate')['provider']);
            if (! $llm->isAvailable()) {
                return null;
            }

            $offerings = (array) ($plan->offerings ?? []);
            $sell = implode('; ', array_slice((array) ($offerings['sell'] ?? []), 0, 8));
            $typeList = implode('|', ContentSiteTypeProfiles::TYPES);

            $response = $llm->completeJson([
                ['role' => 'system', 'content' => 'You classify websites for a content-marketing tool. Respond with valid JSON only.'],
                ['role' => 'user', 'content' => <<<PROMPT
                Classify this business.

                DESCRIPTION: {$description}
                OFFERS: {$sell}

                Return JSON:
                {
                  "site_type": "one of: {$typeList} — brand = sells its OWN products; ecommerce_reseller = shop selling OTHER brands; affiliate = reviews/comparisons monetized via links; local_service = customers book/call locally; tool = free online tool/generator/calculator used right in the browser (no signup needed); saas = software people sign up/pay for; use 'other' when unsure",
                  "audience": "one sentence: who buys from or reads this site",
                  "ymyl": "boolean — does the site's subject affect readers' health, money, safety or legal standing?"
                }
                PROMPT],
            ], [
                'temperature' => 0.2,
                'max_tokens' => 200,
                'timeout' => 30,
                '__source' => 'content_autopilot.site_type_backfill',
                '__unmetered' => true,
            ]);
            app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

            $type = is_array($response) && is_string($response['site_type'] ?? null)
                ? strtolower(trim($response['site_type'])) : null;
            if (! ContentSiteTypeProfiles::isValid($type)) {
                return null;
            }

            return [
                'site_type' => $type,
                'audience' => is_array($response) && is_string($response['audience'] ?? null)
                    ? mb_substr(trim($response['audience']), 0, 500) : null,
                'ymyl' => is_array($response) && is_bool($response['ymyl'] ?? null) ? $response['ymyl'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Live homepage fetch fallback (no crawl data yet). SSRF-guarded, short
     * timeout, fails soft to []. Parses <title>, meta/og description and the
     * first few headings so the LLM (or the raw fallback) has something to work
     * with during onboarding.
     *
     * 2026-07-22 (kayali.com/en-ae incident): two upgrades so bot-hostile /
     * region-routed sites still yield a profile —
     *  (a) the URL the visitor actually TYPED (path included) is preferred
     *      when the funnel cached it (`content:entered-url:{website_id}`) —
     *      kayali.com's root is a bare 302 to /en-ae where the content lives;
     *  (b) each candidate URL is fetched with redirect-following (every hop
     *      re-checked through SafeHttpGuard, same policy as CrawlFetcher) and
     *      retried with a browser-like User-Agent when the honest bot UA is
     *      blocked (Shopify-class bot protection).
     *
     * @return list<array{title:string, meta:string}>
     */
    private function liveSignals(Website $website): array
    {
        try {
            $host = $website->normalized_domain ?: $website->domain;
            if (! $host) {
                return [];
            }

            $candidates = [];
            $entered = Cache::get('content:entered-url:'.$website->id);
            if (is_string($entered) && $entered !== '') {
                $candidates[] = $entered;
            }
            $candidates[] = 'https://'.preg_replace('#^https?://#i', '', (string) $host);

            $html = null;
            foreach (array_values(array_unique($candidates)) as $url) {
                $html = $this->fetchFollowingRedirects($url);
                if ($html !== null) {
                    break;
                }
            }
            if ($html === null) {
                return [];
            }

            $title = '';
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
                $title = $this->cleanText($m[1]);
            }
            $meta = '';
            if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is', $html, $m)
                || preg_match('#<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']#is', $html, $m)
                || preg_match('#<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']#is', $html, $m)) {
                $meta = $this->cleanText($m[1]);
            }

            $signals = [];
            if ($title !== '' || $meta !== '') {
                $signals[] = ['title' => mb_substr($title !== '' ? $title : (string) $host, 0, 120), 'meta' => mb_substr($meta, 0, 200)];
            }
            // A couple of H1/H2 headings as extra "pages" to hint offerings.
            if (preg_match_all('#<h[12][^>]*>(.*?)</h[12]>#is', $html, $mm) && ! empty($mm[1])) {
                foreach (array_slice($mm[1], 0, 6) as $h) {
                    $h = $this->cleanText($h);
                    if (mb_strlen($h) >= 3 && mb_strlen($h) <= 120) {
                        $signals[] = ['title' => $h, 'meta' => ''];
                    }
                }
            }

            return $signals;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * GET a URL following up to 4 redirect hops MANUALLY — each hop's target
     * is re-checked through SafeHttpGuard so a redirect can never bounce the
     * fetch into private address space (same per-hop policy as CrawlFetcher).
     * The honest bot UA goes first; a blocked/refused response (403/406/429/
     * 5xx or transport error) retries once with a browser-like UA, because
     * Shopify-class bot protection rejects unknown bots outright.
     */
    private function fetchFollowingRedirects(string $url): ?string
    {
        $agents = [
            'SerfixBot/1.0 (+https://serfix.io)',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        ];

        foreach ($agents as $ua) {
            $current = $url;
            for ($hop = 0; $hop <= 4; $hop++) {
                if (! (app(SafeHttpGuard::class)->check($current)['ok'] ?? false)) {
                    break;
                }
                try {
                    $res = Http::timeout(8)
                        ->withHeaders(['User-Agent' => $ua, 'Accept' => 'text/html,application/xhtml+xml'])
                        ->withOptions(['allow_redirects' => false])
                        ->get($current);
                } catch (\Throwable) {
                    break; // transport error — try the next UA
                }

                if ($res->redirect()) {
                    $location = (string) $res->header('Location');
                    if ($location === '') {
                        break;
                    }
                    // Resolve relative Locations against the current URL.
                    if (! preg_match('#^https?://#i', $location)) {
                        $parts = parse_url($current);
                        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
                        $location = str_starts_with($location, '/')
                            ? $base.$location
                            : rtrim($base.(dirname($parts['path'] ?? '/') ?: ''), '/').'/'.$location;
                    }
                    $current = $location;

                    continue;
                }

                if ($res->ok() && trim((string) $res->body()) !== '') {
                    return (string) $res->body();
                }

                break; // blocked / error — try the next UA from the top URL
            }
        }

        return null;
    }

    /** Strip tags/entities/whitespace from an HTML fragment. */
    private function cleanText(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * When no LLM is available, build a plain description from the homepage
     * signals (meta preferred, else title) so the wizard is never blank.
     *
     * @param  list<array{title:string, meta:string}>  $signals
     * @param  array{description:?string, sell:list<string>, dont_sell:list<string>, site_type:?string, audience:?string}  $empty
     * @return array{description:?string, sell:list<string>, dont_sell:list<string>, site_type:?string, audience:?string}
     */
    private function rawProfile(array $signals, array $empty): array
    {
        $first = $signals[0] ?? null;
        if ($first === null) {
            return $empty;
        }
        $desc = $first['meta'] !== '' ? $first['meta'] : $first['title'];

        return array_merge($empty, ['description' => $desc !== '' ? mb_substr($desc, 0, 1000) : null]);
    }

    /** @return list<array{title:string, meta:string}> top crawled pages */
    private function crawlSignals(Website $website): array
    {
        try {
            if (! $website->crawl_site_id) {
                return [];
            }

            return DB::table('website_pages')
                ->where('crawl_site_id', $website->crawl_site_id)
                ->where('http_status', 200)
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->orderByDesc('inbound_link_count')
                ->limit(20)
                ->get(['title', 'meta_description'])
                ->map(fn ($p) => [
                    'title' => mb_substr((string) $p->title, 0, 120),
                    'meta' => mb_substr((string) ($p->meta_description ?? ''), 0, 200),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
