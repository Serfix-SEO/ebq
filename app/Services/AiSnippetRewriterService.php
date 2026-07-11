<?php

namespace App\Services;

use App\Services\Llm\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered title + meta description rewrites for the live editor.
 *
 * Two modes:
 *   - **Auto** (no intent passed) — returns 3 rewrites across DIFFERENT
 *     angles, like the original behaviour. Good "show me what's possible"
 *     mode for users who don't know which angle they want.
 *   - **Single intent** (intent key passed) — returns 3 *variations* of
 *     that one intent. Different headlines, hooks, lengths — all still
 *     within the angle the user picked. Cached separately per intent so
 *     the user can A/B between intents without re-burning LLM tokens.
 *
 * The intent registry below documents every angle with a one-line
 * description + a strict structural rule the model must follow when that
 * intent is selected (e.g. list-based titles must start with a number).
 *
 * Caching
 * ───────
 * Caches per (post_id × content-hash × focus-keyword × top-3-hash × intent)
 * for 7 days. Re-clicks within a week are free. Each intent has its own
 * cache slot so flipping intents doesn't invalidate prior generations.
 *
 * MOAT
 * ────
 * Prompt + few-shot examples + competitor SERP grounding all live on EBQ.
 * Plugin only sees rewrites + rationale text. Free-tier uninstall = lose
 * the only "make my snippet better" button in the editor.
 */
class AiSnippetRewriterService
{
    private const CACHE_TTL_DAYS = 7;

    /**
     * Prompt revision token — bake into the cache key so any prompt-policy
     * change (stricter focus-keyword enforcement, new constraint, new
     * power-word list…) automatically invalidates cached generations.
     * Without this, users see week-old rewrites that predate the new
     * rules and complain "this still doesn't follow the rule".
     *
     * Bump whenever you change buildPrompt() in a way that should produce
     * different output for the same content.
     *
     * v3 — 2026-04-30: focus keyword now MUST appear verbatim in both
     * title and meta; post-validation drops rewrites that fail the check.
     * v4 — 2026-04-30: title and meta length (50–60 / 130–155) promoted
     * to non-negotiable rules with hard post-validation; out-of-range
     * rewrites are dropped rather than served and truncated downstream.
     * v5 — 2026-04-30: title window widened to 30–60 (Yoast's range);
     * meta stays at 130–155.
     * v6 — 2026-04-30: backend now COERCES out-of-range output back into
     * the window via word/sentence-boundary truncation or curated suffix/
     * CTA padding. Users never see a "regenerate" length error — even
     * when the model drifts, post-processing pulls the title/meta into
     * range. Only a missing focus keyword is still grounds for skipping
     * a rewrite (since splicing in a keyword would break grammar).
     * v7 — 2026-04-30: removed truncation/padding (caused incomplete
     * sentences). Switched primary model to mistral-medium-latest and
     * added a feedback-driven retry — when any rewrite drifts, the
     * model is re-prompted with concrete per-rewrite drift descriptions
     * ("title 67 chars, over by 7") and asked to rewrite from scratch
     * rather than have its output post-trimmed.
     * v8 — 2026-04-30: rewrites per run bumped from 3 to 10 so users
     * get a real shortlist; max_tokens scaled accordingly.
     * v9 — 2026-04-30: human-voice rules added (no em-dashes, no AI
     * jargon list, sentence-cadence guidance). Output sanitised to
     * strip em/en-dashes and curly quotes before returning.
     */
    private const PROMPT_VERSION = 'v9';

    /** Sentinel for the "give me a mixed-angle set" mode. */
    public const INTENT_AUTO = 'auto';

    /**
     * How many distinct title+meta rewrites we ask the model to produce
     * (and accept) per request. Bumped from 3 to 10 so users have a real
     * shortlist to A/B from. Each rewrite is a JSON object so 10 fits
     * comfortably under the model's max_tokens budget.
     */
    private const REWRITES_PER_RUN = 10;

    /**
     * Human-voice rules. Every AI title surface gets these so output
     * doesn't read like "AI wrote it". Researched from real AI-detector
     * signals + the patterns Google's quality-rater guidelines flag as
     * "robotic / unhelpful". Single source of truth — both this service
     * and AiBlockEditorService include this same block.
     *
     * The rules cover three categories:
     *   1. Punctuation that flags AI authorship (em-dash, en-dash)
     *   2. Jargon / cliché phrases AI models default to
     *   3. Sentence-construction patterns that read as machine-paced
     */
    public const HUMAN_VOICE_RULES = <<<'RULES'
HUMAN VOICE — write so the result reads as written by a knowledgeable human, not an AI:

PUNCTUATION (strict):
- NEVER use em-dashes (—) or en-dashes (–). Use commas, periods, parentheses, or restructure the sentence. Em-dashes are the strongest single signal of AI authorship in 2026.
- Use straight quotes ('), not curly quotes (' ' " ").
- Avoid the AI rhythm of "X — Y", "X. Y" or "X, Y" or "X (Y)" reads more human.

JARGON & CLICHÉS — never use these phrases (this list is exhaustive of what to avoid; rewrite around them):
- "in today's fast-paced world", "in this article", "in this guide"
- "let's dive in", "let's explore", "delve into", "embark on", "navigate the world of"
- "unlock the secrets", "look no further", "ready to [verb]"
- "leverage", "utilize" (use "use"), "harness", "streamline", "optimize for success"
- "robust", "comprehensive", "seamless", "cutting-edge", "game-changer", "revolutionary"
- "empower", "elevate", "transform your", "take your X to the next level"
- "moreover", "furthermore", "additionally" at sentence start
- "it's worth noting that", "it's important to remember", "needless to say"
- "in conclusion", "to wrap up", "ultimately" as closers
- "journey" as a metaphor for any process
- generic intensifiers: "absolutely", "truly", "incredibly", "literally"

SENTENCE PATTERNS — avoid these AI tells:
- Opening every paragraph with the same connective.
- The "not just X but Y" parallel.
- Triplet lists where every comparison comes in threes ("X, Y, and Z").
- Hedging stacks: "could potentially", "may possibly", "tends to often".
- Empty hype openers ("Picture this:", "Imagine if:").

POSITIVE TARGETS:
- Use contractions where natural (don't, you're, it's, won't).
- Vary sentence length. Mix a 6-word sentence next to a 22-word one. AI text feels evenly paced.
- Speak directly to "you" the reader where natural.
- Prefer concrete, specific nouns over abstract ones (use "deadlines" not "scheduling priorities").
- Cut adverb pairs ("very important" → "important"; "quite useful" → "useful").
RULES;

    /**
     * Curated CTR-boosting "power words" the model can lean on when a
     * title/meta would otherwise read flat. Grouped here for legibility,
     * but the prompt sees them as a single shuffled subset so the model
     * doesn't bias toward any one bucket.
     *
     * Picked for SEO snippet relevance — words that show up in click-heavy
     * SERP titles and read as natural English. We deliberately exclude
     * over-used clickbait ("INSANE", "SHOCKING", "NEVER BEFORE SEEN")
     * because Google penalizes title-tag clickbait via the rewrite layer.
     *
     * @var list<string>
     */
    private const POWER_WORDS = [
        // Value / outcome
        'proven', 'effective', 'practical', 'actionable', 'real',
        'powerful', 'reliable', 'trusted', 'guaranteed',

        // Speed / ease
        'fast', 'quick', 'simple', 'easy', 'straightforward',
        'instant', 'in minutes', 'step-by-step',

        // Comprehensiveness / authority
        'complete', 'definitive', 'ultimate', 'essential', 'comprehensive',
        'expert', 'tested', 'data-driven', 'researched',

        // Curiosity / contrast
        'avoid', 'mistake', 'truth', 'overlooked', 'forgotten',
        'underrated', 'real reason', 'actually',

        // Currency / freshness
        'updated', 'latest', 'new', '2026', 'modern',

        // Specificity hooks
        'every', 'top', 'best', 'free', 'exclusive',
        'beginner', 'advanced', 'pro', 'small-team',

        // Action verbs that read well in titles
        'master', 'boost', 'cut', 'fix', 'unlock',
        'save', 'speed up', 'rank', 'win', 'grow',
    ];

    /**
     * Intent registry. Each value:
     *   - label    : short human label for the picker UI
     *   - desc     : one-line description (also shown as picker tooltip)
     *   - rule     : the structural rule the model must follow when this
     *                intent is the single requested angle. Strict enough
     *                that 3 variations within the intent stay coherent.
     *
     * @var array<string, array{label: string, desc: string, rule: string}>
     */
    public const INTENTS = [
        // — User-requested 10 —
        'list_based' => [
            'label' => 'List-based',
            'desc'  => 'Listicle or "Top N" framing, scannable and high-CTR for comparison queries.',
            'rule'  => 'Title MUST start with a number ("7", "12", etc.) followed by a noun phrase. Meta MUST hint at what the list contains.',
        ],
        'problem_solution' => [
            'label' => 'Problem → Solution',
            'desc'  => 'Names the pain in the title, promises relief in the meta. Direct-response framing.',
            'rule'  => 'Title MUST name a concrete pain or mistake. Meta MUST present the page as the resolution in one short sentence.',
        ],
        'beginner_friendly' => [
            'label' => 'Beginner-friendly',
            'desc'  => 'Wide-funnel, gentle entry point. Targets "for beginners" / "explained simply" intent.',
            'rule'  => 'Title MUST contain a beginner cue ("for beginners", "explained", "from scratch", "step by step"). Meta MUST avoid jargon and promise plain language.',
        ],
        'question_based' => [
            'label' => 'Question-based',
            'desc'  => 'Featured-snippet + voice-search optimized. Title is the question; meta is the one-line answer + expansion.',
            'rule'  => 'Title MUST be a complete question ending with "?". Meta MUST start with a direct one-sentence answer, then a benefit hook.',
        ],
        'benefit_driven' => [
            'label' => 'Benefit-driven',
            'desc'  => 'Leads with the outcome the reader gets, not the topic. Best for solution-aware audiences.',
            'rule'  => 'Title MUST lead with the benefit ("Get…", "Cut…", "Save…", "Boost…", or a specific result). Meta MUST quantify the benefit if possible.',
        ],
        'authority_expert' => [
            'label' => 'Authority / expert',
            'desc'  => 'Trust-signal framing. Lab-tested, expert-reviewed, by-the-numbers. Best for YMYL topics.',
            'rule'  => 'Title MUST signal expertise or testing ("We tested", "Lab-verified", "By experts", "X data points analyzed"). Meta MUST reinforce with a credential cue.',
        ],
        'freshness_updated' => [
            'label' => 'Freshness / updated',
            'desc'  => 'Recency-CTR play with the current year and "updated" or "new for". Best for fast-moving topics.',
            'rule'  => 'Title MUST contain the current year or "Updated" / "New for". Meta MUST signal what was refreshed (data, picks, methods).',
        ],
        'use_case_focused' => [
            'label' => 'Use-case focused',
            'desc'  => 'Niches the title to a specific use-case (industry, role, scenario). Lower volume, higher CTR.',
            'rule'  => 'Title MUST narrow to a use case ("for SaaS", "for ecommerce", "for remote teams", "for beginners running ads"). Meta MUST reinforce the niche fit.',
        ],
        'myth_busting' => [
            'label' => 'Myth-busting',
            'desc'  => 'Contrarian hook ("X isn\'t true, Y is the real answer"). Pattern-interrupt for saturated SERPs.',
            'rule'  => 'Title MUST contain a contradiction or "isn\'t / not / wrong" hook. Meta MUST tease the corrected truth without spoiling the page.',
        ],
        'comparison_verdict' => [
            'label' => 'Comparison with verdict',
            'desc'  => 'X vs Y framing with a clear winner. Decisive comparison, beats wishy-washy "X vs Y compared" titles.',
            'rule'  => 'Title MUST contain "vs" or "or" between two named options AND signal a verdict ("which wins", "we picked", "the clear winner"). Meta MUST hint at the verdict\'s reasoning.',
        ],

        // — Existing 5 angles, kept for back-compat with old "Auto" output —
        'commercial' => [
            'label' => 'Commercial',
            'desc'  => 'Buyer-intent framing. Best for product / review / "best of" pages.',
            'rule'  => 'Title MUST signal commercial intent (best, top, review, buying). Meta MUST hint at decision-helping content.',
        ],
        'informational' => [
            'label' => 'Informational',
            'desc'  => 'Educational, encyclopedic. Wide-funnel learning intent.',
            'rule'  => 'Title MUST signal teaching ("What is", "Guide", "Explained"). Meta MUST promise comprehensive coverage.',
        ],
        'curiosity' => [
            'label' => 'Curiosity',
            'desc'  => 'Open-loop hook that withholds the answer slightly to drive the click. Use sparingly.',
            'rule'  => 'Title MUST create an information gap (surprising claim, counterintuitive frame). Meta MUST tease the resolution without revealing it.',
        ],
        'guide' => [
            'label' => 'Guide',
            'desc'  => 'Definitive long-form how-to. Pairs with deep-dive content.',
            'rule'  => 'Title MUST contain "Guide" / "Complete" / "Definitive" / "How to". Meta MUST signal step-by-step or comprehensive scope.',
        ],
        'comparison' => [
            'label' => 'Comparison',
            'desc'  => 'Side-by-side without taking a side. Good for objective review pages.',
            'rule'  => 'Title MUST name 2+ options being compared. Meta MUST signal balanced criteria.',
        ],
    ];

    public function __construct(private readonly LlmClient $llm) {}

    /** @return list<string> */
    public static function intentKeys(): array
    {
        return array_keys(self::INTENTS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   ok: bool,
     *   intent?: string,
     *   rewrites?: list<array{title: string, meta: string, rationale: string, angle: string}>,
     *   model?: string,
     *   cached?: bool,
     *   error?: string,
     * }
     */
    public function rewrite(int $postId, array $input): array
    {
        if (! $this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'llm_not_configured'];
        }

        $focusKeyword = trim((string) ($input['focus_keyword'] ?? ''));
        $currentTitle = trim((string) ($input['current_title'] ?? ''));
        $currentMeta = trim((string) ($input['current_meta'] ?? ''));
        $contentExcerpt = mb_substr(trim((string) ($input['content_excerpt'] ?? '')), 0, 4000);
        $competitorTitles = is_array($input['competitor_titles'] ?? null) ? $input['competitor_titles'] : [];
        $competitorTitles = array_slice(array_values(array_filter(array_map('strval', $competitorTitles))), 0, 3);
        $additionalKeywords = is_array($input['additional_keywords'] ?? null) ? $input['additional_keywords'] : [];
        $additionalKeywords = array_values(array_unique(array_filter(
            array_map(static fn ($v) => trim((string) $v), $additionalKeywords),
            static fn (string $v) => $v !== '' && mb_strtolower($v) !== mb_strtolower($focusKeyword)
        )));
        $additionalKeywords = array_slice($additionalKeywords, 0, 10);

        // Validate / normalize the requested intent. Anything not in the
        // registry collapses to AUTO so a stale client can't crash us.
        $intent = (string) ($input['intent'] ?? self::INTENT_AUTO);
        if ($intent !== self::INTENT_AUTO && ! array_key_exists($intent, self::INTENTS)) {
            $intent = self::INTENT_AUTO;
        }

        if ($focusKeyword === '') {
            return ['ok' => false, 'error' => 'missing_focus_keyword'];
        }
        if ($contentExcerpt === '') {
            return ['ok' => false, 'error' => 'content_too_short'];
        }

        $cacheKey = $this->cacheKey($postId, $focusKeyword, $currentTitle, $currentMeta, $contentExcerpt, $competitorTitles, $intent, $additionalKeywords);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }

        $messages = $this->buildPrompt($focusKeyword, $currentTitle, $currentMeta, $contentExcerpt, $competitorTitles, $intent, $additionalKeywords);
        // Snippet rewrites need solid constraint adherence (verbatim focus
        // keyword + 30–60 / 130–155 char windows). The default tier
        // routinely drifts on length; the premium tier is markedly better
        // at constraint following at modest cost overhead. Worth it for a
        // user-facing "Improve with AI" surface.
        $payload = $this->llm->completeJson($messages, [
            'model' => \App\Support\AiModelConfig::premiumModel(),
            'temperature' => 0.6,
            // 10 rewrites × ~280 output tokens each (title + meta +
            // rationale + JSON envelope) leaves comfortable headroom.
            'max_tokens' => 3200,
            'json_object' => true,
            'timeout' => 60,
        ]);

        if (! is_array($payload) || ! isset($payload['rewrites']) || ! is_array($payload['rewrites'])) {
            Log::warning('AiSnippetRewriterService: malformed LLM response', [
                'post_id' => $postId,
                'focus_keyword' => $focusKeyword,
                'intent' => $intent,
            ]);
            return ['ok' => false, 'error' => 'llm_parse_failed'];
        }

        // Default angle to the requested intent in single-intent mode so
        // the UI can still chip-tag rewrites even if the model omits it.
        $defaultAngle = $intent !== self::INTENT_AUTO ? $intent : 'general';

        // Validate against two hard rules:
        //   1. Focus keyword presence (verbatim).
        //   2. Length: title 30–60, meta 130–155.
        // When ALL rewrites fail OR every passing rewrite has a defect,
        // run ONE retry with concrete per-rewrite feedback ("title was 67
        // chars — over by 7"). This nudges the model to fix the real
        // issue without artificial truncation that would chop sentences
        // mid-thought. After retry, accept whatever passes.
        [$rewrites, $invalid] = $this->validateRewrites($payload['rewrites'], $focusKeyword, $defaultAngle);

        // Retry whenever we haven't hit the target count AND there's at
        // least one invalid rewrite to feed back to the model. Keep both
        // sets after retry — first-pass winners + retry winners — so the
        // user gets a full N-variation shortlist even when the first
        // pass nailed 7 and dropped 3.
        if (count($rewrites) < self::REWRITES_PER_RUN && $invalid !== []) {
            $retryPayload = $this->retryWithFeedback($messages, $invalid, $focusKeyword);
            if (is_array($retryPayload) && isset($retryPayload['rewrites']) && is_array($retryPayload['rewrites'])) {
                [$retried, $_] = $this->validateRewrites($retryPayload['rewrites'], $focusKeyword, $defaultAngle);
                // Keep first-pass winners first (they were generated with
                // the full angle diversity in mind), then fill with retry
                // winners. Dedupe by title.
                $seenTitles = [];
                $merged = [];
                foreach (array_merge($rewrites, $retried) as $rw) {
                    $key = mb_strtolower($rw['title']);
                    if (isset($seenTitles[$key])) continue;
                    $seenTitles[$key] = true;
                    $merged[] = $rw;
                    if (count($merged) >= self::REWRITES_PER_RUN) break;
                }
                $rewrites = $merged;
            }
        }

        if ($rewrites === []) {
            Log::warning('AiSnippetRewriterService: zero valid rewrites after retry', [
                'post_id' => $postId,
                'focus_keyword' => $focusKeyword,
                'intent' => $intent,
                'invalid_count' => count($invalid),
            ]);
            return ['ok' => false, 'error' => 'rewrites_invalid', 'message' => 'The model could not produce in-spec rewrites for this focus keyword. Try a shorter focus keyphrase or different intent.'];
        }

        $result = [
            'ok' => true,
            'intent' => $intent,
            'rewrites' => $rewrites,
            'model' => \App\Support\LlmProviderConfig::currentProvider(),
            'cached' => false,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $result, Carbon::now()->addDays(self::CACHE_TTL_DAYS));
        return $result;
    }

    /**
     * @param  list<string>  $competitorTitles
     * @param  list<string>  $additionalKeywords
     * @return list<array{role: string, content: string}>
     */
    private function buildPrompt(string $keyword, string $currentTitle, string $currentMeta, string $excerpt, array $competitorTitles, string $intent, array $additionalKeywords = []): array
    {
        $competitorBlock = $competitorTitles === []
            ? "(no competitor titles available)"
            : implode("\n", array_map(fn ($t, $i) => sprintf('  %d. %s', $i + 1, $t), $competitorTitles, array_keys($competitorTitles)));

        // Shuffle a 20-word subset of the power-words registry into the
        // prompt — the model picks zero/one/two per rewrite as it sees
        // fit. Sending all ~60 would just bias toward stuffing; a smaller
        // rotation per call keeps each generation feeling fresh.
        $shuffled = self::POWER_WORDS;
        shuffle($shuffled);
        $powerWordSubset = array_slice($shuffled, 0, 20);
        $powerWordsBlock = implode(', ', $powerWordSubset);

        $additionalBlock = $additionalKeywords === []
            ? '(none provided)'
            : implode(', ', $additionalKeywords);

        $system = <<<'SYS'
You are a senior SEO copywriter. You produce snappy, click-worthy SEO titles
and meta descriptions that beat what's already ranking for the target query.

NON-NEGOTIABLE RULES (a rewrite that breaks ANY of these is invalid and will
be rejected, never return one):
- Every title MUST contain the EXACT focus keyword (case-insensitive match).
  No paraphrases, no synonyms, no plurals where the focus is singular,
  no rearranging the words. The full phrase must appear verbatim, ideally
  in the first 60 characters of the title.
- Every meta description MUST contain the EXACT focus keyword (case-
  insensitive match) at least once. Same rule, verbatim phrase, no
  paraphrases or partial matches.
- Both fields MUST work as natural English with the keyword in place. Do
  not bolt the keyword onto a sentence that doesn't grammatically support
  it. Rewrite the surrounding words if needed to integrate cleanly.
- Title character count MUST be between 30 and 60 inclusive (Yoast's
  industry-standard SEO range; Google truncates anything longer than ~60
  on SERPs). Count every character: letters, digits, spaces, punctuation.
  Aim for 50 to 60 as the sweet spot, closer to 60 maximizes use of the
  display window, but never go over. Before returning, count the
  characters and rewrite if outside range.
- Meta description character count MUST be between 130 and 155 inclusive
  (Google's snippet window; under 130 wastes the SERP real estate, over
  155 truncates). Count every character. Aim for 145 ± 5. Before returning,
  count the characters and rewrite if outside range.

Universal constraints:
- Lead the title with the focus keyword where it reads naturally. No ALL
  CAPS, no lying, no generic clickbait.
- Meta description reinforces the focus keyword, states the value the user
  gets, and ends with an implicit or explicit CTA.
- Lean on power words for CTR but never sacrifice clarity. At most one or
  two per title and one per meta. Titles must read as real human-written
  headlines, not a clickbait stack.
- When additional keyphrases are provided, weave each one into AT MOST ONE
  of the rewrites where it fits naturally. Never force them, and never
  repeat the same additional keyphrase across multiple rewrites. Additional
  keyphrases come AFTER the focus keyword, they never replace it.
- Differentiate from the competitor titles when given, do not just rephrase.
- Return STRICTLY valid JSON with the schema below. No prose, no markdown.
SYS;
        $system .= "\n\n" . self::HUMAN_VOICE_RULES;

        $count = self::REWRITES_PER_RUN;

        // Two prompt modes:
        //   - AUTO  → N different angles from the full registry, model picks
        //   - INTENT → N distinct VARIATIONS of the same single intent
        if ($intent === self::INTENT_AUTO) {
            $intentList = implode(', ', array_keys(self::INTENTS));
            // Heredoc identifier MUST NOT collide with any token at the
            // start of an interior line. Earlier we used `MODE` and the
            // body contained `MODE:` — PHP closed the heredoc early and
            // parsed the rest as code. `EBQ_PROMPT_MODE_BLOCK` is unique.
            $modeBlock = <<<EBQ_PROMPT_MODE_BLOCK
MODE: auto-mix.
Pick {$count} DIFFERENT angles from this registry. Every rewrite uses a
different one (no two rewrites share an angle):
  {$intentList}
Set the "angle" field on each rewrite to the chosen angle key from the
list above. The {$count} angles you pick MUST be visibly different in
framing, not paraphrases of each other.
EBQ_PROMPT_MODE_BLOCK;
        } else {
            $intentMeta = self::INTENTS[$intent];
            $intentLabel = $intentMeta['label'];
            $intentDesc = $intentMeta['desc'];
            $intentRule = $intentMeta['rule'];
            $modeBlock = <<<EBQ_PROMPT_MODE_BLOCK
MODE: single-intent variations.
Selected intent: "{$intent}" ({$intentLabel}).
What this angle is: {$intentDesc}
STRUCTURAL RULE for every rewrite (non-negotiable): {$intentRule}

Produce {$count} distinct VARIATIONS of this same intent. Each variation
should differ from the others in at least two of: opening word, length
mix (shorter title vs longer meta vs vice versa), specificity (concrete
numbers / named examples), and the rationale focus. All {$count} MUST set
"angle": "{$intent}".
EBQ_PROMPT_MODE_BLOCK;
        }

        $user = <<<USER
Focus keyword (MUST appear verbatim in every title AND every meta, case-
insensitive match, no paraphrases, no partial matches, no synonyms):
  "{$keyword}"

Additional keyphrases (weave AT MOST ONE into ONE of the three rewrites
where it fits, never forced, never across multiple rewrites, never as a
replacement for the focus keyword): {$additionalBlock}

Power words you may draw on (zero, one, or two per title; one per meta;
never stuffed): {$powerWordsBlock}

Current SEO title: "{$currentTitle}"
Current meta description: "{$currentMeta}"

Top-ranking competitor titles for this query:
{$competitorBlock}

Content excerpt (use only for intent grounding, do not echo verbatim):
---
{$excerpt}
---

{$modeBlock}

CHARACTER LENGTH (HARD LIMIT, verify before returning):
- title: between 30 and 60 characters inclusive (sweet spot 50 to 60)
- meta:  between 130 and 155 characters inclusive (sweet spot 145 ± 5)
Count BEFORE returning. If a draft is outside the range, rewrite it
until it fits, never round up/down by one char and submit anyway.

Return JSON exactly in this shape (EXACTLY {$count} entries, no fewer):
{
  "rewrites": [
    {
      "angle": "<angle_key_from_registry>",
      "title": "...",   // 30 to 60 chars, verbatim focus keyword
      "meta": "...",    // 130 to 155 chars, verbatim focus keyword
      "rationale": "Why this rewrite works against the SERP, in one sentence."
    },
    ... (exactly {$count} entries)
  ]
}
USER;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Validate every rewrite against keyword + length rules. Returns
     * [valid_rewrites, invalid_rewrites_with_reasons]. The invalid list is
     * fed back to the model on retry so it can fix specific drifts (e.g.
     * "title was 67 chars — over by 7"). No truncation, no padding —
     * we never serve incomplete sentences or artificially-padded copy.
     *
     * @param  array<int, mixed>  $rawRewrites
     * @return array{
     *   0: list<array{title:string, meta:string, rationale:string, angle:string}>,
     *   1: list<array{title:string, meta:string, reasons:list<string>}>
     * }
     */
    private function validateRewrites(array $rawRewrites, string $focusKeyword, string $defaultAngle): array
    {
        $valid = [];
        $invalid = [];
        foreach ($rawRewrites as $r) {
            if (! is_array($r)) continue;
            // Humanize before validating: stripping em-dashes can shift
            // length by a few chars, so we measure the humanized version
            // (which is what the user will actually see).
            $title = self::humanizePunctuation(trim((string) ($r['title'] ?? '')));
            $meta = self::humanizePunctuation(trim((string) ($r['meta'] ?? '')));
            if ($title === '' || $meta === '') continue;

            $reasons = [];
            $titleLen = mb_strlen($title);
            $metaLen = mb_strlen($meta);

            if (! $this->containsKeyword($title, $focusKeyword)) {
                $reasons[] = "title is missing the focus keyword \"{$focusKeyword}\"";
            }
            if (! $this->containsKeyword($meta, $focusKeyword)) {
                $reasons[] = "meta is missing the focus keyword \"{$focusKeyword}\"";
            }
            if ($titleLen < 30) {
                $reasons[] = "title is {$titleLen} chars (under by " . (30 - $titleLen) . "); expand to 30 to 60 chars";
            } elseif ($titleLen > 60) {
                $reasons[] = "title is {$titleLen} chars (over by " . ($titleLen - 60) . "); shorten to 30 to 60 chars without ending mid-thought";
            }
            if ($metaLen < 130) {
                $reasons[] = "meta is {$metaLen} chars (under by " . (130 - $metaLen) . "); expand to 130 to 155 chars with a CTA or extra detail";
            } elseif ($metaLen > 155) {
                $reasons[] = "meta is {$metaLen} chars (over by " . ($metaLen - 155) . "); rewrite tighter to 130 to 155 chars while keeping a complete sentence and CTA";
            }

            if ($reasons === []) {
                $valid[] = [
                    'title' => $title,
                    'meta' => $meta,
                    'rationale' => mb_substr(self::humanizePunctuation(trim((string) ($r['rationale'] ?? ''))), 0, 220),
                    'angle' => mb_substr(trim((string) ($r['angle'] ?? $defaultAngle)), 0, 32),
                ];
            } else {
                $invalid[] = [
                    'title' => $title,
                    'meta' => $meta,
                    'reasons' => $reasons,
                ];
            }
        }
        return [$valid, $invalid];
    }

    /**
     * Build a follow-up "fix these specific issues" message and re-call
     * the LLM. Reuses the same conversation history (system + original
     * user prompt) so the model retains all the SEO context we already
     * paid to send, then sees a concrete punch list of what to fix.
     *
     * @param  list<array{role:string, content:string}>  $messages
     * @param  list<array{title:string, meta:string, reasons:list<string>}>  $invalid
     * @return array<string, mixed>|null
     */
    private function retryWithFeedback(array $messages, array $invalid, string $focusKeyword): ?array
    {
        if ($invalid === []) {
            return null;
        }
        $issuesBlock = '';
        foreach ($invalid as $i => $bad) {
            $issuesBlock .= sprintf(
                "\nRewrite %d (drop or fix):\n  title (%d chars): \"%s\"\n  meta  (%d chars): \"%s\"\n  problems:\n    - %s\n",
                $i + 1,
                mb_strlen($bad['title']),
                str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $bad['title']),
                mb_strlen($bad['meta']),
                str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $bad['meta']),
                implode("\n    - ", $bad['reasons'])
            );
        }
        $count = self::REWRITES_PER_RUN;
        $feedback = <<<FEEDBACK
Your previous response had rewrites that violated the hard rules. Fix them
and return EXACTLY {$count} valid rewrites in the same JSON shape.

Hard rules — every returned rewrite MUST satisfy ALL:
- title and meta both contain the EXACT focus keyword "{$focusKeyword}" verbatim
- title is 30–60 characters inclusive
- meta is 130–155 characters inclusive
- title and meta are complete, natural-sounding sentences — never truncated mid-thought

Specific drifts to correct:
{$issuesBlock}

If a rewrite is too long, REWRITE it tighter — do not just chop the end.
If a rewrite is too short, REWRITE it with more value-adding content — do
not just append filler. Both fields must read as polished, human-written
copy in the final response.

Return JSON in the exact shape from the previous instruction.
FEEDBACK;

        $messagesWithFeedback = array_merge($messages, [
            ['role' => 'user', 'content' => $feedback],
        ]);

        $payload = $this->llm->completeJson($messagesWithFeedback, [
            'model' => \App\Support\AiModelConfig::premiumModel(),
            'temperature' => 0.4,         // tighter for the corrective pass
            'max_tokens' => 3200,
            'json_object' => true,
            'timeout' => 60,
        ]);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Strip the punctuation that flags "this was AI-written" most loudly:
     * em-dashes (—) and en-dashes (–) get replaced with safer ASCII
     * equivalents, smart quotes get straightened. The model is asked not
     * to use these in the prompt, but enforcement here means they never
     * leak through to the user even when the model drifts. Applied to
     * every accepted title/meta/rationale before returning.
     *
     * Replacement strategy:
     *   - " — " (em-dash with surrounding spaces, parenthetical aside) → ", "
     *   - "—" with no spaces (compound style) → ", "
     *   - en-dash variants likewise (used as ranges or asides)
     *   - curly quotes → straight ASCII
     */
    public static function humanizePunctuation(string $s): string
    {
        // Em-dash and en-dash with surrounding spaces — most common AI
        // pattern, behaves as a parenthetical comma in a human-written
        // line.
        $s = (string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $s);
        // Stripped pattern collapsed double-commas / leading-comma fragments.
        $s = (string) preg_replace('/,\s*,/u', ',', $s);
        $s = (string) preg_replace('/^,\s*/u', '', $s);
        // Curly quotes to straight.
        $s = (string) preg_replace('/[\x{2018}-\x{201B}]/u', "'", $s);
        $s = (string) preg_replace('/[\x{201C}-\x{201F}]/u', '"', $s);
        // NBSP to space.
        $s = (string) preg_replace('/\x{00A0}/u', ' ', $s);
        // Collapse whitespace runs introduced by the substitutions.
        $s = (string) preg_replace('/\s{2,}/u', ' ', $s);
        return trim($s);
    }

    /**
     * Case-insensitive, whitespace-tolerant check for whether `$haystack`
     * contains the exact `$needle` keyphrase. Both sides are lowered and
     * whitespace runs collapsed so "Focus  Keyword" matches "focus keyword"
     * but synonyms and partial matches still fail. Used to validate AI
     * rewrites against the user's focus keyword.
     */
    private function containsKeyword(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        // Normalise common Unicode lookalikes the model substitutes:
        //   curly quotes → straight, en/em dashes → hyphen, NBSP → space.
        // Also lowercases, collapses whitespace runs, and strips outer
        // punctuation so trailing "?" / "." / quotes don't break the match.
        $normalize = static function (string $s): string {
            // NBSP → space.
            $s = (string) preg_replace('/\x{00A0}/u', ' ', $s);
            // Curly single quotes (U+2018, U+2019, U+201A, U+201B) → ASCII '.
            $s = (string) preg_replace('/[\x{2018}-\x{201B}]/u', "'", $s);
            // Curly double quotes (U+201C..U+201F) → ASCII ".
            $s = (string) preg_replace('/[\x{201C}-\x{201F}]/u', '"', $s);
            // En-dash / em-dash / minus → ASCII -.
            $s = (string) preg_replace('/[\x{2013}\x{2014}\x{2212}]/u', '-', $s);
            $s = mb_strtolower($s);
            $s = (string) preg_replace('/\s+/u', ' ', $s);
            return trim($s);
        };
        $h = $normalize($haystack);
        $n = $normalize($needle);
        return $n !== '' && mb_strpos($h, $n) !== false;
    }

    /**
     * @param  list<string>  $competitorTitles
     * @param  list<string>  $additionalKeywords
     */
    private function cacheKey(int $postId, string $keyword, string $title, string $meta, string $excerpt, array $competitorTitles, string $intent, array $additionalKeywords = []): string
    {
        $contentHash = hash('xxh3', $title . "\n" . $meta . "\n" . $excerpt);
        $compHash = hash('xxh3', implode('|', $competitorTitles));
        // Sort so the same set in different order returns the same cached result.
        $sortedAdditional = $additionalKeywords;
        sort($sortedAdditional, SORT_STRING);
        $additionalHash = hash('xxh3', implode('|', $sortedAdditional));
        return sprintf(
            'ai_snippet_rewrite:%s:%d:%s:%s:%s:%s:%s',
            self::PROMPT_VERSION,
            $postId,
            hash('xxh3', $keyword),
            $contentHash,
            $compHash,
            $additionalHash,
            $intent
        );
    }
}
