<?php

namespace App\Support\Crawler;

use App\Support\Audit\HtmlAuditor;
use App\Support\UrlNormalizer;

/**
 * Turns one fetched HTML page into a normalized crawl record by orchestrating
 * HtmlAuditor. Single place that constructs HtmlAuditor so the crawl jobs stay
 * small. No DB, no network.
 */
class PageAnalyzer
{
    /** Cap stored body_text to keep rows lean. */
    private const MAX_BODY_TEXT = 200_000;

    /**
     * @param  array<string,string>  $responseHeaders  lowercased header map (incl. x-robots-tag)
     * @return array<string,mixed>
     */
    public function analyze(string $url, string $html, array $responseHeaders = []): array
    {
        $auditor = new HtmlAuditor($html, $url);

        $meta = $auditor->metadata();
        $robots = $auditor->robots();
        $headings = $auditor->headings();
        $content = $auditor->content();
        $links = $auditor->links();
        $images = $auditor->images();
        $schema = $auditor->schema();
        $locale = $auditor->localeSignals();
        $mixedContent = $auditor->mixedContentUrls();

        // X-Robots-Tag response header can also force noindex.
        $xRobots = strtolower((string) ($responseHeaders['x-robots-tag'] ?? ''));
        $headerNoindex = str_contains($xRobots, 'noindex') || str_contains($xRobots, 'none');

        $canonical = (string) ($meta['canonical'] ?? '');
        // Query-aware comparison: HtmlAuditor's canonical_matches ignores the
        // query string, so /?name=abc with canonical /  would wrongly read as
        // self-canonical. We compare path + sorted query so a canonical to a
        // different URL (incl. a different parameter set) is detected as a
        // canonicalized duplicate → non-indexable.
        $canonicalPointsAway = $this->canonicalPointsAway($canonical, $url);

        $isIndexable = ! ($robots['noindex'] || $headerNoindex || $canonicalPointsAway);

        $hreflangs = $locale['hreflangs'] ?? [];
        // A page declaring hreflang alternates should list itself among them (the
        // "self-referencing hreflang" Google/Semrush both require) — without it,
        // search engines can't confirm which hreflang entry IS this page.
        $hreflangSelfRef = false;
        foreach ($hreflangs as $h) {
            if ($this->canonicalKey((string) ($h['href'] ?? '')) === $this->canonicalKey($url)) {
                $hreflangSelfRef = true;
                break;
            }
        }

        $bodyText = (string) ($content['body_text'] ?? '');
        if (strlen($bodyText) > self::MAX_BODY_TEXT) {
            $bodyText = substr($bodyText, 0, self::MAX_BODY_TEXT);
        }

        $byLevel = ['h1' => [], 'h2' => [], 'h3' => []];
        foreach (($headings['headings'] ?? []) as $h) {
            $lvl = 'h'.((int) ($h['level'] ?? 0));
            if (isset($byLevel[$lvl]) && ($h['text'] ?? '') !== '') {
                $byLevel[$lvl][] = $h['text'];
            }
        }

        $robotsRaw = trim($robots['raw'].($xRobots !== '' ? ' x-robots:'.$xRobots : ''));

        return [
            'title' => $meta['title'] ?? '',
            'meta_description' => $meta['meta_description'] ?? '',
            'canonical_url' => $canonical !== '' ? $canonical : null,
            'canonical_points_away' => $canonicalPointsAway,
            'hreflangs' => $hreflangs,
            'hreflang_self_ref' => $hreflangSelfRef,
            'mixed_content_urls' => $mixedContent,
            'is_indexable' => $isIndexable,
            'robots_directives' => $robotsRaw !== '' ? mb_substr($robotsRaw, 0, 255) : null,
            'h1_count' => $headings['h1_count'] ?? 0,
            'heading_order_ok' => $headings['heading_order_ok'] ?? true,
            'headings_json' => $byLevel,
            'word_count' => $content['word_count'] ?? 0,
            'body_text' => $bodyText,
            'content_hash' => sha1($bodyText),
            'internal_links' => $links['internal'] ?? [],
            'external_links' => $links['external'] ?? [],
            'internal_link_count' => $links['internal_count'] ?? 0,
            'external_link_count' => $links['external_count'] ?? 0,
            'images' => $images,
            'schema' => $schema,
            'og_tag_count' => $meta['og_tag_count'] ?? 0,
            'twitter_tag_count' => $meta['twitter_tag_count'] ?? 0,
        ];
    }

    public static function normalizeUrl(string $url): string
    {
        return UrlNormalizer::normalize($url);
    }

    /**
     * True when the page's canonical points to a DIFFERENT URL than itself
     * (host + path + sorted query, www/scheme/trailing-slash insensitive) — i.e.
     * the page is a canonicalized duplicate. Resolves a relative canonical
     * against the page URL.
     */
    private function canonicalPointsAway(string $canonical, string $pageUrl): bool
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return false;
        }
        if (! preg_match('#^https?://#i', $canonical)) {
            $base = parse_url($pageUrl);
            if (empty($base['scheme']) || empty($base['host'])) {
                return false;
            }
            $canonical = $base['scheme'].'://'.$base['host'].(str_starts_with($canonical, '/') ? '' : '/').$canonical;
        }

        return $this->canonicalKey($canonical) !== $this->canonicalKey($pageUrl);
    }

    private function canonicalKey(string $url): string
    {
        $p = parse_url($url);
        if (! is_array($p) || empty($p['host'])) {
            return strtolower(trim($url));
        }
        $host = preg_replace('/^www\./', '', strtolower((string) $p['host']));
        $path = rtrim((string) ($p['path'] ?? '/'), '/');
        if ($path === '') {
            $path = '/';
        }
        $query = '';
        if (! empty($p['query'])) {
            parse_str((string) $p['query'], $qa);
            ksort($qa);
            $query = http_build_query($qa);
        }

        return $host.$path.($query !== '' ? '?'.$query : '');
    }
}
