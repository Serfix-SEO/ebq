<?php

namespace App\Support\Content;

use Illuminate\Support\Facades\DB;

/**
 * Internal-link candidate selection for the content pipeline (cocomii
 * 2026-08-26: the writer used to get 15 BARE urls — the site's most
 * internally-linked pages, identical for every article — and invented
 * topic-derived anchors for them, producing "specific term → random blog
 * post" links).
 *
 * Now: pages are fetched WITH titles, a topic-targeted title search widens
 * the pool beyond the nav-heavy inbound top (a catalog's relevant product
 * pages live outside it), and candidates are ranked by token overlap with
 * the topic. The scorer gets a url→title map covering EVERY fetched page so
 * it can judge whatever link the model actually picked.
 */
class InternalLinkCandidates
{
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'your', 'from', 'that', 'this', 'are',
        'how', 'what', 'why', 'best', 'guide', 'top', 'you', 'can', 'get',
    ];

    /**
     * @return array{
     *   site_urls: list<string>,
     *   existing_titles: list<string>,
     *   selected_pages: list<array{url: string, title: string}>,
     *   site_pages: array<string, string>,
     * }
     */
    public static function build(string|int|null $crawlSiteId, string $topicText): array
    {
        $empty = ['site_urls' => [], 'existing_titles' => [], 'selected_pages' => [], 'site_pages' => []];
        if (! $crawlSiteId) {
            return $empty;
        }

        try {
            $pages = DB::table('website_pages')
                ->where('crawl_site_id', $crawlSiteId)
                ->where('http_status', 200)
                ->orderByDesc('inbound_link_count')
                ->limit(300)
                ->get(['url', 'title']);

            // Topic-targeted widening: the inbound top-300 of a big catalog is
            // nav/collection pages; the pages anchors SHOULD point at usually
            // rank low on inbound links. Cheap title LIKE per topic token.
            $tokens = self::tokens($topicText);
            if ($tokens !== []) {
                $targeted = DB::table('website_pages')
                    ->where('crawl_site_id', $crawlSiteId)
                    ->where('http_status', 200)
                    ->where(function ($q) use ($tokens): void {
                        foreach (array_slice($tokens, 0, 6) as $t) {
                            $q->orWhere('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%');
                        }
                    })
                    ->limit(50)
                    ->get(['url', 'title']);
                $pages = $pages->concat($targeted)->unique('url')->values();
            }
        } catch (\Throwable) {
            return $empty; // no crawl data — scorer renormalizes without link checks
        }

        $sitePages = [];
        foreach ($pages as $p) {
            $sitePages[(string) $p->url] = (string) ($p->title ?? '');
        }

        // Rank by overlap of (title + slug) tokens with the topic tokens:
        // top 10 relevant, padded with the inbound-ordered head to 15.
        $topicTokens = self::tokens($topicText);
        $scored = $pages->map(function ($p) use ($topicTokens): array {
            $pageTokens = self::tokens(((string) ($p->title ?? '')).' '.self::slugWords((string) $p->url));
            $overlap = count(array_intersect($topicTokens, $pageTokens));

            return ['url' => (string) $p->url, 'title' => (string) ($p->title ?? ''), 'overlap' => $overlap];
        });

        $relevant = $scored->filter(fn ($p) => $p['overlap'] > 0)
            ->sortByDesc('overlap')->take(10)->values();
        $selected = $relevant->concat(
            $scored->reject(fn ($p) => $relevant->contains('url', $p['url']))->take(15 - $relevant->count())
        )->take(15)->map(fn ($p) => ['url' => $p['url'], 'title' => $p['title']])->values()->all();

        return [
            'site_urls' => $pages->pluck('url')->map(fn ($u) => (string) $u)->all(),
            'existing_titles' => $pages->pluck('title')->filter()->map(fn ($t) => (string) $t)->all(),
            'selected_pages' => $selected,
            'site_pages' => $sitePages,
        ];
    }

    /** Lowercased content tokens (unicode-aware, stopwords + short tokens dropped). */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3
            && ! in_array($w, self::STOPWORDS, true))));
    }

    /** The URL's path as words ("/rectangle-iphone-case" → "rectangle iphone case"). */
    public static function slugWords(string $url): string
    {
        return str_replace(['-', '_', '/'], ' ', (string) parse_url($url, PHP_URL_PATH));
    }
}
