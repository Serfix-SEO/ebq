<?php

namespace App\Services\KeywordResearch;

/**
 * Algorithmic term grouping for keyword-research results — the instant,
 * zero-cost "Groups" rail (à la Keyword Magic): keywords that share a
 * significant term are grouped under it, ranked by group volume.
 *
 * Unigrams + bigrams, stopword-filtered. A keyword can appear in several
 * groups (that's expected — groups are filters, not partitions).
 */
class KeywordTermGrouper
{
    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'for', 'to', 'with', 'at', 'by', 'from',
        'is', 'are', 'be', 'was', 'were', 'do', 'does', 'can', 'how', 'what', 'why', 'when',
        'where', 'who', 'which', 'my', 'your', 'our', 'their', 'it', 'its', 'you', 'i', 'me',
        'de', 'la', 'el', 'en', 'un', 'una', 'le', 'les', 'des', 'du', 'und', 'der', 'die', 'das',
    ];

    /**
     * @param  list<array{keyword:string, volume:?int}>  $rows  normalized result rows
     * @return list<array{term:string, count:int, volume:int}>  top groups, volume-desc
     */
    public static function groups(array $rows, int $limit = 24): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($kw === '') {
                continue;
            }
            $volume = is_numeric($row['volume'] ?? null) ? (int) $row['volume'] : 0;

            $words = preg_split('/[^\p{L}\p{N}]+/u', $kw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $terms = [];

            foreach ($words as $w) {
                if (mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) {
                    continue;
                }
                $terms[$w] = true;
            }
            for ($i = 0; $i < count($words) - 1; $i++) {
                if (in_array($words[$i], self::STOPWORDS, true) || in_array($words[$i + 1], self::STOPWORDS, true)) {
                    continue;
                }
                $terms[$words[$i].' '.$words[$i + 1]] = true;
            }

            foreach (array_keys($terms) as $t) {
                $groups[$t] ??= ['term' => $t, 'count' => 0, 'volume' => 0];
                $groups[$t]['count']++;
                $groups[$t]['volume'] += $volume;
            }
        }

        // A group of one keyword is noise; a bigram group dominated by its own
        // unigram parent adds nothing — keep it simple: min 2 keywords.
        $groups = array_filter($groups, fn ($g) => $g['count'] >= 2);

        // Prefer bigrams over their component unigrams when they cover the
        // same keywords (identical count+volume) — the longer term is more
        // specific and reads better in the rail.
        $byTerm = $groups;
        foreach ($byTerm as $term => $g) {
            if (! str_contains($term, ' ')) {
                continue;
            }
            foreach (explode(' ', $term) as $part) {
                if (isset($groups[$part]) && $groups[$part]['count'] === $g['count'] && $groups[$part]['volume'] === $g['volume']) {
                    unset($groups[$part]);
                }
            }
        }

        usort($groups, fn ($a, $b) => [$b['volume'], $b['count']] <=> [$a['volume'], $a['count']]);

        return array_slice(array_values($groups), 0, max(1, $limit));
    }
}
