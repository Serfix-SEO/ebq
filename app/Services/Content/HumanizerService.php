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
        - Output raw HTML only. Never wrap the response in markdown code fences (```), and never emit backticks.
        - Use contractions naturally (it's, don't, you'll).
        - Vary sentence length hard: mix short sentences (under 8 words) with longer ones (over 20 words). Never write three consecutive sentences of similar length.
        - Vary paragraph length: some 1-2 sentences, some 4-5. Never uniform.
        - At most ONE rhetorical question in the whole article.

        SOUND LIKE A REAL PERSON, NOT AN LLM. These are the tells AI writing leaves — avoid every one:
        - NO cute or forced metaphors and analogies (e.g. "this is the Swiss Army knife of X", "a symphony of flavors"). Say the plain thing plainly. A metaphor is allowed only if a normal person would actually use it.
        - DO NOT INVENT fake-specific examples to sound authoritative. Never fabricate product names, brand names, made-up username/word mash-ups, statistics, studies, or quotes. Use examples that are real and verifiable, examples taken from the brief, or examples flagged as hypothetical in plain words. Synthetic-sounding invented specifics (e.g. "Mossback Whisperfin", "1993Leaf", "GullRust") are the #1 AI giveaway.
        - NEVER cite research, studies, universities, surveys, experts, or statistics unless a real, checkable source is given to you in the brief. Do NOT write things like "a study from the University of York found..." or "research shows that 70% of users...". Inventing academic citations to sound credible is a glaring AI tell and a factual-integrity failure. If you don't have a real source, make the point from plain reasoning instead.
        - DO NOT pad with hypothetical scenarios or manufactured emotion. Skip "Think about the last time...", "Imagine you...", "Picture this", "We've all been there", and the pattern of (plain statement) then (invented scenario) then (why it emotionally matters). State the point and move on.
        - Be EXACT or say nothing. Don't hedge vague half-specifics ("it takes about 10, 15 minutes depending on your setup"). Give the exact figure if you actually know it, otherwise leave it out — vague hedged numbers signal a model guessing.
        - Cover the topic at a sensible depth. Do NOT inflate a simple question into psychology, branding, philosophy, and academic research just to hit a length. Breadth for its own sake reads as SEO filler.
        - DO NOT coin jargon and present it as established (e.g. "evergreen words", "the X-plus-Y method"). Use language your reader already knows.
        - DO NOT make every section a rule or command. Vary the shape: some sections explain, some tell a short real scenario, some weigh a trade-off, some answer a question. Not every H2 should be an imperative.
        - DO NOT stack parallel example patterns ("X plus Y works: A. Y plus Z: B."). Real writers don't template their examples.
        - Drop the over-signposting and the checklist voice. Write in flowing prose, not a sequence of terse directives.
        - Take a clear stance. Say what actually works and why, including trade-offs and the occasional "it depends". Mild, honest imperfection reads as human; relentless polish reads as a machine.
        - NEVER fabricate personal experience or a backstory. Do NOT write "I've been doing this since the early days", "I've tested dozens of these", "in my years in this field", or any invented anecdote/credential. You are writing for a brand, not pretending to be an individual with a fake history. Establish authority through genuinely useful, concrete, correct information — not a made-up personal story. (A first-hand-sounding but unverifiable anecdote is a top AI tell.) You may use a light second-person "you" voice; avoid first-person "I did X" claims you cannot back up.
        - NO unevidenced hype or dramatic contrasts. Ban the "It doesn't just X. It Y." construction and cousins ("This isn't just about X, it's about Y"). Ban vague persuasion with no basis ("that psychological edge is real", "this changes everything"). If you can't support a claim, cut it or state it plainly.
        - BREAK PARALLELISM inside sentences. Avoid tidy triples like "short enough to remember, easy to spell, and it says something about you." Real writers list two things, or four uneven ones, or bury the point mid-sentence.
        - Open the piece and most sections with something specific (a scenario, a concrete case, a direct answer), NOT a dictionary-style definition ("A good X is...").
        - A deliberate sentence fragment for emphasis is fine. So is starting a sentence with "And" or "But".

        WRITE LIKE SOMEONE WHO ACTUALLY KNOWS THE TOPIC (this is what separates human-grade content from generated filler):
        - BE SPECIFIC AND PRACTICAL. The single biggest signal of real content is concrete, checkable detail: exact values, real settings names, precise steps, actual limits, version/platform differences, copy-ready examples. Wherever the topic allows it, give the reader something they can act on immediately, not a paragraph about why the topic matters.
        - SPECIFICS MUST BE REAL, NEVER MANUFACTURED. Only state specifics you actually know as stable facts (standard values, menu names, currencies, character limits). NEVER invent history or events: no "in mid-2025 many users reported X stopped working", no "after the last update...", no "the community found...", no version-and-date compatibility claims ("the latest version as of early 2026 supports..."). You cannot know these. False precision is a worse tell than vagueness. If you don't know when or whether something changed, say nothing about it.
        - Explain each concept ONCE. Do not circle back and re-explain the same mechanism in later sections with slightly different words.
        - Do not run a benefit → drawback → "balanced verdict" seesaw in section after section. Vary how you weigh things; some points are just good or just bad.
        - No dramatic sign-offs or sweeping historical arcs ("This approach has been around forever... enjoy it while it lasts."). End on something useful.
        - NARROW SCOPE. Answer the core query well and stop. Do NOT bolt on tangential "cover every angle" sections (loosely-related sub-topics, adjacent tools, edge platforms, history, legal asides) unless the topic is specifically about them. A tight article on one intent reads human; a sprawling "complete guide" to every adjacent query reads like SEO generation.
        - NO sweeping unverifiable claims. Not "works on all regions/all versions", not "Krafton has been stricter lately", not "this is the most reliable" unless it is a plainly known fact. Scope every claim to what is actually true and stable.
        - KEYWORD PLACEMENT: use the exact focus phrase enough to meet the SEO density target (about once every 120-160 words), spread evenly across the article. Weave each mention into a natural sentence; do not cluster several into one paragraph or open consecutive sentences with it.
        - STRUCTURE AROUND THE SEARCHER'S REAL QUESTIONS. Someone searching this phrase wants specific answers (does it work? which one? how exactly? why did it fail? is it allowed?). Lead with those. Do not open with a copywriting hook or a "why X matters" preamble — open with the direct answer or the concrete problem.
        - Use a comparison TABLE when the content is genuinely tabular (options vs attributes, platform A vs B, before/after). A tight table reads as researched; a wall of parallel sentences reads as generated.
        - HEDGE claims you cannot prove. Never make universal absolutes ("works on every version", "always", "guaranteed"). Use "usually", "as of now", "in most cases", "generally". Overconfident universal claims are an AI tell. (And do NOT swap them for a fake personal test — just scope the claim honestly.)
        - Trim anything that isn't useful. If a sentence doesn't help the reader do or decide something, cut it. Depth means more specifics, not more words.

        - Prefer concrete specifics (numbers, examples, named things FROM THE BRIEF) over generic claims — but never invent them.
        - Use bullet lists ONLY where content is genuinely list-shaped; most sections should be prose.
        - No formulaic intro ("In this article we will...") and no formulaic closing ("In conclusion...").
        - Write like an experienced practitioner sharing what they actually know, not like a brochure or a how-to robot.
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

        // Strip markdown code fences the model sometimes wraps HTML in
        // (```html … ```), plus any stray backticks — a visible AI tell.
        $out = preg_replace('/```[a-z]*\n?/i', '', $out) ?? $out;
        $out = str_replace('`', '', $out);

        // Collapse ", ," artifacts and doubled spaces introduced above.
        $out = preg_replace('/\s*,\s*,/', ',', $out) ?? $out;

        return preg_replace('/ {2,}/', ' ', $out) ?? $out;
    }

    /**
     * Deterministic style lint over article HTML. Each issue:
     * {code, message, count?} — messages are written as revision
     * instructions the LLM can act on.
     *
     * $blockedTerms/$blockedDomains: the plan's competitor-mention guard
     * (CompetitorMentionGuard::termsForTopic / blockedDomains). A mention or a
     * link to a blocked competitor becomes a style issue, which the producer's
     * revise loop hard-gates on exactly like the dash ban — the article cannot
     * ship READY while one remains.
     *
     * @param  list<string>  $blockedTerms
     * @param  list<string>  $blockedDomains
     * @return list<array{code:string, message:string, count?:int}>
     */
    public function lint(string $html, array $blockedTerms = [], array $blockedDomains = []): array
    {
        $issues = [];
        $text = $this->toText($html);
        $lower = mb_strtolower($text);

        // 0. Competitor mentions (per-plan, optional). Word-boundary and
        //    case-insensitive like the banned phrases below; links are checked
        //    against the HTML because hrefs do not survive toText().
        if ($blockedTerms !== [] || $blockedDomains !== []) {
            $hits = [];
            foreach ($blockedTerms as $term) {
                $n = preg_match_all('/\b'.preg_quote(mb_strtolower($term), '/').'\b/u', $lower);
                if ($n > 0) {
                    $hits[$term] = $n;
                }
            }
            $linkHits = 0;
            foreach ($blockedDomains as $domain) {
                $linkHits += preg_match_all(
                    '/href\s*=\s*["\'][^"\']*'.preg_quote(mb_strtolower($domain), '/').'/iu',
                    $html
                );
            }
            if ($hits !== [] || $linkHits > 0) {
                $list = implode('", "', array_keys($hits));
                $issues[] = ['code' => 'competitor_mentions',
                    'count' => array_sum($hits) + $linkHits,
                    'message' => 'Remove every mention of and link to these competitor brands: "'.$list.'"'
                        .($linkHits > 0 ? ' (including '.$linkHits.' link(s) to their sites)' : '')
                        .'. Describe the tool or service generically instead, or refer to this site\'s own offering. Never name or recommend them.'];
            }
        }

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

        // 7. Rhetorical-question budget in PROSE (max 1). FAQ Q&A and
        //    question-style headings are legitimate, so strip the FAQ section
        //    and all heading text before counting — otherwise every
        //    FAQ-enabled article trips this falsely.
        $prose = $this->proseOnly($html);
        $questions = mb_substr_count($prose, '?');
        if ($questions > 1) {
            $issues[] = ['code' => 'question_overuse', 'count' => $questions,
                'message' => 'Too many rhetorical questions in the body. Keep at most one; rewrite the rest as statements. (FAQ questions are fine and do not count.)'];
        }

        // 8. Formal tone — expanded auxiliaries where a human would contract.
        //    The revise loop tends to un-contract ("you're" -> "you are");
        //    that stiffness is a strong AI/robotic tell.
        $expanded = preg_match_all('/\b(you are|it is|do not|does not|did not|that is|there is|cannot|can not|will not|would not|is not|are not|we are|they are|you will|you have|i am)\b/i', $lower);
        $contractions = preg_match_all("/\b\w+(?:'|\x{2019})(?:s|t|re|ll|ve|d|m)\b/u", $text);
        if ($expanded >= 6 && $contractions < $expanded) {
            $issues[] = ['code' => 'formal_tone', 'count' => (int) $expanded,
                'message' => 'The tone is too stiff: contract naturally (it\'s, you\'re, don\'t, that\'s). Replace expanded forms like "you are"/"do not"/"it is" with contractions wherever a person would.'];
        }

        // 9. Hype "X doesn't just A. It Bs." / "not just X but Y" contrast —
        //    a classic AI marketing tell, including the two-sentence variant
        //    ("They don't just label you. They announce you.").
        $hype = preg_match_all("/\\b(?:do|does|is|are|it|they|you)(?:n'|n\x{2019})?t?\\s*(?:not\\s+)?just\\b[^.?!]{0,60}[.?!]\\s+(?:it|they|this|that|you)\\b/iu", $text);
        $hype += preg_match_all('/\bnot just\b[^.?!]{0,50}?\bbut\b/i', $text);
        if ($hype > 0) {
            $issues[] = ['code' => 'hype_contrast', 'count' => (int) $hype,
                'message' => 'Remove dramatic "it doesn\'t just X, it Y" / "not just X but Y" constructions and any unevidenced hype; state the point plainly.'];
        }

        // 10. Fabricated research / citation — vague academic or statistical
        //     claims with no source. A top AI credibility tell (and a factual
        //     integrity problem). The article HTML rarely carries real
        //     citations, so any of these patterns is almost certainly invented.
        $citation = preg_match_all('/\b(?:a|one|recent)\s+(?:study|survey|report|research|paper)\s+(?:from|by|conducted|found|shows?|showed|suggests?)\b/i', $text);
        $citation += preg_match_all('/\bresearch(?:ers)?\s+(?:at|from|found|shows?|suggests?|discovered|have found)\b/i', $text);
        $citation += preg_match_all('/\baccording to (?:a|one|recent|the)\s+(?:study|survey|report|research)\b/i', $text);
        $citation += preg_match_all('/\bstudies (?:show|have shown|found|suggest)\b/i', $text);
        // "<number>% of <plural group>" — niche-agnostic (people, users,
        // customers, players, chefs, marketers, patients, …). Any bare stat
        // about a group with no source is almost always invented.
        $citation += preg_match_all('/\b\d{1,3}%\s+of\s+(?:all\s+|the\s+)?[a-z]{3,}(?:s|people)\b/i', $text);
        if ($citation > 0) {
            $issues[] = ['code' => 'fabricated_citation', 'count' => (int) $citation,
                'message' => 'Remove invented research/statistics ("a study from...", "research shows", "70% of users..."). Do not cite studies, surveys, or percentages without a real source; make the point from plain reasoning instead.'];
        }

        // 11. Fabricated consensus / false-precision history — "many players
        //     reported...", "the community found...", "in mid-2025 X stopped
        //     working". The writer has no sources, so any claimed community
        //     event or dated change is invented. ("As of ..." present-state
        //     hedging is allowed and not matched here.)
        // Niche-agnostic: "<many/most/some/several> <any group> <report/say/
        // claim/found/agree>" catches invented consensus in ANY vertical
        // (players, customers, chefs, developers, marketers, patients...).
        $consensus = preg_match_all('/\b(?:many|most|some|several|countless|plenty of)\s+(?:\w+\s+){0,2}(?:report(?:ed)?|say|said|claim(?:ed)?|note[d]?|found|agree[d]?|complain(?:ed)?)\b/i', $text);
        $consensus += preg_match_all('/\bthe\s+(?:community|industry|consensus|internet|experts?)\s+(?:found|reported|discovered|agrees?|noticed|says?)\b/i', $text);
        $consensus += preg_match_all('/\b(?:in|since|after|until)\s+(?:early|mid|late)[- ]?20\d\d\b/i', $text);
        if ($consensus > 0) {
            $issues[] = ['code' => 'fabricated_consensus', 'count' => (int) $consensus,
                'message' => 'Remove invented consensus and dated event claims ("many users reported...", "the community found...", "in mid-2025 X changed", "since the last update..."). You have no source for these; state only stable facts, and drop timeline claims entirely.'];
        }

        return $issues;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Body prose only: drops the FAQ section (its Q&A questions are wanted,
     * not a tell) and all heading text (a question-form H2/H3 is fine). Used
     * for the rhetorical-question budget so FAQ articles aren't false-flagged.
     */
    private function proseOnly(string $html): string
    {
        // Remove the FAQ section: an <h2> whose text marks FAQ, through the
        // next <h2> or end of document.
        $html = preg_replace(
            '/<h2\b[^>]*>\s*(?:frequently asked|faqs?|common questions)[^<]*<\/h2>.*?(?=<h2\b|$)/is',
            ' ',
            $html
        ) ?? $html;
        // Drop all heading inner text.
        $html = preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html) ?? $html;

        return $this->toText($html);
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
