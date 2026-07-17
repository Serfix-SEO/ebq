<?php

namespace App\Services\Content;

/**
 * The verification-loop referee: deterministic technical-SEO scoring of a
 * generated article against the site data it was written from. Pure — no
 * I/O, no LLM; all context arrives in the $context array so unit tests pin
 * exact scores and the revision loop is reproducible.
 *
 * score() returns:
 *   score   0-100 (weighted, weights renormalize when a check's context is
 *           missing — e.g. no known site URLs => internal-link check skipped)
 *   issues  list<{code, weight, message}> — messages are written as direct
 *           revision instructions for the LLM
 *   checks  list<{code, passed, weight}> — full audit trail (UI check list)
 *
 * Context keys (all optional unless noted):
 *   target_keyword (required), secondary_keywords[], site_urls[] (known
 *   internal URLs), existing_titles[], article_length (target words),
 *   toggles{toc,key_takeaways,faq,external_links,cta_enabled}, cta_url,
 *   language, style_issues (HumanizerService::lint output).
 */
class ContentSeoScorer
{
    public const VERSION = 1;

    /** @return array{score:int, issues:list<array{code:string,weight:int,message:string}>, checks:list<array{code:string,passed:bool,weight:int}>} */
    public function score(
        string $html,
        string $metaTitle,
        string $metaDescription,
        string $h1,
        string $slug,
        array $context
    ): array {
        $keyword = mb_strtolower(trim((string) ($context['target_keyword'] ?? '')));
        $text = $this->toText($html);
        $lowerText = mb_strtolower($text);
        $wordCount = str_word_count($text);

        $checks = [];
        $issues = [];

        $add = function (string $code, int $weight, bool $passed, string $fixMessage) use (&$checks, &$issues): void {
            $checks[] = ['code' => $code, 'passed' => $passed, 'weight' => $weight];
            if (! $passed) {
                $issues[] = ['code' => $code, 'weight' => $weight, 'message' => $fixMessage];
            }
        };

        // ── Keyword placement ───────────────────────────────────────────
        $kwIn = fn (string $haystack): bool => $keyword !== '' && str_contains(mb_strtolower($haystack), $keyword);

        $add('kw_in_meta_title', 10, $kwIn($metaTitle),
            "Include the exact keyword \"{$keyword}\" in the meta title.");
        $add('meta_title_length', 4, mb_strlen($metaTitle) >= 30 && mb_strlen($metaTitle) <= 60,
            'Rewrite the meta title to 30-60 characters (currently '.mb_strlen($metaTitle).').');
        $add('kw_in_h1', 8, $kwIn($h1),
            "Include the exact keyword \"{$keyword}\" in the H1.");
        $add('h1_length', 2, $h1 !== '' && mb_strlen($h1) <= 70,
            'Shorten the H1 to 70 characters or fewer.');
        $add('kw_in_meta_description', 6, $kwIn($metaDescription),
            "Include the exact keyword \"{$keyword}\" in the meta description.");
        $add('meta_description_length', 4, mb_strlen($metaDescription) >= 120 && mb_strlen($metaDescription) <= 160,
            'Rewrite the meta description to 120-160 characters (currently '.mb_strlen($metaDescription).').');
        $add('kw_in_first_words', 6, $kwIn(implode(' ', array_slice(explode(' ', $text), 0, 100))),
            "Use the exact keyword \"{$keyword}\" within the first 100 words.");
        $add('kw_in_slug', 3, $slug !== '' && str_contains($slug, $this->slugify($keyword)),
            'Use the keyword in the URL slug (lowercase, hyphenated).');

        // ── Structure ───────────────────────────────────────────────────
        $h2s = $this->headings($html, 2);
        $h3sOrphaned = $this->hasOrphanH3($html);
        $targetWords = (int) ($context['article_length'] ?? 2500);

        $add('word_count', 8,
            $wordCount >= (int) floor($targetWords * 0.85) && $wordCount <= (int) ceil($targetWords * 1.3),
            "Adjust length to roughly {$targetWords} words (currently {$wordCount}). Expand thin sections rather than padding.");
        $add('h2_count', 6, count($h2s) >= 4,
            'Structure the article with at least 4 H2 sections.');
        $add('kw_in_a_heading', 4, $keyword !== '' && (bool) array_filter($h2s, $kwIn),
            "Use the keyword \"{$keyword}\" naturally in at least one H2 heading.");
        $add('no_orphan_h3', 2, ! $h3sOrphaned,
            'Every H3 must sit under an H2 parent; fix the heading hierarchy.');
        $add('heading_not_stuffed', 2,
            $keyword === '' || count(array_filter($h2s, $kwIn)) <= 2,
            'The keyword appears in too many headings; keep it in at most 2.');

        // Toggle-driven blocks.
        $toggles = (array) ($context['toggles'] ?? []);
        if (($toggles['key_takeaways'] ?? false)) {
            $add('key_takeaways_present', 3,
                (bool) preg_match('/key\s+takeaways/i', $html),
                'Add a "Key takeaways" box near the top with 3-5 bullet points.');
        }
        if (($toggles['faq'] ?? false)) {
            $add('faq_present', 3,
                (bool) preg_match('/<h[23][^>]*>[^<]*(faq|frequently asked|common questions)/i', $html),
                'Add an FAQ section (H2) answering 3-5 real questions.');
        }
        if (($toggles['cta_enabled'] ?? false) && ! empty($context['cta_url'])) {
            $add('cta_present', 3,
                str_contains($html, (string) $context['cta_url']),
                'Link to '.$context['cta_url'].' with a natural call-to-action.');
        }

        // ── Keyword usage ───────────────────────────────────────────────
        if ($keyword !== '' && $wordCount > 0) {
            $kwWords = max(1, str_word_count($keyword));
            $occurrences = $this->countOccurrences($lowerText, $keyword);
            $density = ($occurrences * $kwWords) / $wordCount * 100;
            $add('kw_density', 6, $density >= 0.4 && $density <= 2.5,
                $density < 0.4
                    ? "Use the keyword \"{$keyword}\" more often (a few more natural mentions)."
                    : "The keyword \"{$keyword}\" is over-used (density ".round($density, 1)."%); remove repetitions, use synonyms.");
        }

        $secondary = array_values(array_filter(array_map(
            static fn ($k) => mb_strtolower(trim((string) $k)),
            (array) ($context['secondary_keywords'] ?? [])
        )));
        if ($secondary !== []) {
            $used = array_filter($secondary, fn ($k) => str_contains($lowerText, $k));
            $coverage = count($used) / count($secondary);
            $missing = array_diff($secondary, $used);
            $add('secondary_coverage', 6, $coverage >= 0.6,
                'Work these related phrases in naturally: "'.implode('", "', array_slice($missing, 0, 8)).'".');
        }

        // ── Linking ─────────────────────────────────────────────────────
        [$internal, $external, $invalidInternal] = $this->classifyLinks($html, $context);

        if (! empty($context['site_urls'])) {
            $add('internal_links', 8, count($internal) >= 2,
                'Add at least 2 internal links to relevant pages from the provided site URL list.');
            $add('internal_links_valid', 6, $invalidInternal === [],
                'These internal links do not exist on the site, replace them with URLs from the provided list: '.implode(', ', array_slice($invalidInternal, 0, 5)).'.');
        }
        if (($toggles['external_links'] ?? false)) {
            $add('external_link', 3, count($external) >= 1,
                'Add at least one link to an authoritative external source.');
        }
        $linkCount = count($internal) + count($external);
        $add('link_density', 2, $wordCount === 0 || $linkCount <= max(3, (int) ceil($wordCount / 150)),
            'Too many links for the article length; keep roughly one link per 150+ words.');

        // ── Media ───────────────────────────────────────────────────────
        $imgs = preg_match_all('/<img\b[^>]*>/i', $html, $imgMatches) ?: 0;
        if ($imgs > 0) {
            $missingAlt = 0;
            foreach ($imgMatches[0] as $tag) {
                if (! preg_match('/\balt="([^"]{4,})"/i', $tag)) {
                    $missingAlt++;
                }
            }
            $add('img_alt_text', 3, $missingAlt === 0,
                "Give every image a specific, descriptive alt text ({$missingAlt} missing).");
        }

        // ── Readability ─────────────────────────────────────────────────
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($sentences) >= 10) {
            $avg = $wordCount / count($sentences);
            $add('sentence_length', 4, $avg <= 24,
                'Average sentence is too long ('.round($avg).' words); split long sentences.');

            $longParagraph = false;
            foreach ($this->paragraphs($html) as $p) {
                if (preg_match_all('/[.!?](?:\s|$)/u', $p) > 5) {
                    $longParagraph = true;
                    break;
                }
            }
            $add('paragraph_length', 2, ! $longParagraph,
                'Break up paragraphs longer than 5 sentences.');
        }

        // ── Uniqueness (cannibalization re-check) ───────────────────────
        $existing = (array) ($context['existing_titles'] ?? []);
        if ($existing !== [] && $h1 !== '') {
            $duplicate = null;
            foreach ($existing as $title) {
                if ($this->titleSimilarity($h1, (string) $title) >= 0.75) {
                    $duplicate = (string) $title;
                    break;
                }
            }
            $add('title_unique', 6, $duplicate === null,
                'The H1 nearly duplicates the existing page "'.($duplicate ?? '').'"; give this article a clearly distinct angle and title.');
        }

        // ── Style (HumanizerService lint feeds the score) ───────────────
        $styleIssues = (array) ($context['style_issues'] ?? []);
        $add('style_clean', 10, $styleIssues === [],
            $styleIssues === [] ? '' : 'Fix the writing-style problems: '
                .implode(' ', array_map(static fn ($i) => (string) ($i['message'] ?? ''), array_slice($styleIssues, 0, 6))));

        // ── Weighted score, renormalized over checks that RAN ───────────
        $totalWeight = array_sum(array_column($checks, 'weight'));
        $earned = array_sum(array_map(
            static fn ($c) => $c['passed'] ? $c['weight'] : 0,
            $checks
        ));
        $score = $totalWeight > 0 ? (int) round($earned / $totalWeight * 100) : 0;

        return ['score' => $score, 'issues' => $issues, 'checks' => $checks];
    }

    // ── internals ───────────────────────────────────────────────────────

    private function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return list<string> */
    private function headings(string $html, int $level): array
    {
        if (! preg_match_all('/<h'.$level.'\b[^>]*>(.*?)<\/h'.$level.'>/is', $html, $m)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($h) => trim(strip_tags($h)), $m[1])));
    }

    private function hasOrphanH3(string $html): bool
    {
        $firstH2 = stripos($html, '<h2');
        $firstH3 = stripos($html, '<h3');

        return $firstH3 !== false && ($firstH2 === false || $firstH3 < $firstH2);
    }

    /** @return list<string> */
    private function paragraphs(string $html): array
    {
        if (! preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $m)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($p) => trim(strip_tags($p)), $m[1])));
    }

    private function countOccurrences(string $lowerText, string $keyword): int
    {
        return $keyword === '' ? 0 : substr_count($lowerText, $keyword);
    }

    /**
     * Split anchors into internal / external / invalid-internal based on the
     * site's known URL inventory.
     *
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private function classifyLinks(string $html, array $context): array
    {
        if (! preg_match_all('/<a\b[^>]*href="([^"#][^"]*)"[^>]*>/i', $html, $m)) {
            return [[], [], []];
        }

        $siteHost = mb_strtolower((string) ($context['site_host'] ?? ''));
        $known = array_map(
            static fn ($u) => rtrim(mb_strtolower((string) $u), '/'),
            (array) ($context['site_urls'] ?? [])
        );

        $internal = $external = $invalid = [];
        foreach (array_unique($m[1]) as $href) {
            $host = mb_strtolower((string) (parse_url($href, PHP_URL_HOST) ?? ''));
            $isInternal = $host === '' || $host === $siteHost
                || ($siteHost !== '' && str_ends_with($host, '.'.$siteHost));

            if (! $isInternal) {
                $external[] = $href;

                continue;
            }
            $internal[] = $href;

            if ($known !== []) {
                $normalized = rtrim(mb_strtolower($href), '/');
                $matches = array_filter($known, fn ($k) => $k === $normalized
                    || str_ends_with($k, $this->pathOf($normalized)));
                if ($this->pathOf($normalized) !== '' && $matches === []) {
                    $invalid[] = $href;
                }
            }
        }

        return [$internal, $external, $invalid];
    }

    private function pathOf(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return rtrim($path, '/');
    }

    private function slugify(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }

    /** Token-overlap similarity (0..1) for cannibalization checks. */
    private function titleSimilarity(string $a, string $b): float
    {
        $tokens = static function (string $s): array {
            $words = preg_split('/[^a-z0-9]+/', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_diff($words, ['the', 'a', 'an', 'for', 'to', 'of', 'and', 'in', 'on', 'your', 'how', 'what', 'best', 'guide']);
        };

        $ta = $tokens($a);
        $tb = $tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $overlap = count(array_intersect($ta, $tb));

        return $overlap / min(count($ta), count($tb));
    }
}
