<?php

namespace App\Services\Content\Aeo;

use App\Support\UnicodeText;

/**
 * How quotable an article is to an answer engine — scored separately from SEO.
 *
 * A model does not read a page the way a ranking algorithm does. It retrieves
 * a *passage* and reuses it, so the unit that matters is the self-contained
 * chunk: a heading that states a question, an answer directly underneath that
 * makes sense lifted out of the page, a definition that does not depend on the
 * paragraph above it. An article can rank well and still be unquotable, which
 * is why this is a second number rather than more weight on the first.
 *
 * **Deliberately NOT part of ContentSeoScorer.** The publish floor gates on
 * `seo_score` (`ContentArticleProducer:322`); adding ~24 points of new weight
 * there would drop every site's score overnight and start failing articles
 * that were fine yesterday. Two numbers side by side is also what the original
 * design called for (docs/architecture/30-simulation.md:281).
 *
 * Same shape as its sibling on purpose: pure, no I/O, one `$add()` closure, and
 * every fix message written as an instruction the reviser can act on verbatim.
 */
class AeoScorer
{
    /** Bump when a rule changes meaning, so stored scores stay comparable. */
    public const VERSION = 1;

    /** Words a direct answer should fit in — long enough to be useful, short
     *  enough for a model to lift whole. */
    private const ANSWER_MIN_WORDS = 15;

    private const ANSWER_MAX_WORDS = 60;

    /** Below this there is no article to judge. */
    private const MIN_BODY_WORDS = 50;

    /** Openers that make a passage meaningless once it is lifted out. */
    private const DANGLING_OPENERS = [
        'it ', 'this ', 'that ', 'these ', 'those ', 'they ', 'he ', 'she ',
        'such ', 'here ', 'there ', 'above ', 'below ', 'the former', 'the latter',
        'however,', 'therefore,', 'also,', 'additionally,', 'furthermore,', 'moreover,',
    ];

    /**
     * @param  array{target_keyword?: string, author_name?: ?string, published_at?: ?string}  $context
     * @return array{score: int, version: int, issues: list<array{code: string, weight: int, message: string}>, checks: list<array{code: string, passed: bool, weight: int}>}
     */
    public function score(string $html, string $h1, array $context = []): array
    {
        $title = trim($h1) !== '' ? trim($h1) : trim((string) ($context['target_keyword'] ?? ''));
        $body = $this->stripWidgets($html);
        $blocks = $this->blocks($body);

        // Nothing to score. Returning a partial score here would be the worst
        // kind of wrong: a stub article that "passed" the checks it could not
        // fail (no sections, so no dangling openers) scored 36 before this.
        if (UnicodeText::wordCount($this->text($body)) < self::MIN_BODY_WORDS) {
            return [
                'score' => 0,
                'version' => self::VERSION,
                'issues' => [[
                    'code' => 'no_article',
                    'weight' => 100,
                    'message' => 'There is not enough text here to judge — write the article first.',
                ]],
                'checks' => [],
            ];
        }

        $checks = [];
        $issues = [];
        $add = function (string $code, int $weight, bool $passed, string $fix) use (&$checks, &$issues): void {
            $checks[] = ['code' => $code, 'passed' => $passed, 'weight' => $weight];
            if (! $passed) {
                $issues[] = ['code' => $code, 'weight' => $weight, 'message' => $fix];
            }
        };

        // ── The answer itself ───────────────────────────────────────────
        // The single most valuable thing on the page: a passage near the top
        // that answers the title outright, so a model can quote it without
        // reading on.
        $opening = $this->openingAnswer($blocks, $title);
        $add(
            'answer_first',
            10,
            $opening !== null,
            'Open with a direct answer to "'.$title.'" in its own paragraph of '
            .self::ANSWER_MIN_WORDS.'-'.self::ANSWER_MAX_WORDS.' words, before any background or preamble.'
        );

        // A question heading with the answer buried under a list is a passage
        // a model cannot lift. Each question must be answered in prose first.
        [$questionHeadings, $answeredHeadings] = $this->questionAnswerPairs($blocks);
        // Only scored when there ARE question headings — crediting an article
        // for passing a check it cannot fail is how an empty page scores 36.
        $questionHeadings > 0 and $add(
            'answer_after_question',
            8,
            $answeredHeadings === $questionHeadings,
            'Every question heading must be followed immediately by a short paragraph that answers it. '
            .'Put any list or table after that paragraph, not instead of it.'
        );

        $headings = $this->headings($blocks);
        $questionShare = $headings === [] ? 0.0 : $questionHeadings / count($headings);
        $add(
            'question_headings',
            6,
            $questionShare >= 0.25,
            'Rewrite at least a quarter of the H2/H3 headings as the questions a reader would actually ask, '
            .'in their words.'
        );

        // ── Self-contained passages ─────────────────────────────────────
        // The check that matters most for retrieval and the one writers get
        // wrong: a section that opens with "It also..." is meaningless once
        // quoted on its own.
        $dangling = $this->danglingSections($blocks);
        $this->headings($blocks) !== [] and $add(
            'self_contained_sections',
            8,
            $dangling === [],
            'These sections start with a word that refers back to the previous one, so they make no sense quoted '
            .'alone — rewrite their first sentence to name the subject: "'.implode('", "', array_slice($dangling, 0, 3)).'".'
        );

        $add(
            'entity_definition',
            5,
            $this->hasDefinition($body, $title),
            'Define the subject plainly in the first two paragraphs — one sentence of the form '
            .'"<subject> is ..." that a reader could quote on its own.'
        );

        // ── Evidence ────────────────────────────────────────────────────
        $add(
            'cited_claims',
            5,
            $this->citedClaims($body) >= 1,
            'Support at least one specific number or claim with a named source in the same sentence '
            .'(for example "according to <source>"), and link it.'
        );

        $add(
            'structured_block',
            4,
            preg_match('/<(table|ul|ol|dl)\b/i', $body) === 1,
            'Add a comparison table or a list where the content is genuinely list-shaped — steps, options, specs.'
        );

        // ── Who says so ─────────────────────────────────────────────────
        // Answer engines lean on a named author and a date. This is satisfied
        // deterministically at publish time when the client has set an author,
        // so it is never something the model is asked to invent.
        $add(
            'byline_or_date',
            4,
            filled($context['author_name'] ?? null) || $this->hasVisibleDate($body),
            'Add the author and the date this was last reviewed. Set them in Content settings so every article '
            .'carries them automatically.'
        );

        // ── Shape ───────────────────────────────────────────────────────
        $add(
            'scannable_paragraphs',
            3,
            $this->longParagraphShare($blocks) <= 0.25,
            'Break the longest paragraphs up — an answer engine quotes a passage, and a wall of text has no '
            .'passage to quote. Aim for 2-4 sentences each.'
        );

        $total = array_sum(array_column($checks, 'weight'));
        $earned = array_sum(array_map(static fn (array $c): int => $c['passed'] ? $c['weight'] : 0, $checks));

        usort($issues, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'score' => $total > 0 ? (int) round($earned / $total * 100) : 0,
            'version' => self::VERSION,
            'issues' => $issues,
            'checks' => $checks,
        ];
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Top-level blocks as [tag, text], in document order.
     *
     * @return list<array{tag: string, text: string}>
     */
    private function blocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $out = [];
        if (preg_match_all('#<(h[1-6]|p|ul|ol|dl|table|blockquote)\b[^>]*>(.*?)</\1>#is', $html, $m, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($m as $match) {
            $out[] = [
                'tag' => strtolower($match[1]),
                'text' => $this->text($match[2]),
            ];
        }

        return $out;
    }

    private function text(string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Drops the Key-takeaways box and the TOC. They render above the article
     * but are widgets, not its opening — the same correction ContentSeoScorer
     * needed when it read summary bullets as the intro (prod 2026-07-28).
     */
    private function stripWidgets(string $html): string
    {
        $html = preg_replace('#<nav\b[^>]*class="[^"]*content-toc[^"]*"[^>]*>.*?</nav>#is', '', $html) ?? $html;
        $html = preg_replace('#<(div|aside|section)\b[^>]*class="[^"]*key-takeaways[^"]*"[^>]*>.*?</\1>#is', '', $html) ?? $html;

        return $html;
    }

    /** @return list<array{tag: string, text: string}> */
    private function headings(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (array $b): bool => in_array($b['tag'], ['h2', 'h3'], true)));
    }

    private function isQuestion(string $text): bool
    {
        if (str_contains($text, '?')) {
            return true;
        }

        // Question words at the start catch headings written without the mark.
        return preg_match('/^(how|what|why|when|where|which|who|can|do|does|is|are|should)\b/i', $text) === 1;
    }

    /**
     * The opening answer: the first real paragraph, if it answers rather than
     * sets the scene.
     *
     * Length alone cannot tell the two apart — a 62-word ramble through the
     * history of perfume fits the same window as a 32-word answer. What
     * separates them is WHERE the subject appears: an answer names it in its
     * first sentence, a preamble reaches it eventually.
     */
    private function openingAnswer(array $blocks, string $title): ?string
    {
        foreach ($blocks as $block) {
            if ($block['tag'] !== 'p') {
                continue;
            }
            $words = UnicodeText::wordCount($block['text']);
            if ($words < 5) {
                continue;   // a stray caption or lead-in
            }
            if ($words < self::ANSWER_MIN_WORDS || $words > self::ANSWER_MAX_WORDS) {
                return null;
            }

            $firstSentence = preg_split('/(?<=[.!?])\s+/u', $block['text'])[0] ?? $block['text'];

            return $this->mentionsSubject($firstSentence, $title) ? $block['text'] : null;
        }

        return null;
    }

    /** Does this sentence name what the article is about? */
    private function mentionsSubject(string $sentence, string $title): bool
    {
        $terms = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', UnicodeText::fold($title)) ?: [],
            static fn (string $t): bool => mb_strlen($t) > 3
                && ! in_array($t, ['best', 'guide', 'your', 'with', 'from', 'that', 'this', 'what', 'when', 'which'], true)
        ));
        if ($terms === []) {
            return true;   // nothing distinctive to look for — do not punish
        }

        $haystack = UnicodeText::fold($sentence);
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: int} [question headings, those answered in prose] */
    private function questionAnswerPairs(array $blocks): array
    {
        $questions = 0;
        $answered = 0;
        foreach ($blocks as $i => $block) {
            if (! in_array($block['tag'], ['h2', 'h3'], true) || ! $this->isQuestion($block['text'])) {
                continue;
            }
            $questions++;
            $next = $blocks[$i + 1] ?? null;
            if ($next !== null && $next['tag'] === 'p' && UnicodeText::wordCount($next['text']) >= 10) {
                $answered++;
            }
        }

        return [$questions, $answered];
    }

    /**
     * Sections whose first paragraph opens with a back-reference.
     *
     * @return list<string> the headings of the offending sections
     */
    private function danglingSections(array $blocks): array
    {
        $bad = [];
        foreach ($blocks as $i => $block) {
            if (! in_array($block['tag'], ['h2', 'h3'], true)) {
                continue;
            }
            $next = $blocks[$i + 1] ?? null;
            if ($next === null || $next['tag'] !== 'p') {
                continue;
            }
            $opening = mb_strtolower($next['text']);
            foreach (self::DANGLING_OPENERS as $opener) {
                if (str_starts_with($opening, $opener)) {
                    $bad[] = $block['text'];
                    break;
                }
            }
        }

        return $bad;
    }

    private function hasDefinition(string $html, string $title): bool
    {
        $blocks = array_values(array_filter($this->blocks($html), static fn (array $b): bool => $b['tag'] === 'p'));
        $first = implode(' ', array_map(static fn (array $b): string => $b['text'], array_slice($blocks, 0, 2)));
        if ($first === '') {
            return false;
        }
        if (preg_match('/<(dl|dt)\b/i', $html) === 1) {
            return true;
        }

        // "X is/are/means/refers to ..." — a sentence a model can lift as the
        // definition of the thing.
        return preg_match('/\b\w[\w\s\-]{2,60}\s+(is|are|means|refers to)\s+(a|an|the|when|any|\w)/iu', $first) === 1;
    }

    /** Numbers backed by a source in the same sentence. */
    private function citedClaims(string $html): int
    {
        $hits = 0;
        foreach (preg_split('/(?<=[.!?])\s+/u', $this->text($html)) ?: [] as $sentence) {
            $hasNumber = preg_match('/\d/u', $sentence) === 1;
            $hasSource = preg_match('/\b(according to|per|source:|reports?|study|survey|research|data from)\b/iu', $sentence) === 1;
            if ($hasNumber && $hasSource) {
                $hits++;
            }
        }

        return $hits;
    }

    private function hasVisibleDate(string $html): bool
    {
        $text = $this->text($html);

        return preg_match('/\b(updated|reviewed|published|last reviewed)\b[^.]{0,30}\d{4}/iu', $text) === 1
            || preg_match('/\b\d{1,2}\s+\p{L}{3,}\s+\d{4}\b/u', $text) === 1;
    }

    private function longParagraphShare(array $blocks): float
    {
        $paras = array_values(array_filter($blocks, static fn (array $b): bool => $b['tag'] === 'p'));
        if ($paras === []) {
            return 0.0;
        }
        $long = 0;
        foreach ($paras as $p) {
            if (count(array_filter(preg_split('/(?<=[.!?])\s+/u', $p['text']) ?: [])) > 5) {
                $long++;
            }
        }

        return $long / count($paras);
    }
}
