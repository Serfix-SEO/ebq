<?php

namespace App\Support\Crawler;

use App\Services\Crawler\CrawlFetcher;
use XMLReader;

/**
 * Downloads + parses XML sitemaps and returns the page URLs (<loc>) they
 * contain. Handles <sitemapindex> recursion (nested sitemaps), gzip (.xml.gz),
 * and guards against runaway recursion / cycles / huge sets.
 *
 * Pure extraction — no DB. Uses CrawlFetcher so every download is SSRF-guarded.
 */
class SitemapUrlExtractor
{
    private const MAX_DEPTH = 3;
    private const MAX_URLS = 50_000;
    private const MAX_SITEMAPS = 500;

    /** @var array<string,bool> */
    private array $visited = [];
    private int $sitemapsFetched = 0;

    public function __construct(private readonly CrawlFetcher $fetcher) {}

    /**
     * @param  iterable<string>  $sitemapUrls  the WebsiteSitemap.path values
     * @return array<int,array{loc:string,lastmod:?string}>  deduped page URLs
     */
    public function extract(iterable $sitemapUrls): array
    {
        $out = [];
        $seenLoc = [];

        foreach ($sitemapUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            foreach ($this->parse($url, 0) as $entry) {
                $key = strtolower($entry['loc']);
                if (isset($seenLoc[$key])) {
                    continue;
                }
                $seenLoc[$key] = true;
                $out[] = $entry;
                if (count($out) >= self::MAX_URLS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{loc:string,lastmod:?string}>
     */
    private function parse(string $sitemapUrl, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || $this->sitemapsFetched >= self::MAX_SITEMAPS) {
            return [];
        }
        $norm = strtolower(rtrim($sitemapUrl, '/'));
        if (isset($this->visited[$norm])) {
            return []; // cycle / already seen
        }
        $this->visited[$norm] = true;

        $res = $this->fetcher->fetch($sitemapUrl, [], 30);
        $this->sitemapsFetched++;
        if (! $res['ok'] || ($res['status'] ?? 0) >= 400 || $res['body'] === '') {
            return [];
        }

        $body = $this->maybeGunzip($sitemapUrl, $res['body']);
        if ($body === '') {
            return [];
        }

        $isIndex = stripos($body, '<sitemapindex') !== false;

        $reader = new XMLReader();
        if (@$reader->XML($body, 'UTF-8', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
            return [];
        }

        $entries = [];
        $childSitemaps = [];
        $currentLoc = null;
        $currentLastmod = null;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $name = strtolower($reader->localName);
                    // Un-prefixed elements ONLY: localName strips namespaces,
                    // so Shopify's <image:image><image:loc> matched here and
                    // OVERWROTE the page URL with the CDN image URL before
                    // </url> closed (carmenperfumes 2026-09-08: 87 sitemap
                    // products, 4 survived the domain filter). Same trap for
                    // any namespaced lastmod/loc (video:, news:, xhtml:).
                    if ($reader->prefix !== '') {
                        continue;
                    }
                    if ($name === 'loc') {
                        $currentLoc = trim((string) $reader->readString());
                    } elseif ($name === 'lastmod') {
                        $currentLastmod = trim((string) $reader->readString()) ?: null;
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    $name = strtolower($reader->localName);
                    if (($name === 'url' || $name === 'sitemap') && $currentLoc) {
                        if ($isIndex || $name === 'sitemap') {
                            $childSitemaps[] = $currentLoc;
                        } else {
                            $entries[] = ['loc' => $currentLoc, 'lastmod' => $currentLastmod];
                        }
                        $currentLoc = null;
                        $currentLastmod = null;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        foreach ($childSitemaps as $child) {
            foreach ($this->parse($child, $depth + 1) as $e) {
                $entries[] = $e;
                if (count($entries) >= self::MAX_URLS) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    private function maybeGunzip(string $url, string $body): string
    {
        $isGz = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz')
            || str_starts_with($body, "\x1f\x8b");
        if (! $isGz) {
            return $body;
        }
        $decoded = @gzdecode($body);

        return $decoded !== false ? $decoded : '';
    }
}
