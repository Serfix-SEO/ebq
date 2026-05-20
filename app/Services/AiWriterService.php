<?php

namespace App\Services;

use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Llm\LlmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI writer — generates section-level proposals for a post.
 *
 * Inputs (any subset is fine; the service degrades gracefully when one is
 * missing):
 *   - existing post HTML / excerpt
 *   - the post's content brief (subtopics, must-have entities, outline)
 *   - the topical-coverage gaps (subtopics competitors cover that this
 *     post doesn't)
 *
 * Output: a list of section proposals, each tagged as `add`, `edit`, or
 * `replace`, with current and proposed HTML so the editor can render a
 * diff and the user can approve / reject per section. The frontend then
 * applies the approved sections by writing the merged HTML back via WP
 * core's REST POST /wp/v2/posts/{id} endpoint.
 *
 * Cached 24h by (website × postId × focusKeyword × content-hash) so
 * re-clicks during the same drafting session are free.
 */
class AiWriterService
{
    private const CACHE_TTL_SEC = 60 * 60 * 24;

    /**
     * 20 sections × ~400 words avg ≈ 10–12k output tokens; the 16k
     * `max_tokens` budget below leaves headroom for JSON delimiters and
     * metadata. The model is told to aim for substantive sections but
     * skip ones that would just pad — quality over hitting the cap.
     */
    private const MAX_SECTIONS = 20;

    /** Per-section HTML cap (chars). 6000 ≈ 900 words of prose. */
    private const SECTION_HTML_CAP = 6000;

    public function __construct(
        private readonly LlmClient $llm,
    ) {}

    /**
     * @param  array{
     *   focus_keyword: string,
     *   current_html?: string|null,
     *   brief?: array<string, mixed>|null,
     *   gaps?: array<string, mixed>|null,
     * }  $input
     * @return array<string, mixed>
     */
    public function draft(Website $website, int $postId, array $input): array
    {
        if (! $this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'llm_not_configured'];
        }

        $keyword = trim((string) ($input['focus_keyword'] ?? ''));
        if ($keyword === '' || mb_strlen($keyword) < 2) {
            return ['ok' => false, 'error' => 'missing_focus_keyword'];
        }

        $currentHtml = (string) ($input['current_html'] ?? '');
        $currentText = trim(strip_tags($currentHtml));
        $brief = is_array($input['brief'] ?? null) ? $input['brief'] : null;
        $gaps = is_array($input['gaps'] ?? null) ? $input['gaps'] : null;
        $excludeUrl = trim((string) ($input['exclude_url'] ?? ''));
        $selected = is_array($input['selected'] ?? null) ? $input['selected'] : null;
        $title = trim((string) ($input['title'] ?? ''));
        $additionalKeywords = array_values(array_filter(
            array_map('trim', (array) ($input['additional_keywords'] ?? [])),
            static fn ($k) => is_string($k) && $k !== ''
        ));

        // Standalone-draft mode: user supplied a Title but no existing
        // content. Treat the title as the brief's suggested H1 if the
        // brief didn't return one (or override if user explicitly chose
        // their title via the H1 picker — that lands here as
        // `selected.h1`, which applySelection already handles).
        if ($title !== '' && is_array($brief) && empty($brief['suggested_h1'] ?? null)) {
            $brief['suggested_h1'] = $title;
        }

        // When the user has curated a selection in the plan step, filter
        // the brief / gaps lists down to those items so the prompt isn't
        // told to cover topics the user explicitly skipped.
        if ($selected !== null) {
            [$brief, $gaps] = $this->applySelection($brief, $gaps, $selected);
        }
        $wpPages = is_array($input['wp_pages'] ?? null) ? $input['wp_pages'] : [];

        // User-curated link picks from the writer's strategy step.
        // When the user ticked suggestions or typed manual links, we
        // honor that exactly — no GSC fallback for internal in that
        // case, because the user already curated. External links are
        // always user-driven (no GSC equivalent), so the array maps
        // 1:1 to the external_links prompt block when present.
        $rawSelected = is_array($input['selected_links'] ?? null) ? $input['selected_links'] : [];
        $selectedInternal = $this->normalizeSelectedLinks($rawSelected['internal'] ?? []);
        $selectedExternal = $this->normalizeSelectedLinks($rawSelected['external'] ?? []);

        // Topic-aware internal-link candidate pool. GSC click data is the
        // moat — pages with proven user demand always beat "exists but
        // unranked" candidates. The wp_pages fallback lets newly-published
        // content surface before it accumulates GSC impressions. Only
        // computed when the user did NOT curate internal links manually.
        $smartLinks = $selectedInternal === []
            ? $this->resolveSmartInternalLinks($website, $excludeUrl, $keyword, $brief, $gaps, $wpPages)
            : $this->smartLinksFromSelection($selectedInternal);

        $hasBrief = $brief !== null && ! empty($brief['subtopics'] ?? []);
        $hasGaps = $gaps !== null && ! empty($gaps['missing'] ?? []);
        $hasContent = mb_strlen($currentText) >= 100;
        $availableInternalLinks = count($smartLinks);
        $availablePaaCount = is_array($brief['people_also_ask'] ?? null)
            ? count(array_filter($brief['people_also_ask'], 'is_string'))
            : 0;
        $availableGapsCount = is_array($gaps['missing'] ?? null)
            ? count(array_filter($gaps['missing'], 'is_array'))
            : 0;
        // Look at the raw HTML (block markers and all) for an <h1> — the
        // strip_tags'd $currentText loses tag info. Editor themes render the
        // post title as H1, so most published posts won't have one inside the
        // content; the writer should add one when missing.
        $hasH1 = (bool) preg_match('/<h1\b/i', (string) ($input['current_html'] ?? ''));

        // No hard guard on inputs — the writer can produce a useful first
        // draft from just the focus keyword (and any user-curated
        // selection from the plan step). Brief / gaps / existing content
        // make output richer and more grounded, but they are NOT required.
        // The prompt's all-empty case (see below) handles graceful
        // degradation.

        // Cache version — bump when the prompt or output shape changes so
        // existing cached results don't pin users to a stale generation.
        // v12: expanded allowed-tag palette (tables, blockquote, dl/dt/dd,
        //      pre/code) with explicit "use only when topic warrants"
        //      guidance.
        // v13: schema_suggestions added — primary type derived from
        //      brief.suggested_schema_type, content-derived FAQPage
        //      when Q&A pairs exist, plus auto-emitted informational
        //      entries (Organization, WebSite, WebPage, BreadcrumbList).
        // v14: strict-mode count rule rewritten to use the UNION of
        //      outline + subtopics + paa + gap as input list, so
        //      outline-origin picks aren't silently excluded from N.
        // v15: additional_keywords + title supplied by the standalone
        //      draft form land in the prompt; cache key includes them.
        // v16: strict mode now forbids <h1> in any section (per-section
        //      generation flow was producing duplicate H1s).
        // v17: user-curated internal + external link selection wired
        //      into the prompt; cache key includes their hashes so a
        //      changed picks list invalidates stale cached drafts.
        $selectionHash = $selected !== null
            ? substr(hash('sha256', json_encode($selected, JSON_UNESCAPED_UNICODE) ?: ''), 0, 12)
            : '0';
        $extraHash = substr(hash('sha256', mb_strtolower($title).'|'.implode('|', $additionalKeywords)), 0, 8);
        $linksHash = substr(hash('sha256', json_encode([
            'i' => $selectedInternal,
            'e' => $selectedExternal,
        ], JSON_UNESCAPED_UNICODE) ?: ''), 0, 12);
        $cacheKey = sprintf(
            'ai_writer_v17:%d:%d:%s:%s:%s:%s:%d:%d:%s:%s:%s',
            $website->id,
            $postId,
            hash('xxh3', mb_strtolower($keyword)),
            $hasBrief ? '1' : '0',
            $hasGaps ? '1' : '0',
            substr(hash('sha256', $currentText), 0, 12),
            count($smartLinks),
            count($wpPages),
            $selectionHash,
            $extraHash,
            $linksHash,
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['ok'] ?? false) === true) {
            $cached['cached'] = true;

            return $cached;
        }

        $messages = $this->buildPrompt($keyword, $currentText, $brief, $gaps, $hasH1, $smartLinks, $selected !== null, $title, $additionalKeywords, $selectedExternal);
        // 16k output tokens supports the 20-section cap with room for JSON
        // overhead. Mistral Small's 32k context window comfortably fits
        // input + this output. 240s timeout matches the worst-case wall
        // time for large JSON-mode generations.
        $response = $this->llm->completeJson($messages, [
            'temperature' => 0.5,
            'max_tokens' => 16000,
            'json_object' => true,
            'timeout' => 240,
        ]);

        if (! is_array($response) || ! isset($response['sections']) || ! is_array($response['sections'])) {
            Log::warning('AiWriterService: malformed LLM response', [
                'website_id' => $website->id,
                'post_id' => $postId,
                'keyword' => $keyword,
                'response_type' => gettype($response),
                'response_keys' => is_array($response) ? array_keys($response) : null,
            ]);

            return [
                'ok' => false,
                'error' => 'llm_parse_failed',
                'message' => is_array($response)
                    ? 'The model returned JSON but without a "sections" array — try regenerating; if it persists, the focus keyword may be too vague.'
                    : 'The model response could not be parsed as JSON. Re-try once; if it persists, try a more specific focus keyword or shorter source content.',
            ];
        }

        $sections = $this->normalizeSections($response['sections']);

        // Belt-and-suspenders for strict mode: drop any "replace" sections
        // the model snuck in despite the prompt. They collapse the entire
        // generation into one block, which is exactly the bug strict mode
        // exists to prevent.
        if ($selected !== null) {
            $beforeCount = count($sections);
            $sections = array_values(array_filter(
                $sections,
                static fn (array $s) => ($s['kind'] ?? '') !== 'replace',
            ));
            $dropped = $beforeCount - count($sections);
            if ($dropped > 0) {
                Log::warning('AiWriterService: stripped replace sections in strict mode', [
                    'website_id' => $website->id,
                    'post_id' => $postId,
                    'dropped' => $dropped,
                ]);
            }

            // Strip <h1>...</h1> blocks from per-section output. The H1 is
            // the user's Title and is rendered separately; emitting it
            // inside every generated section was producing duplicate H1s
            // in the editor canvas. Demote any leading <h1> to <h2>
            // (so the section still has a heading) and drop subsequent
            // ones outright.
            $sections = array_map(static function (array $s): array {
                $html = (string) ($s['proposed_html'] ?? '');
                if ($html === '' || stripos($html, '<h1') === false) {
                    return $s;
                }
                // Demote the FIRST <h1>...</h1> to <h2>; remove any others.
                $promoted = false;
                $html = preg_replace_callback(
                    '/<h1\b[^>]*>(.*?)<\/h1>/is',
                    static function (array $m) use (&$promoted): string {
                        if (! $promoted) {
                            $promoted = true;
                            return '<h2>'.$m[1].'</h2>';
                        }
                        return '';
                    },
                    $html,
                ) ?? $html;
                $s['proposed_html'] = $html;
                return $s;
            }, $sections);

            // Diagnostics: did the model actually produce N sections?
            // Compute the expected N (union of outline + subtopics + paa
            // + gap missing) and warn if the add-section count doesn't
            // match. The frontend already shows the inputs, so a
            // mismatch is recoverable by Regenerate; we just log so the
            // root cause is visible.
            $unionSeen = [];
            $countItems = static function (array $list) use (&$unionSeen): void {
                foreach ($list as $v) {
                    if (! is_string($v)) {
                        continue;
                    }
                    $k = mb_strtolower(trim($v));
                    if ($k !== '') {
                        $unionSeen[$k] = true;
                    }
                }
            };
            $countItems((array) ($brief['suggested_outline'] ?? []));
            $countItems((array) ($brief['subtopics'] ?? []));
            $countItems((array) ($brief['people_also_ask'] ?? []));
            foreach ((array) ($gaps['missing'] ?? []) as $m) {
                if (is_array($m) && is_string($m['topic'] ?? null)) {
                    $k = mb_strtolower(trim($m['topic']));
                    if ($k !== '') {
                        $unionSeen[$k] = true;
                    }
                }
            }
            $expectedN = count($unionSeen);
            $addCount = count(array_filter($sections, static fn (array $s) => ($s['kind'] ?? '') === 'add'));
            if ($expectedN > 0 && $addCount !== $expectedN) {
                Log::info('AiWriterService: strict-mode section count mismatch', [
                    'website_id' => $website->id,
                    'post_id' => $postId,
                    'expected_N' => $expectedN,
                    'got_add_sections' => $addCount,
                ]);
            }
        }

        if ($sections === []) {
            return ['ok' => false, 'error' => 'no_sections'];
        }

        // How many of the available signals actually made it into the
        // generated output. Lets the UI explain "no internal links?" with
        // "the brief had 0 link targets to use" vs. "the model ignored
        // them" — we can tell because we know the input count and we can
        // count <a href> tags in the output.
        $linkCount = 0;
        foreach ($sections as $section) {
            $linkCount += preg_match_all('/<a\s+href=/i', (string) ($section['proposed_html'] ?? ''));
        }

        // Auto-build schema suggestions from the generated sections — the
        // user picks which to apply and the plugin writes them into
        // _ebq_schemas. Multiple types stack: Article is always offered
        // (default page schema), FAQPage joins when Q&A patterns are
        // detected, etc.
        $schemaSuggestions = $this->buildSchemaSuggestions($sections, $brief, $excludeUrl);

        // Strip em-dashes / en-dashes / "--" before returning; the
        // prompt forbids them but models occasionally still emit one,
        // and an em-dash is the single most reliable "AI tell" in
        // long-form prose.
        $sections = array_map(function (array $s): array {
            foreach (['title', 'rationale', 'proposed_html', 'current_html'] as $k) {
                if (isset($s[$k]) && is_string($s[$k])) {
                    $s[$k] = self::stripDashes($s[$k]);
                }
            }
            return $s;
        }, $sections);

        $result = [
            'ok' => true,
            'summary' => self::stripDashes(mb_substr((string) ($response['summary'] ?? ''), 0, 600)),
            'sections' => $sections,
            'sources_used' => [
                'brief' => $hasBrief,
                'gaps' => $hasGaps,
                'content' => $hasContent,
            ],
            'diagnostics' => [
                'internal_links_available' => $availableInternalLinks,
                'internal_links_in_output' => $linkCount,
                'paa_questions_available' => $availablePaaCount,
                'gaps_available' => $availableGapsCount,
                'sections_returned' => count($sections),
            ],
            'schema_suggestions' => $schemaSuggestions,
            'cached' => false,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL_SEC);

        return $result;
    }

    /**
     * @param  list<array{url: string, anchor: string, topic: string, clicks: int}>  $smartLinks
     * @param  list<string>  $additionalKeywords
     * @param  list<array{anchor:string,url:string,manual:bool}>  $selectedExternal
     * @return list<array{role: string, content: string}>
     */
    private function buildPrompt(string $keyword, string $currentText, ?array $brief, ?array $gaps, bool $hasH1, array $smartLinks, bool $strictSelection, string $title, array $additionalKeywords, array $selectedExternal = []): array
    {
        $briefBlock = '(none)';
        if (is_array($brief) && ! empty($brief)) {
            $subtopics = array_values(array_filter((array) ($brief['subtopics'] ?? []), 'is_string'));
            $entities = array_values(array_filter((array) ($brief['must_have_entities'] ?? []), 'is_string'));
            $outline = array_values(array_filter((array) ($brief['suggested_outline'] ?? []), 'is_string'));
            $paa = array_values(array_filter((array) ($brief['people_also_ask'] ?? []), 'is_string'));
            $serpTitles = array_slice(array_values(array_filter((array) ($brief['serp_titles'] ?? []), 'is_string')), 0, 10);
            $briefBlock = json_encode([
                'angle' => (string) ($brief['angle'] ?? ''),
                'recommended_word_count' => (int) ($brief['recommended_word_count'] ?? 0),
                'suggested_schema_type' => (string) ($brief['suggested_schema_type'] ?? ''),
                'subtopics' => $subtopics,
                'must_have_entities' => $entities,
                'suggested_outline' => $outline,
                'people_also_ask' => $paa,
                'top_serp_titles' => $serpTitles,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Topic-tagged internal links: each entry tells the model which
        // section topic this URL is the strongest fit for, derived from
        // the GSC query that drove the most impressions to that page.
        // When the user manually curated internal links on the strategy
        // step, those override the GSC-derived smartLinks via the
        // resolveSmartInternalLinks() bypass above.
        $smartLinksBlock = empty($smartLinks)
            ? '(none)'
            : json_encode(array_map(static fn (array $l) => [
                'url' => $l['url'],
                'anchor' => $l['anchor'],
                'best_fit_topic' => $l['topic'],
            ], $smartLinks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // External links the user explicitly added on the strategy step.
        // No GSC fallback exists for external — if the array is empty
        // the prompt simply doesn't ask the writer for outbound links.
        $externalLinksBlock = empty($selectedExternal)
            ? '(none)'
            : json_encode(array_map(static fn (array $l) => [
                'url' => $l['url'],
                'anchor' => $l['anchor'],
            ], $selectedExternal), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $gapsBlock = '(none)';
        if (is_array($gaps) && ! empty($gaps)) {
            $missing = array_slice(array_filter((array) ($gaps['missing'] ?? []), 'is_array'), 0, 8);
            $missingNorm = array_map(static fn (array $m) => [
                'topic' => (string) ($m['topic'] ?? ''),
                'rationale' => (string) ($m['rationale'] ?? ''),
            ], $missing);
            $gapsBlock = json_encode($missingNorm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $currentBlock = $currentText !== ''
            ? mb_substr($currentText, 0, 24000)
            : '(empty post)';

        $system = <<<'SYS'
You are a senior SEO editor proposing publish-ready content changes the
user can review and approve one section at a time. Your job is to use
EVERY signal you're given — the brief's outline, must-have entities,
"people also ask" questions, top-SERP titles, internal-link targets,
and the topical-gap analysis — and turn them into reviewable sections.

Output rules (STRICT — non-compliance breaks the consumer):
- Return ONE JSON object only. No prose, no markdown fences, no commentary.
- Top-level keys: "summary" (string) and "sections" (array).
- Section count: %SECTION_COUNT_RULE%
- 200–500 words per section is the sweet spot; up to 800 when the topic
  genuinely warrants depth. Don't pad. Don't undersell.
- "people also ask" handling — REQUIRED: for EACH question in the
  brief's `people_also_ask` array, produce one section whose <h2> is the
  question itself (or a near-paraphrase). The first <p> after that <h2>
  must be a direct, snippet-ready answer (40–60 words, no preamble).
  Tag these with source_tags ⊇ ["brief"] (since PAA comes from the
  brief). Do NOT skip PAA questions — every one gets a section.
- For every topical-gap "missing" topic, produce a section that closes
  it. Tag with source_tags ⊇ ["gaps"].
- For every must-have entity, weave it naturally into at least one
  section (no entity stuffing — work it into prose where it fits).
- INTERNAL LINKS — REQUIRED. The user provides a topic-tagged list of
  internal_links. Each entry has:
    • url             — the page to link to
    • anchor          — suggested anchor text (paraphrase if it reads
                        more naturally; do not invent a different URL)
    • best_fit_topic  — the topic that page is the strongest fit for,
                        derived from the GSC query that drives the
                        most clicks to it
  For EVERY entry you MUST include exactly ONE <a href="<url>"><anchor
  or paraphrase></a> in the response. Place each link in the section
  whose subject most overlaps with the entry's best_fit_topic.
  %LINK_FALLBACK_RULE%
  Do not link to the same URL twice. Do not skip any entry.

  Concrete example: if internal_links contains
    [{"url":"https://example.com/protein-guide",
      "anchor":"vegan protein",
      "best_fit_topic":"vegan protein sources"}]
  then your section about protein sources must include something like
    <p>For an in-depth comparison see our
    <a href="https://example.com/protein-guide">vegan protein</a>
    guide.</p>
  Failing to include any internal_link is a failure of the task.
- proposed_html: valid HTML using a focused tag palette designed to
  support content density without inviting visual cruft. Allowed tags:
    Structural:    <h1>, <h2>, <h3>
    Prose:         <p>, <strong>, <em>, <a>
    Lists:         <ul>, <ol>, <li>
    Definitions:   <dl>, <dt>, <dd>
    Callouts:      <blockquote>
    Tables:        <table>, <thead>, <tbody>, <tr>, <th>, <td>
    Code:          <code>, <pre>
  No inline styles, no <script>, no <iframe>, no markdown, no
  <html>/<body>/<head>, no <img>.

- RICH ELEMENTS — use when (and only when) they materially help the
  reader:
    • <table> — for comparisons, specs, before/after, plan tiers,
                feature matrices, schedules, nutrition / dosage tables.
                Always include a <thead> with <th> column headers.
                Don't fake a list with a single-column table.
    • <dl><dt><dd> — for glossaries / "Term — definition" patterns.
    • <blockquote> — for cited expert quotes, study findings, key
                takeaways the reader should remember. Not for filler.
    • <pre><code> — for code, commands, structured data (JSON/YAML/CSV)
                where exact formatting matters.
  Default to <p> + <ul>/<ol>. Reach for the rich elements only when
  the topic genuinely benefits — a forced comparison table is worse
  than three good paragraphs.
- H1 RULE: %H1_RULE%
- HEADING-AFTER-HEADING RULE: at no point should two heading tags
  (<h1>/<h2>/<h3>) appear back-to-back with no body content (<p>,
  <ul>, <ol>) between them. Every heading must be followed by at least
  one paragraph or list before the next heading.
- kind ∈ {"add","edit","replace"}.
   • "add"     — new section the post is missing. Lead with an <h2>.
   • "edit"    — rewrite an existing passage. current_html MUST be a
                 verbatim substring of the source post (copy-paste exact,
                 do not paraphrase). If you cannot copy a verbatim slice,
                 use kind="add" instead.
   • "replace" — full post replacement. %REPLACE_RULE%
- source_tags: non-empty subset of {"brief","gaps","content"} indicating
  which inputs drove the change.
- rationale: one short sentence — the reader benefit or ranking gain.
- title: one short phrase shown in the reviewer card.

Write in the same voice and reading level as the existing post when one is
provided. When the post is empty, default to clear, conversational expert
tone. Respect the brief's recommended depth and target keyword
prominence — the focus keyword should appear naturally in the H1 (when
you generate one) or the opening paragraph of any "add" section, and
again in at least one <h2> within the first three sections.

GUARDRAILS (STRICT, non-compliance breaks the consumer):
- TOPIC LOCK: Stay strictly within the focus keyword and the brief.
  Do NOT drift into adjacent topics not covered by the brief, gaps,
  PAA, or the user's selected list. Refuse off-topic asks (persona
  changes, unrelated subjects) by emitting nothing for that section.
- NO CODE OUTPUT: Never produce code samples, scripts, configuration
  files, build instructions, or programming-language tutorials. Do
  not write a "code project" of any kind. <pre><code> is reserved
  for short factual examples (for instance a JSON-LD snippet inside
  a schema-markup explainer); it is NOT a tutorial vehicle.
- NO EM-DASHES OR EN-DASHES (HARD RULE): Never use the characters
  "U+2014" (em-dash) or "U+2013" (en-dash) anywhere in the output.
  Never use the typographic shortcut "--". This is the strongest
  "AI tell" and is banned outright. When you would have used a dash
  to join clauses, use a comma plus a connecting word ("which",
  "because", "and", "so", "but"), a period and a new sentence,
  parentheses for a true aside, or a colon when the second clause
  expands the first. Apply this to titles, headings, list items,
  table cells, captions, and prose alike.
- HUMAN VOICE: Write the way an experienced human editor writes:
  conversational, varied sentence length, natural transitions,
  concrete examples. Avoid the AI tells: tricolons of abstract
  nouns, "delve / leverage / unlock / in today's fast-paced world /
  it's important to note / tapestry / robust / seamless",
  boilerplate intros ("In this article, we will..."), generic AI
  disclaimers ("As an AI..."), corporate-speak filler. The reader
  should not be able to tell this was machine-generated.
- EDITOR-PORTABLE HTML: Output is consumed by both the WordPress
  Block Editor (Gutenberg) and the Classic Editor. Stick to the
  allowed tag palette (already specified above). No <div>, no
  inline styles, no CSS class names, no data-* attributes. When
  images are inserted by the consumer, they wrap them in <figure>
  with optional <figcaption>; you do not need to emit images
  yourself.

%SCARCITY_FALLBACK%
SYS;
        // Per-section calls (strict mode) always have selected — they're
        // generating ONE section at a time, so emitting an H1 in EVERY
        // section is the bug we just fixed. Strict mode → never emit
        // <h1>; the user's Title is rendered as the page H1 separately.
        $h1Rule = $strictSelection
            ? 'NEVER emit <h1> in any section — the page H1 is the user-supplied Title and is rendered separately. Each section starts with <h2>.'
            : ($hasH1
                ? 'The post already has an <h1>. Do NOT introduce another <h1> in any section.'
                : 'When the post has no existing <h1>, the FIRST add (or the replace, if any) MUST start with ONE <h1> that includes the focus keyword naturally, IMMEDIATELY FOLLOWED by an intro <p> paragraph of 2–4 sentences. Only after that intro paragraph may any <h2> appear. Never place an <h2> directly after an <h1> with no intervening body content. Subsequent sections must NOT include another <h1>.');

        $sectionCountRule = $strictSelection
            ? "STRICT-SELECTION MODE — the user curated their inputs in a prior step. Follow these rules exactly:\n  (1) Build the INPUT LIST as the case-insensitive UNION of all items in: `suggested_outline` + `subtopics` + `people_also_ask` + the gap analysis's `missing` array. Deduplicate so the same string never appears twice. Call its size N.\n      (Do NOT count `must_have_entities`, `top_serp_titles`, or internal_links — those are CONTEXT for prose, not section drivers.)\n  (2) Generate EXACTLY N sections of kind=\"add\", one per item in the input list, in this order: outline first, then subtopics, then people_also_ask, then gap topics. Each section's <h2> uses or closely paraphrases its source item.\n  (3) NEVER use kind=\"replace\" in strict mode, EVEN WHEN THE POST IS EMPTY. Always emit N add sections instead.\n  (4) Do NOT invent new topics, do NOT pad with extra sections, do NOT split an input into multiple sections, do NOT merge two inputs into one section. One input → one section.\n  (5) Optionally append `edit` sections for weak passages of the existing post (improvements). These do NOT count toward N and are extra, not substitutes.\n  (6) Verify before emitting: the number of `add` sections in your output must equal N exactly. If you produce N-1 or N+1, the response is invalid."
            : 'BETWEEN 12 AND 20 sections. Coverage is the point — produce one section per brief subtopic, one per topical gap, and one per "people also ask" question. Combining is allowed only when two inputs cover the same ground; otherwise each gets its own section. Returning fewer than 12 sections when richer inputs are available is a failure of the task.';

        $linkFallbackRule = $strictSelection
            ? 'If no section is a clean fit, place the link in the closest-related section anyway — DO NOT invent a new section to host the link in strict mode.'
            : 'If no section is a clean fit, create one (an "add" section about that topic) so the link has a home — that\'s preferable to dropping the entry.';

        $replaceRule = $strictSelection
            ? 'FORBIDDEN in strict-selection mode — never emit kind="replace" here. If the post is empty, still produce N "add" sections per the rule above.'
            : 'Use ONLY when the post is empty or fundamentally unsalvageable. Maximum one per response.';

        $scarcityFallback = $strictSelection
            ? '' // no fallback paragraph in strict mode — STRICT-SELECTION rule already covers it
            : "INPUT-SCARCITY FALLBACK: when CONTENT BRIEF, TOPICAL GAPS, and CURRENT\nPOST CONTENT are ALL marked \"(none)\" / \"(empty post)\", you still produce\na useful first draft using only the target keyword and any user-curated\nitems in the user message. In that case, return ONE \"replace\" section\nthat scaffolds a complete article: H1 (if not yet on page) → 2–4\nsentence intro paragraph → 6–10 H2 sections covering the keyword's\ntypical search intent (problem → core explanation → how-to / comparison\n→ FAQs → next steps), each followed by 2–4 paragraphs of substantive\nprose. Do NOT refuse — the absence of brief / gaps means richer output\nis impossible, not that no output is.";

        $system = str_replace(
            ['%H1_FLAG%', '%H1_RULE%', '%SECTION_COUNT_RULE%', '%LINK_FALLBACK_RULE%', '%REPLACE_RULE%', '%SCARCITY_FALLBACK%'],
            [$hasH1 ? 'true' : 'false', $h1Rule, $sectionCountRule, $linkFallbackRule, $replaceRule, $scarcityFallback],
            $system,
        );

        $titleBlock = $title !== '' ? "\"{$title}\"" : '(none — derive a title from the brief or keyword)';
        $additionalBlock = empty($additionalKeywords)
            ? '(none)'
            : '"' . implode('", "', array_slice($additionalKeywords, 0, 20)) . '"';

        $user = <<<USER
Target keyword: "{$keyword}"

User-supplied page title:
{$titleBlock}

Additional keyphrases (weave naturally into the prose, do NOT keyword-stuff;
each should appear in at least one section where it fits):
{$additionalBlock}

CONTENT BRIEF (may be empty):
{$briefBlock}

INTERNAL LINKS — topic-tagged candidates pulled from this site's own GSC
footprint, ordered by topical fit (may be empty):
{$smartLinksBlock}

EXTERNAL LINKS — user-curated outbound links the article must cite (may
be empty). For EVERY entry you MUST include exactly ONE <a href="<url>"
target="_blank" rel="noopener"><anchor or close paraphrase></a> in the
response, placed in a section where the cited resource is relevant. If
no section is a natural fit, weave it into the most adjacent paragraph
as supporting evidence. Do not invent new URLs; only use the URLs in
this list. Do not link to the same external URL twice.
{$externalLinksBlock}

TOPICAL GAPS vs. top SERP (may be empty):
{$gapsBlock}

CURRENT POST CONTENT (plain text extract, may be empty):
{$currentBlock}

Return JSON exactly in this shape:
{
  "summary": "One short paragraph: what the proposed changes do as a whole.",
  "sections": [
    {
      "title": "Section title shown in the reviewer UI",
      "kind": "add" | "edit" | "replace",
      "anchor": "for kind=edit only — short heading or first words of the targeted block; null otherwise",
      "current_html": "for kind=edit only — verbatim substring of the post being replaced; null otherwise",
      "proposed_html": "<h2>...</h2><p>...</p>",
      "rationale": "One sentence on why this change matters.",
      "source_tags": ["brief"]
    }
  ]
}
USER;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Apply the user's curated selection (from the plan step) to the
     * brief / gaps blobs. Anything the user didn't tick is removed, so
     * the writer prompt only mentions what the user wants.
     *
     *   selected = {
     *     h1?: string,
     *     h2_outline?: string[],
     *     subtopics?: string[],
     *     paa?: string[],
     *     gap_topics?: string[],
     *     competitor_subtopics?: string[],
     *   }
     *
     * If a selection key is absent OR an empty array, that signal stays
     * intact (treated as "user didn't curate this list"). If a selection
     * key is a non-empty array, the corresponding brief/gaps list is
     * filtered to that exact set.
     *
     * @return array{0: ?array<string,mixed>, 1: ?array<string,mixed>}
     */
    private function applySelection(?array $brief, ?array $gaps, array $selected): array
    {
        $brief = is_array($brief) ? $brief : null;
        $gaps = is_array($gaps) ? $gaps : null;

        $filterList = static function (?array $list, $picked): ?array {
            if (! is_array($list)) {
                return $list;
            }
            if (! is_array($picked) || empty($picked)) {
                return $list;
            }
            $set = [];
            foreach ($picked as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $set[mb_strtolower(trim($p))] = true;
                }
            }
            if (empty($set)) {
                return $list;
            }
            $filtered = [];
            foreach ($list as $item) {
                if (! is_string($item)) {
                    continue;
                }
                if (isset($set[mb_strtolower(trim($item))])) {
                    $filtered[] = $item;
                }
            }
            return $filtered;
        };

        if ($brief !== null) {
            // Suggested H1 — when the user picked one (or wrote a custom),
            // overwrite the brief's value so the prompt's H1 rule lands
            // on what they chose.
            if (isset($selected['h1']) && is_string($selected['h1']) && trim($selected['h1']) !== '') {
                $brief['suggested_h1'] = trim($selected['h1']);
            }
            $brief['suggested_outline'] = $filterList($brief['suggested_outline'] ?? [], $selected['h2_outline'] ?? null);
            $brief['subtopics']         = $filterList($brief['subtopics'] ?? [], $selected['subtopics'] ?? null);
            $brief['people_also_ask']   = $filterList($brief['people_also_ask'] ?? [], $selected['paa'] ?? null);
        }

        if ($gaps !== null) {
            // Gap "missing" entries are objects {topic, rationale}; filter
            // by topic match. Competitor subtopics are plain strings.
            if (is_array($selected['gap_topics'] ?? null) && ! empty($selected['gap_topics'])) {
                $picks = [];
                foreach ((array) $selected['gap_topics'] as $p) {
                    if (is_string($p) && trim($p) !== '') {
                        $picks[mb_strtolower(trim($p))] = true;
                    }
                }
                if (! empty($picks) && is_array($gaps['missing'] ?? null)) {
                    $gaps['missing'] = array_values(array_filter(
                        $gaps['missing'],
                        static fn ($m) => is_array($m)
                            && is_string($m['topic'] ?? null)
                            && isset($picks[mb_strtolower(trim($m['topic']))]),
                    ));
                }
            }
            $gaps['competitor_subtopics'] = $filterList(
                $gaps['competitor_subtopics'] ?? [],
                $selected['competitor_subtopics'] ?? null,
            );
        }

        return [$brief, $gaps];
    }

    /**
     * Build the full schema stack appropriate for this page. Three tiers:
     *
     *  1. Primary content schema — chosen from the brief's
     *     suggested_schema_type. Article / Product / Recipe / Event /
     *     LocalBusiness / Course / Review / Service / Person etc.
     *
     *  2. Content-derived schemas — added when the generated sections
     *     show signals for them: FAQPage when Q&A pairs exist, Review
     *     when a review/comparison frame is detected, etc. These are
     *     stand-alone schemas the user opts into.
     *
     *  3. Auto-emitted informational entries — Organization, WebSite,
     *     WebPage, BreadcrumbList. These are emitted on every page by
     *     EBQ_Schema_Output already, so the user can't toggle them, but
     *     showing them in the UI completes the schema-graph picture
     *     ("an article page typically has Article + BreadcrumbList +
     *     Organization") — gives the user confidence the full graph is
     *     in place without making them configure each piece.
     *
     * Each entry is shaped to drop straight into `_ebq_schemas` post
     * meta on apply (template + data + enabled).
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function buildSchemaSuggestions(array $sections, ?array $brief, string $url): array
    {
        $headline = $this->extractHeadline($brief, $sections);
        $primaryType = $this->normalizeSchemaType((string) ($brief['suggested_schema_type'] ?? 'Article'));

        $out = [];

        // ── 1. Primary schema (user-configurable) ─────────────────────
        $primary = $this->buildPrimarySchema($primaryType, $headline, $url);
        if ($primary !== null) {
            $out[] = $primary;
        }

        // ── 2. Content-derived schemas ────────────────────────────────
        $faqPairs = $this->extractFaqPairs($sections);
        if (count($faqPairs) >= 2) {
            $out[] = [
                'template' => 'faq',
                'type' => 'FAQPage',
                'label' => 'FAQ Page',
                'auto_emitted' => false,
                'rationale' => sprintf(
                    '%d Q&A pair%s detected — sections starting with a "?" headline followed by a direct answer.',
                    count($faqPairs),
                    count($faqPairs) === 1 ? '' : 's',
                ),
                'data' => ['questions' => $faqPairs],
                'jsonld' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(static fn (array $p) => [
                        '@type' => 'Question',
                        'name' => $p['question'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $p['answer'],
                        ],
                    ], $faqPairs),
                ],
            ];
        }

        // ── 3. Auto-emitted informational entries ────────────────────
        // These are emitted by EBQ_Schema_Output on every page. Showing
        // them here lets the user see the full schema graph their page
        // ends up with — Article isn't alone; it's surrounded by
        // BreadcrumbList + Organization etc.
        $out[] = [
            'template' => '_organization',
            'type' => 'Organization',
            'label' => 'Organization (publisher)',
            'auto_emitted' => true,
            'rationale' => 'Site identity. Emitted automatically by EBQ on every page — no action needed.',
            'data' => [],
            'jsonld' => null,
        ];
        $out[] = [
            'template' => '_website',
            'type' => 'WebSite',
            'label' => 'WebSite',
            'auto_emitted' => true,
            'rationale' => 'Site root + sitelinks search box. Emitted automatically.',
            'data' => [],
            'jsonld' => null,
        ];
        $out[] = [
            'template' => '_webpage',
            'type' => 'WebPage',
            'label' => 'WebPage',
            'auto_emitted' => true,
            'rationale' => 'Page node. Emitted automatically and bound to the post.',
            'data' => [],
            'jsonld' => null,
        ];
        $out[] = [
            'template' => '_breadcrumb',
            'type' => 'BreadcrumbList',
            'label' => 'BreadcrumbList',
            'auto_emitted' => true,
            'rationale' => 'Home → ancestors → current. Emitted automatically on singular and term-archive pages.',
            'data' => [],
            'jsonld' => null,
        ];

        return $out;
    }

    /**
     * @return string  Lowercase template id matching EBQ_Schema_Templates::all().
     */
    private function normalizeSchemaType(string $type): string
    {
        $map = [
            'Article'         => 'article',
            'BlogPosting'     => 'article',
            'NewsArticle'     => 'article',
            'Product'         => 'product',
            'Event'           => 'event',
            'Recipe'          => 'recipe',
            'LocalBusiness'   => 'local_business',
            'Restaurant'      => 'local_business',
            'Course'          => 'course',
            'Review'          => 'review',
            'Service'         => 'service',
            'Book'            => 'book',
            'JobPosting'      => 'job_posting',
            'VideoObject'     => 'video',
            'SoftwareApplication' => 'software',
            'Person'          => 'person',
        ];
        return $map[$type] ?? 'article';
    }

    /**
     * Build the primary schema entry for the page based on the brief's
     * suggested type. Falls back to Article when the type isn't one we
     * have a template for. Specific types (Product, LocalBusiness,
     * Recipe etc.) include reasonable empty-state stubs the user fills
     * in via the schema editor — we don't fabricate prices, hours, or
     * ratings the AI can't verify.
     *
     * @return array<string, mixed>|null
     */
    private function buildPrimarySchema(string $template, string $headline, string $url): ?array
    {
        $base = static fn (string $tpl, string $type, string $label, string $rationale, array $data, array $jsonld): array => [
            'template' => $tpl,
            'type' => $type,
            'label' => $label,
            'auto_emitted' => false,
            'rationale' => $rationale,
            'data' => array_filter($data, static fn ($v) => $v !== '' && $v !== null),
            'jsonld' => array_filter($jsonld, static fn ($v) => $v !== null && $v !== ''),
        ];

        switch ($template) {
            case 'article':
                return $base(
                    'article', 'Article', 'Article',
                    'Default content schema for blog posts and articles. Surfaces the headline + url to search engines.',
                    ['headline' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $headline, 'url' => $url],
                );
            case 'product':
                return $base(
                    'product', 'Product', 'Product',
                    'Page describes a product. Add price, brand, SKU, and reviews in the schema editor for rich-result eligibility.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $headline, 'url' => $url],
                );
            case 'recipe':
                return $base(
                    'recipe', 'Recipe', 'Recipe',
                    'Cooking content. Fill prep/cook time, ingredients and instructions in the schema editor for recipe rich-results.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => $headline, 'url' => $url],
                );
            case 'event':
                return $base(
                    'event', 'Event', 'Event',
                    'Event page — start/end dates, location, and ticketing fill in the schema editor.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Event', 'name' => $headline, 'url' => $url],
                );
            case 'local_business':
                return $base(
                    'local_business', 'LocalBusiness', 'Local Business',
                    'Storefront or service-area business. Add address, opening hours, telephone, and aggregate rating in the schema editor.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $headline, 'url' => $url],
                );
            case 'course':
                return $base(
                    'course', 'Course', 'Course',
                    'Educational content. Add provider + description for course rich-results.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Course', 'name' => $headline, 'url' => $url],
                );
            case 'review':
                return $base(
                    'review', 'Review', 'Review',
                    'The page reviews a product / book / movie. Add itemReviewed + rating in the schema editor.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Review', 'name' => $headline, 'url' => $url],
                );
            case 'service':
                return $base(
                    'service', 'Service', 'Service',
                    'Page describes a service. Add provider + serviceType for service rich-results.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $headline, 'url' => $url],
                );
            case 'book':
                return $base(
                    'book', 'Book', 'Book',
                    'The page is about a book. Add author + ISBN for book rich-results.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Book', 'name' => $headline, 'url' => $url],
                );
            case 'job_posting':
                return $base(
                    'job_posting', 'JobPosting', 'Job Posting',
                    'Open position. Add hiringOrganization + jobLocation in the schema editor.',
                    ['title' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => $headline, 'url' => $url],
                );
            case 'video':
                return $base(
                    'video', 'VideoObject', 'Video',
                    'Page features a video. Add contentUrl + duration in the schema editor.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $headline, 'url' => $url],
                );
            case 'software':
                return $base(
                    'software', 'SoftwareApplication', 'Software',
                    'Software / app page. Add operatingSystem + applicationCategory.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $headline, 'url' => $url],
                );
            case 'person':
                return $base(
                    'person', 'Person', 'Person',
                    'Page is about a person (bio / profile). Add jobTitle + sameAs for entity disambiguation.',
                    ['name' => $headline],
                    ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => $headline, 'url' => $url],
                );
            default:
                // Unknown types fall back to article for safety.
                return $this->buildPrimarySchema('article', $headline, $url);
        }
    }

    /**
     * Pull the page headline from the brief's suggested H1 if present;
     * otherwise scan the generated sections for an <h1>.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function extractHeadline(?array $brief, array $sections): string
    {
        if (is_string($brief['suggested_h1'] ?? null) && trim($brief['suggested_h1']) !== '') {
            return trim((string) $brief['suggested_h1']);
        }
        foreach ($sections as $s) {
            $html = (string) ($s['proposed_html'] ?? '');
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
                return trim(strip_tags($m[1]));
            }
        }
        return '';
    }

    /**
     * Extract Q&A pairs from generated sections. A section qualifies when
     * its first <h2> ends with "?" and is followed by a non-trivial <p>.
     * Rich-element sections (table-led, code-led) are skipped.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array{question: string, answer: string}>
     */
    private function extractFaqPairs(array $sections): array
    {
        $pairs = [];
        foreach ($sections as $s) {
            if (($s['kind'] ?? '') !== 'add') {
                continue;
            }
            $html = (string) ($s['proposed_html'] ?? '');
            if (! preg_match('/<h2[^>]*>(.*?)<\/h2>\s*(?:<h3[^>]*>.*?<\/h3>\s*)*<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
                continue;
            }
            $question = trim(strip_tags($m[1]));
            $answer = trim(strip_tags($m[2]));
            if (! str_ends_with($question, '?')) {
                continue;
            }
            if (mb_strlen($question) < 5 || mb_strlen($answer) < 20) {
                continue;
            }
            $pairs[] = ['question' => $question, 'answer' => $answer];
            if (count($pairs) >= 12) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Build a topic-tagged internal-link candidate pool.
     *
     * Two sources, layered:
     *   1) GSC click data — the EBQ moat. Pages with proven user demand
     *      always rank highest. Score = clicks × (1 + topic_overlap).
     *   2) WordPress pages provided by the plugin (wp_pages) — every
     *      published post/page on the site, including newly-published
     *      ones that have zero GSC traction yet. Score uses a tiny
     *      synthetic signal so these only surface when topic overlap is
     *      strong AND no GSC candidate covers the same topic better.
     *
     * Dedup by URL: if a URL appears in both, the GSC entry wins (preserves
     * its click signal and best-matching anchor from real search queries).
     *
     * Returns up to 12 candidates as
     *   [{url, anchor, topic, source, clicks}, ...]
     * sorted by score desc.
     *
     * Excludes the current post's URL so we don't link the page to itself.
     *
     * Sanitize a user-supplied list of {anchor, url} dicts into the
     * canonical shape used by the writer prompt. Drops entries with a
     * missing/empty anchor or url; trims whitespace; caps each list at
     * a sensible max so a malformed payload can't blow out the prompt.
     *
     * @param  mixed  $list
     * @return list<array{anchor:string, url:string, manual:bool}>
     */
    private function normalizeSelectedLinks(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $entry) {
            if (! is_array($entry)) continue;
            $anchor = trim((string) ($entry['anchor'] ?? ''));
            $url    = trim((string) ($entry['url'] ?? ''));
            if ($anchor === '' || $url === '') continue;
            // Bare existence check; full URL validity was already
            // enforced by the controller's `url` rule on PATCH.
            if (! preg_match('#^https?://#i', $url)) continue;
            $out[] = [
                'anchor' => mb_substr($anchor, 0, 200),
                'url'    => mb_substr($url, 0, 2048),
                'manual' => (bool) ($entry['manual'] ?? false),
            ];
            if (count($out) >= 20) break; // sanity cap
        }
        return $out;
    }

    /**
     * Convert a user-curated internal-link selection into the smart-link
     * shape consumed by the prompt builder. We don't know which topic
     * each link best fits when the user picked it, so we use the
     * anchor itself as the topic label — the writer's section-placement
     * logic falls back to the closest-related-section rule.
     *
     * @param  list<array{anchor:string, url:string, manual:bool}>  $selected
     * @return list<array{url: string, anchor: string, topic: string, source: string, clicks: int}>
     */
    private function smartLinksFromSelection(array $selected): array
    {
        return array_map(static fn (array $l) => [
            'url'    => $l['url'],
            'anchor' => $l['anchor'],
            'topic'  => $l['anchor'],
            'source' => $l['manual'] ? 'user_manual' : 'user_selected',
            'clicks' => 0,
        ], $selected);
    }

    /**
     * @param  list<array{url: string, title?: string}>  $wpPages
     * @return list<array{url: string, anchor: string, topic: string, source: string, clicks: int}>
     */
    private function resolveSmartInternalLinks(
        Website $website,
        string $excludeUrl,
        string $focusKeyword,
        ?array $brief,
        ?array $gaps,
        array $wpPages = [],
    ): array {
        // Build a list of (topic_label, tokens) pairs. Each candidate URL
        // is then matched against whichever topic shares the most tokens
        // with its top GSC query.
        $topics = [];

        $addTopic = function (string $label) use (&$topics) {
            $label = trim($label);
            if ($label === '') {
                return;
            }
            $tokens = $this->significantTokens($label);
            if ($tokens === []) {
                return;
            }
            $topics[] = ['label' => $label, 'tokens' => $tokens];
        };

        $addTopic($focusKeyword);
        if (is_array($brief)) {
            foreach (array_slice((array) ($brief['subtopics'] ?? []), 0, 20) as $t) {
                if (is_string($t)) {
                    $addTopic($t);
                }
            }
            foreach (array_slice((array) ($brief['people_also_ask'] ?? []), 0, 20) as $t) {
                if (is_string($t)) {
                    $addTopic($t);
                }
            }
            foreach (array_slice((array) ($brief['must_have_entities'] ?? []), 0, 20) as $t) {
                if (is_string($t)) {
                    $addTopic($t);
                }
            }
        }
        if (is_array($gaps)) {
            foreach (array_slice((array) ($gaps['missing'] ?? []), 0, 20) as $row) {
                if (is_array($row) && is_string($row['topic'] ?? null)) {
                    $addTopic((string) $row['topic']);
                }
            }
        }

        if ($topics === []) {
            return [];
        }

        // Union of all tokens across topics — the WHERE clause that pulls
        // candidate rows. We score per-topic in PHP afterwards, so over-
        // pulling here is fine.
        $allTokens = [];
        foreach ($topics as $t) {
            foreach ($t['tokens'] as $tok) {
                $allTokens[$tok] = true;
            }
        }
        $allTokens = array_keys($allTokens);

        try {
            $tz = config('app.timezone');
            $end = Carbon::yesterday($tz)->endOfDay();
            $start = $end->copy()->subDays(89)->startOfDay();

            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->where('page', '!=', '')
                ->where('query', '!=', '')
                ->when($excludeUrl !== '', fn ($q) => $q->where('page', '!=', $excludeUrl))
                ->where(function ($w) use ($allTokens) {
                    foreach ($allTokens as $tok) {
                        $w->orWhere('query', 'LIKE', '%'.$tok.'%');
                    }
                })
                ->selectRaw('page, query, SUM(clicks) AS clicks, SUM(impressions) AS impressions')
                ->groupBy('page', 'query')
                ->orderByDesc('clicks')
                ->limit(200)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('AiWriterService: smart internal link lookup failed', [
                'website_id' => $website->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        // Score each (page, query) row against every topic; whichever
        // topic shares the most tokens with the query becomes that row's
        // best_fit_topic. Then keep the highest-scoring row per page.
        $best = [];
        foreach ($rows as $r) {
            $page = (string) $r->page;
            $query = (string) $r->query;
            $clicks = (int) $r->clicks;
            $impressions = (int) $r->impressions;
            $signal = $clicks > 0 ? $clicks : max(1, (int) round($impressions / 10));

            $queryLower = mb_strtolower($query);
            $bestTopic = null;
            $bestOverlap = 0;
            foreach ($topics as $t) {
                $overlap = 0;
                foreach ($t['tokens'] as $tok) {
                    if ($tok !== '' && str_contains($queryLower, $tok)) {
                        $overlap++;
                    }
                }
                if ($overlap > $bestOverlap) {
                    $bestOverlap = $overlap;
                    $bestTopic = $t['label'];
                }
            }
            if ($bestTopic === null) {
                continue;
            }

            $score = $signal * (1 + $bestOverlap);

            if (! isset($best[$page]) || $best[$page]['score'] < $score) {
                $best[$page] = [
                    'url' => $page,
                    'anchor' => $query,
                    'topic' => $bestTopic,
                    'source' => 'gsc',
                    'clicks' => $clicks,
                    'score' => $score,
                ];
            }
        }

        // WordPress fallback layer. Each plugin-supplied page is matched
        // against topics by its TITLE, with a low synthetic signal so it
        // only beats a GSC entry when overlap is dramatically stronger.
        // GSC dedup wins: if a URL is already keyed in $best, skip it.
        // ALL_TOKENS_MATCH bonus rewards titles whose tokens are entirely
        // contained in a topic, which captures cases like a post titled
        // "Vegan Protein Sources" matching the topic "vegan protein".
        foreach ($wpPages as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($url === '' || $title === '' || $url === $excludeUrl || isset($best[$url])) {
                continue;
            }
            $titleLower = mb_strtolower($title);
            $bestTopic = null;
            $bestOverlap = 0;
            foreach ($topics as $t) {
                $overlap = 0;
                foreach ($t['tokens'] as $tok) {
                    if ($tok !== '' && str_contains($titleLower, $tok)) {
                        $overlap++;
                    }
                }
                if ($overlap > $bestOverlap) {
                    $bestOverlap = $overlap;
                    $bestTopic = $t['label'];
                }
            }
            if ($bestTopic === null || $bestOverlap === 0) {
                continue;
            }

            // Synthetic signal — well below typical click counts so a
            // GSC-backed candidate with even modest clicks outranks a
            // perfect-overlap WP page. The 2× overlap multiplier keeps
            // strongly-matching new content competitive.
            $score = 1 + (2 * $bestOverlap);

            $best[$url] = [
                'url' => $url,
                'anchor' => $title,
                'topic' => $bestTopic,
                'source' => 'wp',
                'clicks' => 0,
                'score' => $score,
            ];
        }

        uasort($best, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $out = [];
        foreach ($best as $row) {
            unset($row['score']);
            $out[] = $row;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/[^a-z0-9]+/u', $text) ?: [];
        $stop = [
            'the', 'and', 'for', 'with', 'a', 'an', 'of', 'to', 'in', 'on',
            'is', 'are', 'or', 'be', 'do', 'does', 'how', 'what', 'why',
            'when', 'where', 'who', 'which', 'can', 'will', 'should',
            'this', 'that', 'these', 'those', 'has', 'have', 'had',
            'as', 'at', 'by', 'it', 'its',
        ];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 3 && ! in_array($p, $stop, true)) {
                $out[$p] = true;
            }
            if (count($out) >= 6) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeSections(array $raw): array
    {
        $out = [];
        $allowedKinds = ['add', 'edit', 'replace'];
        $allowedTags = ['brief', 'gaps', 'content'];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = strtolower(trim((string) ($item['kind'] ?? '')));
            if (! in_array($kind, $allowedKinds, true)) {
                continue;
            }
            $proposed = trim((string) ($item['proposed_html'] ?? ''));
            if ($proposed === '') {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = $kind === 'add'
                    ? 'New section'
                    : ($kind === 'edit' ? 'Edit existing section' : 'Replace post content');
            }

            $currentHtml = $kind === 'edit' ? trim((string) ($item['current_html'] ?? '')) : '';
            $anchor = $kind === 'edit' ? trim((string) ($item['anchor'] ?? '')) : '';

            $tags = [];
            $rawTags = $item['source_tags'] ?? [];
            if (is_array($rawTags)) {
                foreach ($rawTags as $t) {
                    if (! is_string($t)) {
                        continue;
                    }
                    $t = strtolower(trim($t));
                    if (in_array($t, $allowedTags, true) && ! in_array($t, $tags, true)) {
                        $tags[] = $t;
                    }
                }
            }

            $out[] = [
                'id' => 's_'.substr(hash('xxh3', $kind.'|'.$title.'|'.$proposed), 0, 10),
                'title' => mb_substr($title, 0, 120),
                'kind' => $kind,
                'anchor' => $anchor !== '' ? mb_substr($anchor, 0, 200) : null,
                'current_html' => $currentHtml !== '' ? mb_substr($currentHtml, 0, self::SECTION_HTML_CAP) : null,
                'proposed_html' => mb_substr($proposed, 0, self::SECTION_HTML_CAP),
                'rationale' => mb_substr(trim((string) ($item['rationale'] ?? '')), 0, 400),
                'source_tags' => $tags,
            ];

            if (count($out) >= self::MAX_SECTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Replace em-dashes ("U+2014") and en-dashes ("U+2013") and the
     * typographic shortcut "--" with a comma plus space. Em-dashes
     * are the single most reliable "AI tell" in long-form prose, and
     * the prompt forbids them; this method is the defensive net for
     * the cases where the model emits one anyway.
     *
     * Public + static so other legacy services (AiBlockEditorService,
     * AiSnippetRewriterService) can call into the same helper without
     * duplicating the regex.
     */
    public static function stripDashes(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $s = (string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $s);
        $s = (string) preg_replace('/(\w)\s*[\x{2014}\x{2013}]\s*(\w)/u', '$1, $2', $s);
        $s = (string) preg_replace('/\s+--\s+/', ', ', $s);
        $s = (string) preg_replace('/,\s*,/', ',', $s);
        $s = (string) preg_replace('/([.!?])\s*,\s*/u', '$1 ', $s);
        return $s;
    }
}
