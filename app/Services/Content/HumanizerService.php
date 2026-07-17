<?php

namespace App\Services\Content;

use App\Support\ContentAutopilotConfig;

/**
 * Anti-AI-detection layer for Content Autopilot, two halves:
 *
 *  1. promptRules() — the hard style contract injected into every write and
 *     revise LLM call (extends the writer's proven two-layer dash defense).
 *  2. lint()/clean() — deterministic post-processing: strip the artifacts we
 *     can fix mechanically, and flag the tells we can't so the revision loop
 *     rewrites them (lint findings feed ContentSeoScorer's style component).
 *
 * Deliberately NO external AI-detector API: cost, flakiness, and third
 * parties would log client content. The banned-phrase list is a live admin
 * Setting (ContentAutopilotConfig::bannedPhrases()) so new tells are added
 * without a deploy.
 */
class HumanizerService
{
    /** Style contract block for write/revise prompts. */
    public function promptRules(): string
    {
        $banned = implode('", "', array_slice(ContentAutopilotConfig::bannedPhrases(), 0, 60));

        return <<<RULES
        WRITING STYLE — ABSOLUTE RULES:
        - NEVER use em dashes (—), en dashes (–) or double hyphens (--). Use commas, periods, or parentheses instead.
        - Use straight quotes only, never curly/smart quotes.
        - NEVER use any of these words/phrases (or close variants): "{$banned}".
        - Use contractions naturally (it's, don't, you'll).
        - Vary sentence length hard: mix short sentences (under 8 words) with longer ones (over 20 words). Never write three consecutive sentences of similar length.
        - Vary paragraph length: some 1-2 sentences, some 4-5. Never uniform.
        - Do not open more than one section with the same sentence pattern.
        - At most ONE rhetorical question in the whole article.
        - Prefer concrete specifics (numbers, examples, named things from the brief) over generic claims.
        - Use bullet lists ONLY where content is genuinely list-shaped; most sections should be prose.
        - No formulaic intro ("In this article we will...") and no formulaic closing ("In conclusion...").
        - Write like an experienced practitioner sharing what they know, not like a brochure.
        RULES;
    }

    /**
     * Mechanical cleanup — fixes what can be fixed without an LLM:
     * dashes, curly quotes, stray double spaces.
     */
    public function clean(string $html): string
    {
        $replacements = [
            "\u{2014}" => ', ',   // em dash
            "\u{2013}" => '-',    // en dash (ranges keep a plain hyphen)
            ' -- ' => ', ',
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2026}" => '...',
        ];
        $out = strtr($html, $replacements);

        // Collapse ", ," artifacts and doubled spaces introduced above.
        $out = preg_replace('/\s*,\s*,/', ',', $out) ?? $out;

        return preg_replace('/ {2,}/', ' ', $out) ?? $out;
    }

    /**
     * Deterministic style lint over article HTML. Each issue:
     * {code, message, count?} — messages are written as revision
     * instructions the LLM can act on.
     *
     * @return list<array{code:string, message:string, count?:int}>
     */
    public function lint(string $html): array
    {
        $issues = [];
        $text = $this->toText($html);
        $lower = mb_strtolower($text);

        // 1. Dashes that survived clean() (defense in depth).
        $dashes = mb_substr_count($html, "\u{2014}") + mb_substr_count($html, "\u{2013}") + substr_count($html, ' -- ');
        if ($dashes > 0) {
            $issues[] = ['code' => 'dashes', 'count' => $dashes,
                'message' => 'Remove every em/en dash and double hyphen; restructure those sentences with commas or parentheses.'];
        }

        // 2. Banned AI-tell phrases (word-boundary, case-insensitive; entries
        //    may contain a limited regex like "take your .* to the next level").
        $found = [];
        foreach (ContentAutopilotConfig::bannedPhrases() as $phrase) {
            $pattern = '/\b'.str_replace('\.\*', '.{0,40}', preg_quote($phrase, '/')).'\b/u';
            $n = preg_match_all($pattern, $lower);
            if ($n > 0) {
                $found[$phrase] = $n;
            }
        }
        if ($found !== []) {
            $list = implode('", "', array_slice(array_keys($found), 0, 12));
            $issues[] = ['code' => 'banned_phrases', 'count' => array_sum($found),
                'message' => 'Replace these giveaway phrases with plain, specific language: "'.$list.'".'];
        }

        // 3. Sentence-length variance floor. Uniform sentence length is a
        //    strong machine-writing signal.
        $lengths = $this->sentenceWordCounts($text);
        if (count($lengths) >= 12) {
            $mean = array_sum($lengths) / count($lengths);
            $variance = array_sum(array_map(fn ($l) => ($l - $mean) ** 2, $lengths)) / count($lengths);
            $stddev = sqrt($variance);
            if ($stddev < 5.0) {
                $issues[] = ['code' => 'uniform_sentences',
                    'message' => 'Sentence lengths are too uniform. Mix very short sentences (under 8 words) with long ones (over 20 words).'];
            }
            $short = count(array_filter($lengths, fn ($l) => $l <= 8));
            if ($short === 0) {
                $issues[] = ['code' => 'no_short_sentences',
                    'message' => 'Add several punchy short sentences (under 8 words) for rhythm.'];
            }
        }

        // 4. Transition-word density ceiling ("However," "Moreover," openers).
        $transitions = preg_match_all(
            '/(?:^|[.!?]\s+)(however|therefore|thus|consequently|indeed|notably|importantly|essentially|significantly)\b/i',
            $text
        );
        $sentences = max(1, count($lengths));
        if ($lengths !== [] && $transitions / $sentences > 0.12) {
            $issues[] = ['code' => 'transition_density', 'count' => (int) $transitions,
                'message' => 'Too many sentences start with connector adverbs (However, Therefore...). Rewrite most of them to start with the subject.'];
        }

        // 5. Repeated n-gram detector — the same 6-word run appearing 3+
        //    times is template writing.
        $repeats = $this->repeatedNgrams($lower, 6, 3);
        if ($repeats !== []) {
            $issues[] = ['code' => 'repeated_phrasing', 'count' => count($repeats),
                'message' => 'These word runs repeat too often, rephrase each occurrence differently: "'.implode('", "', array_slice($repeats, 0, 5)).'".'];
        }

        // 6. Uniform paragraphs (every paragraph 3-4 sentences = template).
        $paragraphSentences = $this->paragraphSentenceCounts($html);
        if (count($paragraphSentences) >= 6) {
            $distinct = count(array_unique($paragraphSentences));
            if ($distinct <= 2) {
                $issues[] = ['code' => 'uniform_paragraphs',
                    'message' => 'Paragraphs are all the same size. Vary them: mix 1-2 sentence paragraphs with longer ones.'];
            }
        }

        // 7. Rhetorical-question budget (max 1).
        $questions = mb_substr_count($text, '?');
        if ($questions > 2) {
            $issues[] = ['code' => 'question_overuse', 'count' => $questions,
                'message' => 'Too many rhetorical questions; keep at most one in the whole article.'];
        }

        return $issues;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return list<int> */
    private function sentenceWordCounts(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $counts = [];
        foreach ($sentences as $sentence) {
            $words = str_word_count(strip_tags($sentence));
            if ($words >= 2) {
                $counts[] = $words;
            }
        }

        return $counts;
    }

    /** @return list<int> sentences per <p> block */
    private function paragraphSentenceCounts(string $html): array
    {
        if (! preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $m)) {
            return [];
        }

        $counts = [];
        foreach ($m[1] as $p) {
            $text = trim(strip_tags($p));
            if ($text === '') {
                continue;
            }
            $counts[] = max(1, preg_match_all('/[.!?](?:\s|$)/u', $text));
        }

        return $counts;
    }

    /** @return list<string> n-grams appearing >= $minCount times */
    private function repeatedNgrams(string $lowerText, int $n, int $minCount): array
    {
        $words = preg_split('/[^a-z0-9\']+/u', $lowerText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < $n * 2) {
            return [];
        }

        $seen = [];
        for ($i = 0, $max = count($words) - $n; $i <= $max; $i++) {
            $gram = implode(' ', array_slice($words, $i, $n));
            $seen[$gram] = ($seen[$gram] ?? 0) + 1;
        }

        $repeats = array_keys(array_filter($seen, fn ($count) => $count >= $minCount));

        // Drop overlapping sub-runs of the same repeat (keep the first).
        return array_values(array_unique($repeats));
    }
}
