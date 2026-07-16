<?php

namespace App\Support\Audit;

use DOMDocument;
use DOMXPath;

class HtmlAuditor
{
    private const STOPWORDS = [
        'a','an','the','and','or','but','if','then','so','of','at','by','for','with','about','to','from','in','on','is','are','was','were','be','been','being','have','has','had','do','does','did','will','would','should','could','can','may','might','this','that','these','those','it','its','as','not','no','yes','i','you','he','she','we','they','them','their','our','your','my','me','us','him','her','his','hers','what','when','where','why','how','which','who','whom','all','any','some','such','than','too','very','just','also','there','here',
    ];

    private DOMXPath $xpath;
    private string $html;
    private string $pageUrl;

    public function __construct(string $html, string $pageUrl)
    {
        $this->html = $html;
        $this->pageUrl = $pageUrl;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // LIBXML_NONET prevents DTD / external-entity network fetches; NOWARNING + NOERROR
        // silence malformed-HTML noise we already tolerate.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();

        $this->xpath = new DOMXPath($dom);
    }

    public function metadata(): array
    {
        $title = trim((string) $this->xpath->evaluate('string(//title)'));
        $description = trim((string) $this->xpath->evaluate(
            'string(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]/@content)'
        ));
        $canonical = trim((string) $this->xpath->evaluate('string(//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]/@href)'));

        $ogCount = (int) $this->xpath->evaluate('count(//meta[starts-with(@property, "og:")])');
        $twitterCount = (int) $this->xpath->evaluate('count(//meta[starts-with(@name, "twitter:")])');

        return [
            'title' => $title,
            'title_length' => mb_strlen($title),
            'meta_description' => $description,
            'meta_description_length' => mb_strlen($description),
            'canonical' => $canonical,
            'canonical_matches' => $canonical !== '' && $this->urlsEqual($canonical, $this->pageUrl),
            'og_tag_count' => $ogCount,
            'twitter_tag_count' => $twitterCount,
        ];
    }

    /**
     * Raw locale signals for Serper / market resolution (hreflang, og:locale, html lang).
     *
     * @return array{
     *   html_lang: ?string,
     *   og_locale: ?string,
     *   hreflangs: list<array{hreflang: string, href: string}>
     * }
     */
    public function localeSignals(): array
    {
        $htmlLang = trim((string) $this->xpath->evaluate('string(//html/@lang)'));
        if ($htmlLang === '') {
            $htmlLang = null;
        }

        $ogLocale = trim((string) $this->xpath->evaluate(
            'string(//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:locale"]/@content)'
        ));
        if ($ogLocale === '') {
            $ogLocale = null;
        }

        $hreflangs = [];
        $nodes = $this->xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]');
        if ($nodes) {
            foreach ($nodes as $node) {
                $hreflang = trim((string) $node->getAttribute('hreflang'));
                $hrefRaw = trim((string) $node->getAttribute('href'));
                if ($hreflang === '' || $hrefRaw === '') {
                    continue;
                }
                $abs = $this->absoluteUrl($hrefRaw, $this->pageUrl);
                if ($abs === null) {
                    continue;
                }
                $hreflangs[] = ['hreflang' => $hreflang, 'href' => $abs];
            }
        }

        return [
            'html_lang' => $htmlLang,
            'og_locale' => $ogLocale,
            'hreflangs' => $hreflangs,
        ];
    }

    /**
     * Robots directives from <meta name="robots"> and <meta name="googlebot">.
     * (X-Robots-Tag response header is checked separately by the caller.)
     *
     * @return array{raw: string, noindex: bool, nofollow: bool}
     */
    public function robots(): array
    {
        $robots = strtolower(trim((string) $this->xpath->evaluate(
            'string(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]/@content)'
        )));
        $googlebot = strtolower(trim((string) $this->xpath->evaluate(
            'string(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="googlebot"]/@content)'
        )));
        $combined = trim($robots.' '.$googlebot);

        return [
            'raw' => $combined,
            'noindex' => str_contains($combined, 'noindex') || str_contains($combined, 'none'),
            'nofollow' => str_contains($combined, 'nofollow') || str_contains($combined, 'none'),
        ];
    }

    public function headings(): array
    {
        $nodes = $this->xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $headings = [];
        $prevLevel = 0;
        $orderOk = true;
        $h1Count = 0;

        foreach ($nodes as $n) {
            $level = (int) substr($n->nodeName, 1);
            if ($level === 1) {
                $h1Count++;
            }
            if ($prevLevel > 0 && $level > $prevLevel + 1) {
                $orderOk = false;
            }
            $headings[] = ['level' => $level, 'text' => trim(preg_replace('/\s+/', ' ', $n->textContent ?? ''))];
            $prevLevel = $level;
        }

        return [
            'h1_count' => $h1Count,
            'heading_order_ok' => $orderOk,
            'headings' => $headings,
        ];
    }

    public function content(): array
    {
        $textNodes = $this->xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::svg)]');
        $parts = [];
        if ($textNodes) {
            foreach ($textNodes as $n) {
                $t = trim((string) $n->nodeValue);
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }
        $bodyText = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
        $words = $bodyText === '' ? [] : preg_split('/\s+/u', $bodyText);
        $wordCount = count($words);
        $first150 = $wordCount > 0 ? implode(' ', array_slice($words, 0, 150)) : '';

        return [
            'word_count' => $wordCount,
            'first_150_words' => $first150,
            'keyword_density' => $this->keywordDensity($bodyText, $wordCount),
            'body_text' => $bodyText,
        ];
    }

    private function keywordDensity(string $bodyText, int $totalWords): array
    {
        if ($totalWords === 0) {
            return [];
        }
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($bodyText));
        $tokens = preg_split('/\s+/u', trim((string) $normalized));
        $stop = array_flip(self::STOPWORDS);
        $counts = [];
        foreach ($tokens as $t) {
            if ($t === '' || mb_strlen($t) < 3 || isset($stop[$t])) {
                continue;
            }
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, 20, true) as $term => $count) {
            $out[] = [
                'term' => $term,
                'count' => $count,
                'density' => round($count * 100 / max(1, $totalWords), 2),
            ];
        }

        return $out;
    }

    public function images(): array
    {
        $imgs = $this->xpath->query('//img');
        $missingAlt = [];
        $modernCount = 0;
        $total = 0;

        foreach ($imgs as $img) {
            $total++;
            $src = trim($img->getAttribute('src'));
            $alt = $img->getAttribute('alt');
            if (! $img->hasAttribute('alt') || trim($alt) === '') {
                $missingAlt[] = $src !== '' ? $src : '(no src)';
            }
            $lower = strtolower($src);
            if (str_contains($lower, '.webp') || str_contains($lower, '.avif')) {
                $modernCount++;
            }
        }

        return [
            'total' => $total,
            'missing_alt' => array_values(array_slice($missingAlt, 0, 50)),
            'missing_alt_count' => count($missingAlt),
            'modern_format_count' => $modernCount,
        ];
    }

    public function links(): array
    {
        $pageHost = strtolower((string) parse_url($this->pageUrl, PHP_URL_HOST));
        $anchors = $this->xpath->query('//a[@href]');
        $internal = [];
        $external = [];
        $seen = [];

        foreach ($anchors as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:') || str_starts_with(strtolower($href), 'mailto:') || str_starts_with(strtolower($href), 'tel:')) {
                continue;
            }
            $abs = $this->absoluteUrl($href, $this->pageUrl);
            if ($abs === null) {
                continue;
            }
            if (isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;

            $entry = [
                'href' => $abs,
                'anchor' => trim(preg_replace('/\s+/', ' ', $a->textContent ?? '')),
                // rel flags matter to the link-graph recorder (dofollow edges).
                'nofollow' => str_contains(strtolower($a->getAttribute('rel')), 'nofollow'),
            ];

            $host = strtolower((string) parse_url($abs, PHP_URL_HOST));
            if ($host === $pageHost) {
                $internal[] = $entry;
            } else {
                $external[] = $entry;
            }
        }

        return [
            'internal_count' => count($internal),
            'external_count' => count($external),
            'internal' => $internal,
            'external' => $external,
        ];
    }

    /**
     * Plain-http:// resource references on an https page (img/script/stylesheet/
     * iframe/video/audio/source src/href) — browsers block or warn on these.
     * Only literal `http://` absolute URLs count; protocol-relative (`//host/...`)
     * and relative URLs inherit the page's own https scheme, so they're never
     * mixed content regardless of what host they point to.
     *
     * @return list<string>
     */
    public function mixedContentUrls(): array
    {
        if (strtolower((string) parse_url($this->pageUrl, PHP_URL_SCHEME)) !== 'https') {
            return [];
        }

        $found = [];
        foreach ([
            '//img/@src', '//script/@src', '//iframe/@src', '//source/@src', '//video/@src', '//audio/@src',
            '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="stylesheet"]/@href',
        ] as $expr) {
            $nodes = $this->xpath->query($expr);
            if (! $nodes) {
                continue;
            }
            foreach ($nodes as $attr) {
                $val = trim((string) $attr->nodeValue);
                if (preg_match('#^http://#i', $val)) {
                    $found[] = $val;
                }
            }
        }

        return array_values(array_unique($found));
    }

    public function schema(): array
    {
        $nodes = $this->xpath->query('//script[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
        $blocks = [];
        foreach ($nodes as $n) {
            $raw = trim($n->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            $type = is_array($decoded) ? ($decoded['@type'] ?? data_get($decoded, '0.@type')) : null;
            $blocks[] = [
                'type' => is_string($type) ? $type : (is_array($type) ? implode(',', $type) : null),
                'valid' => $decoded !== null,
            ];
        }

        return [
            'count' => count($blocks),
            'blocks' => $blocks,
        ];
    }

    public function favicon(): array
    {
        $href = trim((string) $this->xpath->evaluate(
            'string(//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "icon")]/@href)'
        ));

        return [
            'present' => $href !== '',
            'href' => $href,
        ];
    }

    public function readability(string $bodyText): array
    {
        $text = trim($bodyText);
        if ($text === '') {
            return ['flesch' => null, 'grade' => null];
        }
        $sentences = max(1, preg_match_all('/[.!?]+/u', $text));
        $words = preg_split('/\s+/u', $text) ?: [];
        $wordCount = max(1, count($words));
        $syllables = 0;
        foreach ($words as $w) {
            $syllables += $this->estimateSyllables($w);
        }
        $syllables = max(1, $syllables);

        $flesch = 206.835 - 1.015 * ($wordCount / $sentences) - 84.6 * ($syllables / $wordCount);
        $flesch = round($flesch, 1);

        $grade = match (true) {
            $flesch >= 90 => '5th grade (very easy)',
            $flesch >= 80 => '6th grade (easy)',
            $flesch >= 70 => '7th grade (fairly easy)',
            $flesch >= 60 => '8th–9th grade (standard)',
            $flesch >= 50 => '10th–12th grade (fairly difficult)',
            $flesch >= 30 => 'College (difficult)',
            default => 'College graduate (very difficult)',
        };

        return ['flesch' => $flesch, 'grade' => $grade];
    }

    private function estimateSyllables(string $word): int
    {
        $w = strtolower(preg_replace('/[^a-z]/i', '', $word));
        if ($w === '') {
            return 0;
        }
        $w = preg_replace('/e$/', '', $w);
        preg_match_all('/[aeiouy]+/', $w, $m);

        return max(1, count($m[0] ?? []));
    }

    private function urlsEqual(string $a, string $b): bool
    {
        return rtrim($this->normalizeUrl($a), '/') === rtrim($this->normalizeUrl($b), '/');
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts) {
            return $url;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        $path = (string) ($parts['path'] ?? '/');

        return $host . $path;
    }

    private function absoluteUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $baseParts = parse_url($base);
        if (! $baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $port . $href;
        }
        $basePath = $baseParts['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        return $scheme . '://' . $host . $port . $basePath . $href;
    }

    /**
     * Detect the page's underlying stack from response headers + DOM fingerprints.
     *
     * @param  array<string, string>  $responseHeaders  lowercased key => value (e.g. 'server', 'x-powered-by', 'x-generator')
     * @return array{label: string, type: 'modern'|'cms'|'static'|'unknown', signals: list<string>}
     */
    public function technology(array $responseHeaders): array
    {
        $headers = array_change_key_case($responseHeaders, CASE_LOWER);
        $signals = [];

        $h = fn (string $key): string => (string) ($headers[$key] ?? '');
        $anyHeaderContains = function (string $needle) use ($headers, &$signals): bool {
            foreach (['server', 'x-powered-by', 'x-generator'] as $k) {
                $v = (string) ($headers[$k] ?? '');
                if ($v !== '' && stripos($v, $needle) !== false) {
                    $signals[] = "{$k}: {$v}";

                    return true;
                }
            }

            return false;
        };

        // 1) Response headers — strong signals for Next.js / Drupal / Shopify / WP hosting.
        if ($anyHeaderContains('Next.js')) {
            return ['label' => 'Next.js', 'type' => 'modern', 'signals' => array_slice($signals, 0, 5)];
        }
        if (stripos($h('x-generator'), 'Drupal') !== false) {
            $signals[] = 'x-generator: ' . $h('x-generator');

            return ['label' => 'Drupal', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        if ($anyHeaderContains('Shopify')) {
            return ['label' => 'Shopify', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        if ($anyHeaderContains('WP Engine')) {
            $signals[] = 'WP Engine → WordPress';

            return ['label' => 'WordPress', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }

        // 2) <meta name="generator"> — verbatim signal.
        $gen = trim((string) $this->xpath->evaluate(
            'string(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="generator"]/@content)'
        ));
        if ($gen !== '') {
            $signals[] = "meta-generator: {$gen}";
            $genMatch = $this->matchGenerator($gen);
            if ($genMatch !== null) {
                return $genMatch + ['signals' => array_slice($signals, 0, 5)];
            }
        }

        // 3) DOM / asset fingerprints.
        $assetContains = fn (string $needle): bool => ((int) $this->xpath->evaluate(
            'count(//*[contains(@src, "' . $this->escapeXpathString($needle) . '") or contains(@href, "' . $this->escapeXpathString($needle) . '")])'
        )) > 0;

        // Next.js
        if ((int) $this->xpath->evaluate('count(//script[@id="__NEXT_DATA__"])') > 0 || $assetContains('/_next/static/')) {
            $signals[] = '__NEXT_DATA__ / _next/static';

            return ['label' => 'Next.js', 'type' => 'modern', 'signals' => array_slice($signals, 0, 5)];
        }
        // Nuxt
        if ($assetContains('/_nuxt/') || (int) $this->xpath->evaluate('count(//script[contains(., "__NUXT__")])') > 0) {
            $signals[] = '_nuxt / __NUXT__';

            return ['label' => 'Nuxt', 'type' => 'modern', 'signals' => array_slice($signals, 0, 5)];
        }
        // Astro
        if ((int) $this->xpath->evaluate('count(//*[@data-astro-cid])') > 0) {
            $signals[] = 'data-astro-cid';

            return ['label' => 'Astro', 'type' => 'modern', 'signals' => array_slice($signals, 0, 5)];
        }
        // Shopify (DOM)
        if ($assetContains('cdn.shopify.com') || (int) $this->xpath->evaluate('count(//script[contains(., "Shopify.shop")])') > 0) {
            $signals[] = 'cdn.shopify.com / Shopify.shop';

            return ['label' => 'Shopify', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // Wix
        if ($assetContains('static.wixstatic.com')) {
            $signals[] = 'static.wixstatic.com';

            return ['label' => 'Wix', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // Squarespace
        if ($assetContains('squarespace-cdn.com')) {
            $signals[] = 'squarespace-cdn.com';

            return ['label' => 'Squarespace', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // Webflow
        if ($assetContains('assets.website-files.com') || (int) $this->xpath->evaluate('count(//@*[starts-with(name(), "data-wf-")])') > 0) {
            $signals[] = 'webflow cdn / data-wf-*';

            return ['label' => 'Webflow', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // Drupal (DOM)
        if ($assetContains('/sites/default/files/')) {
            $signals[] = '/sites/default/files/';

            return ['label' => 'Drupal', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // WordPress (DOM)
        if ($assetContains('/wp-content/') || $assetContains('/wp-includes/') || $assetContains('wp-json')) {
            $signals[] = 'wp-content / wp-includes / wp-json';

            return ['label' => 'WordPress', 'type' => 'cms', 'signals' => array_slice($signals, 0, 5)];
        }
        // Laravel + Vite (needs BOTH build-assets and wire:* attributes to avoid false positives).
        $hasBuildAssets = $assetContains('/build/assets/');
        $hasLivewire = (int) $this->xpath->evaluate('count(//@*[starts-with(name(), "wire:")])') > 0;
        if ($hasBuildAssets && $hasLivewire) {
            $signals[] = '/build/assets/ + wire:*';

            return ['label' => 'Laravel (Vite)', 'type' => 'modern', 'signals' => array_slice($signals, 0, 5)];
        }

        return ['label' => 'Unknown', 'type' => 'unknown', 'signals' => array_slice($signals, 0, 5)];
    }

    /**
     * @return array{label: string, type: 'modern'|'cms'|'static'|'unknown'}|null
     */
    private function matchGenerator(string $generator): ?array
    {
        $g = strtolower($generator);
        $map = [
            'wordpress' => ['WordPress', 'cms'],
            'drupal' => ['Drupal', 'cms'],
            'joomla' => ['Joomla', 'cms'],
            'magento' => ['Magento', 'cms'],
            'shopify' => ['Shopify', 'cms'],
            'wix' => ['Wix', 'cms'],
            'squarespace' => ['Squarespace', 'cms'],
            'webflow' => ['Webflow', 'cms'],
            'hubspot' => ['Hubspot', 'cms'],
            'ghost' => ['Ghost', 'cms'],
            'astro' => ['Astro', 'modern'],
            'next.js' => ['Next.js', 'modern'],
            'nuxt' => ['Nuxt', 'modern'],
            'gatsby' => ['Gatsby', 'modern'],
            'hugo' => ['Hugo', 'static'],
            'jekyll' => ['Jekyll', 'static'],
        ];
        foreach ($map as $needle => [$label, $type]) {
            if (str_contains($g, $needle)) {
                return ['label' => $label, 'type' => $type];
            }
        }

        return null;
    }

    private function escapeXpathString(string $s): string
    {
        // Strings used inside contains() are wrapped in double quotes by the caller,
        // so we only need to defuse embedded double quotes. Replace with empty to stay
        // safe for the narrow set of literal fingerprints we pass in.
        return str_replace('"', '', $s);
    }
}
