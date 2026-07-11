<?php

namespace App\Services;

use App\Models\Website;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmClientFactory;
use App\Services\AiContentBriefService;
use App\Support\AiModelConfig;
use App\Support\LlmProviderConfig;

/**
 * Block-level AI ops invoked from the Gutenberg block toolbar:
 *   - command:   user supplies an instruction; service generates a block.
 *   - extend:    continue the existing block; the original text is kept at the start.
 *   - summarise: condense the existing block into 2–4 sentences.
 *   - grammar:   correct the existing block without changing meaning.
 *   - rewrite:   recompose the existing block — clearer, tighter, same meaning.
 *
 * Single-shot, no caching, no chained calls — the editor needs sub-15s latency.
 * For full-post drafting use AiWriterService instead.
 */
class AiBlockEditorService
{
    public const MODE_COMMAND = 'command';
    public const MODE_EXTEND = 'extend';
    public const MODE_SUMMARISE = 'summarise';
    public const MODE_GRAMMAR = 'grammar';
    public const MODE_REWRITE = 'rewrite';
    public const MODE_SHORTER = 'shorter';
    public const MODE_LONGER = 'longer';
    public const MODE_TRANSLATE = 'translate';
    public const MODE_TONE = 'tone';
    public const MODE_CONVERT_TO_LIST = 'convert_to_list';
    public const MODE_CONVERT_TO_TABLE = 'convert_to_table';
    public const MODE_TITLE = 'title';
    // Workstream D: SEO-aware + media-block + multi-block modes.
    public const MODE_ALT_TEXT = 'alt_text';
    public const MODE_CTA = 'cta';
    public const MODE_SIMPLIFY = 'simplify';
    public const MODE_BOOST_FLESCH = 'boost_flesch';
    public const MODE_ADD_FOCUS_KEYWORD = 'add_focus_keyword';
    public const MODE_SEO_OPTIMIZE = 'seo_optimize';
    public const MODE_FAQ = 'faq';
    public const MODE_COUNTER_ARGUMENT = 'counter_argument';
    // Workstream F: cross-block ops (run from a multi-block selection).
    public const MODE_SUMMARISE_SECTION = 'summarise_section';
    public const MODE_GENERATE_HEADING = 'generate_heading';
    public const MODE_HARMONISE_TONE = 'harmonise_tone';
    public const MODE_OUTLINE = 'outline';

    public const MODES = [
        self::MODE_COMMAND,
        self::MODE_EXTEND,
        self::MODE_SUMMARISE,
        self::MODE_GRAMMAR,
        self::MODE_REWRITE,
        self::MODE_SHORTER,
        self::MODE_LONGER,
        self::MODE_TRANSLATE,
        self::MODE_TONE,
        self::MODE_CONVERT_TO_LIST,
        self::MODE_CONVERT_TO_TABLE,
        self::MODE_ALT_TEXT,
        self::MODE_CTA,
        self::MODE_SIMPLIFY,
        self::MODE_BOOST_FLESCH,
        self::MODE_ADD_FOCUS_KEYWORD,
        self::MODE_SEO_OPTIMIZE,
        self::MODE_FAQ,
        self::MODE_COUNTER_ARGUMENT,
        self::MODE_SUMMARISE_SECTION,
        self::MODE_GENERATE_HEADING,
        self::MODE_HARMONISE_TONE,
        self::MODE_OUTLINE,
        self::MODE_TITLE,
    ];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiContentBriefService $briefService,
    ) {
    }

    /**
     * @param  array{
     *     mode?: string,
     *     text?: string|null,
     *     command?: string|null,
     *     focus_keyword?: string|null,
     *     additional_keywords?: array<int, string>|null,
     *     title?: string|null,
     *     target_language?: string|null,
     *     tone?: string|null,
     * }  $input
     * @return array{ok: bool, text?: string, error?: string}
     */
    public function generate(Website $website, array $input): array
    {
        if (! $this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'llm_not_configured'];
        }

        $mode = (string) ($input['mode'] ?? '');
        if (! in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'invalid_mode'];
        }

        $text = trim((string) ($input['text'] ?? ''));
        $command = trim((string) ($input['command'] ?? ''));
        $focusKeyword = trim((string) ($input['focus_keyword'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $targetLanguage = trim((string) ($input['target_language'] ?? ''));
        $tone = trim((string) ($input['tone'] ?? ''));
        $additionalKeywords = array_values(array_filter(
            array_map(static fn ($k) => trim((string) $k), (array) ($input['additional_keywords'] ?? [])),
            static fn (string $k): bool => $k !== '',
        ));

        // Vision context. Only meaningful for `alt_text`; ignored for all
        // other modes. Validated at the controller (URL + length caps),
        // so we just trim here. Empty `image_url` keeps the alt-text path
        // on the text-only model — useful when the user has selected an
        // image block whose URL isn't yet resolvable (e.g. just-uploaded).
        $imageUrl = trim((string) ($input['image_url'] ?? ''));
        $surroundingText = trim((string) ($input['surrounding_text'] ?? ''));

        // Selection-aware editing — when the client supplies a selection
        // slice + before/after context, the model edits ONLY the slice.
        // The prompt is wrapped to make that boundary explicit.
        $selectionText = trim((string) ($input['selection_text'] ?? ''));
        $selectionPrefix = (string) ($input['selection_prefix'] ?? '');
        $selectionSuffix = (string) ($input['selection_suffix'] ?? '');
        $isSelectionEdit = $selectionText !== '';

        $needsText = in_array($mode, [
            self::MODE_EXTEND, self::MODE_SUMMARISE, self::MODE_GRAMMAR, self::MODE_REWRITE,
            self::MODE_SHORTER, self::MODE_LONGER, self::MODE_TRANSLATE, self::MODE_TONE,
            self::MODE_CONVERT_TO_LIST, self::MODE_CONVERT_TO_TABLE,
            // Workstream D modes that operate on existing text:
            self::MODE_SIMPLIFY, self::MODE_BOOST_FLESCH, self::MODE_ADD_FOCUS_KEYWORD,
            self::MODE_SEO_OPTIMIZE, self::MODE_FAQ, self::MODE_COUNTER_ARGUMENT,
            // Workstream F multi-block modes (text is the joined block content):
            self::MODE_SUMMARISE_SECTION, self::MODE_GENERATE_HEADING,
            self::MODE_HARMONISE_TONE, self::MODE_OUTLINE,
        ], true);
        // Title mode is a special case: it needs EITHER content text OR
        // the focus keyword to produce useful suggestions.
        if ($mode === self::MODE_TITLE && $text === '' && $focusKeyword === '') {
            return ['ok' => false, 'error' => 'missing_title_inputs'];
        }
        if ($needsText && $text === '') {
            return ['ok' => false, 'error' => 'missing_text'];
        }
        if ($mode === self::MODE_COMMAND && $command === '') {
            return ['ok' => false, 'error' => 'missing_command'];
        }
        if ($mode === self::MODE_TRANSLATE && $targetLanguage === '') {
            return ['ok' => false, 'error' => 'missing_target_language'];
        }
        if ($mode === self::MODE_TONE && $tone === '') {
            return ['ok' => false, 'error' => 'missing_tone'];
        }

        // Cache-only lookup — never triggers a fresh brief run. We only
        // enrich when the page already has one cached; otherwise we degrade
        // gracefully to focus-keyword-only context.
        $brief = $mode !== self::MODE_GRAMMAR && $focusKeyword !== ''
            ? ($this->briefService->cachedBrief($website, $focusKeyword) ?? null)
            : null;
        $briefContext = $this->extractBriefContext($brief);

        [$system, $user] = $this->buildPrompt($mode, $text, $command, $focusKeyword, $title, $additionalKeywords, $briefContext, $targetLanguage, $tone, $surroundingText);

        // Selection-aware wrap: replace the user prompt with one that
        // tells the model to edit only the selected slice and return
        // only its replacement. The original mode-prompt's intent
        // (rewrite/grammar/shorter/etc.) is preserved as the operation
        // descriptor inside the wrapped prompt.
        if ($isSelectionEdit) {
            $operationLabel = $this->selectionOperationLabel($mode);
            $user = "You are editing a slice of a longer paragraph. Edit ONLY the selected text below and return its replacement. The text before and after the selection is read-only context — do NOT echo it.\n\n"
                ."[before selection — read only]: \"{$selectionPrefix}\"\n"
                ."[selection to edit]: \"{$selectionText}\"\n"
                ."[after selection — read only]: \"{$selectionSuffix}\"\n\n"
                ."Operation: {$operationLabel}\n"
                ."Constraints:\n"
                ."- Return ONLY the rewritten selection. No quotes, no labels, no preamble.\n"
                ."- Result must read as natural English when spliced between [before] and [after] — match grammatical seams, capitalisation, and punctuation flow.\n"
                ."- Match the input language and approximate length unless the operation explicitly asks otherwise.\n";
        }

        // Title mode benefits from the premium-tier model — better
        // length-constraint adherence is worth the modest cost overhead.
        // Other modes (rewrite/extend/grammar/etc.) stay on the default
        // tier where speed matters more and constraints are looser.
        $modelOverride = $mode === self::MODE_TITLE ? ['model' => AiModelConfig::premiumModel()] : [];

        // Vision-mode alt text: when an image URL is supplied for the
        // `alt_text` mode, send a multi-modal user message (text part +
        // image_url part) and route to a vision model. Only Mistral has
        // one (Pixtral) — when the active provider doesn't (DeepSeek),
        // pin the call to Mistral so alt text keeps working; its token
        // spend lands in the same pooled LLM cap. No Mistral key →
        // graceful error, never throw.
        $llm = $this->llm;
        $userContent = $user;
        if ($mode === self::MODE_ALT_TEXT && $imageUrl !== '' && ! $isSelectionEdit) {
            $userContent = [
                ['type' => 'text', 'text' => $user],
                ['type' => 'image_url', 'image_url' => $imageUrl],
            ];
            $visionModel = AiModelConfig::visionModel();
            if ($visionModel === null) {
                if (! LlmProviderConfig::isConfigured(LlmProviderConfig::PROVIDER_MISTRAL)) {
                    return ['ok' => false, 'error' => 'vision_not_supported'];
                }
                $llm = LlmClientFactory::make(LlmProviderConfig::PROVIDER_MISTRAL);
                $visionModel = AiModelConfig::visionModel(LlmProviderConfig::PROVIDER_MISTRAL);
            }
            $modelOverride = ['model' => $visionModel];
        }

        $response = $llm->complete([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ], array_merge([
            'temperature' => $this->temperatureFor($mode),
            'max_tokens' => $this->maxTokensFor($mode),
            'timeout' => 180,
        ], $modelOverride));

        if (! ($response['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($response['error'] ?? 'llm_failed')];
        }

        $generated = trim((string) ($response['content'] ?? ''));
        $generated = $this->stripCodeFences($generated);
        if ($generated === '') {
            return ['ok' => false, 'error' => 'llm_empty_response'];
        }
        // Strip the punctuation patterns that flag AI authorship: em/en
        // dashes get replaced with commas (or hyphens), curly quotes get
        // straightened. Even when the prompt's HUMAN_VOICE_RULES warns
        // against em-dashes, models leak them; sanitising guarantees the
        // user never sees them.
        $generated = \App\Services\AiSnippetRewriterService::humanizePunctuation($generated);

        // Title mode: validate keyword + length, retry with concrete
        // feedback when any drift. No truncation — chopping a title
        // mid-thought is worse than re-asking the model to write a
        // proper one.
        if ($mode === self::MODE_TITLE) {
            $kept = $this->filterTitlesValid($generated, $focusKeyword);
            // Bumped target from 5 to 10 — users want a real shortlist.
            $titleTarget = 10;
            if (count($kept) < $titleTarget) {
                $invalidLines = $this->extractInvalidTitleLines($generated, $focusKeyword);
                if ($invalidLines !== []) {
                    $retryFeedback = $this->buildTitleRetryFeedback($invalidLines, $focusKeyword);
                    $retryResp = $this->llm->complete([
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                        ['role' => 'assistant', 'content' => $generated],
                        ['role' => 'user', 'content' => $retryFeedback],
                    ], [
                        'model' => AiModelConfig::premiumModel(),
                        'temperature' => 0.4,
                        'max_tokens' => 1500,
                        'timeout' => 60,
                    ]);
                    if (($retryResp['ok'] ?? false) && trim((string) ($retryResp['content'] ?? '')) !== '') {
                        $retryText = $this->stripCodeFences(trim((string) $retryResp['content']));
                        $retryKept = $this->filterTitlesValid($retryText, $focusKeyword);
                        // Keep first-pass winners first (they covered the
                        // 10-angle spread) then fill with retry winners.
                        $seen = [];
                        $merged = [];
                        foreach (array_merge($kept, $retryKept) as $line) {
                            $k = mb_strtolower($line);
                            if (isset($seen[$k])) continue;
                            $seen[$k] = true;
                            $merged[] = $line;
                            if (count($merged) >= $titleTarget) break;
                        }
                        $kept = $merged;
                    }
                }
            }
            if ($kept === []) {
                return ['ok' => false, 'error' => 'titles_invalid', 'message' => 'The model could not produce in-spec title suggestions. Try a shorter focus keyphrase.'];
            }
            $generated = implode("\n", $kept);
        }

        return ['ok' => true, 'text' => $generated];
    }

    /**
     * Return only the title lines that pass keyword + length validation
     * (focus keyword present, 30–60 chars). Whitespace-trimmed; never
     * truncates or pads — drift gets fed back to the model on retry.
     *
     * @return list<string>
     */
    private function filterTitlesValid(string $generated, string $focusKeyword): array
    {
        $kept = [];
        foreach (preg_split('/\r?\n/', $generated) ?: [] as $line) {
            $clean = trim($line);
            if ($clean === '') continue;
            if ($focusKeyword !== '' && ! $this->lineContainsKeyword($clean, $focusKeyword)) {
                continue;
            }
            $len = mb_strlen($clean);
            if ($len < 30 || $len > 60) continue;
            $kept[] = $clean;
        }
        return $kept;
    }

    /**
     * Extract the lines that DIDN'T pass validation, with a per-line
     * diagnostic reason ready to fold into the retry feedback prompt.
     *
     * @return list<array{line:string, reasons:list<string>}>
     */
    private function extractInvalidTitleLines(string $generated, string $focusKeyword): array
    {
        $invalid = [];
        foreach (preg_split('/\r?\n/', $generated) ?: [] as $line) {
            $clean = trim($line);
            if ($clean === '') continue;
            $reasons = [];
            if ($focusKeyword !== '' && ! $this->lineContainsKeyword($clean, $focusKeyword)) {
                $reasons[] = "missing focus keyword \"{$focusKeyword}\"";
            }
            $len = mb_strlen($clean);
            if ($len < 30) {
                $reasons[] = "too short ({$len} chars; needs 30–60)";
            } elseif ($len > 60) {
                $reasons[] = "too long ({$len} chars; needs 30–60)";
            }
            if ($reasons !== []) {
                $invalid[] = ['line' => $clean, 'reasons' => $reasons];
            }
        }
        return $invalid;
    }

    /**
     * Format a corrective feedback message for the retry call, naming
     * each invalid line and the specific drifts it has so the model
     * fixes them rather than repeating the same mistakes.
     *
     * @param  list<array{line:string, reasons:list<string>}>  $invalid
     */
    private function buildTitleRetryFeedback(array $invalid, string $focusKeyword): string
    {
        $list = '';
        foreach ($invalid as $i => $bad) {
            $list .= sprintf(
                "  %d. \"%s\" — %s\n",
                $i + 1,
                str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $bad['line']),
                implode('; ', $bad['reasons'])
            );
        }
        return <<<FEEDBACK
Some of those titles broke the rules. Re-do them as 10 titles total, each:
- between 30 and 60 characters inclusive (count chars yourself before sending)
- containing the EXACT focus keyword "{$focusKeyword}" verbatim
- complete, polished sentences — never truncated mid-thought, never padded with filler
- visibly different in style across the set — vary opening word, framing (question / list / how-to / comparison / benefit / curiosity / authority / mistake-to-avoid / year-led / straightforward) so the user has a real shortlist to A/B from

Fix these specifically:
{$list}
Return ONLY the 10 titles, one per line, no numbering, no quotes, no preamble.
FEEDBACK;
    }

    /**
     * Case-insensitive verbatim keyword check, tolerant of Unicode quote
     * / dash variants the model often substitutes. Mirrors the
     * AiSnippetRewriterService validator so both AI title surfaces apply
     * the same standard.
     */
    private function lineContainsKeyword(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $normalize = static function (string $s): string {
            $s = (string) preg_replace('/\x{00A0}/u', ' ', $s);
            $s = (string) preg_replace('/[\x{2018}-\x{201B}]/u', "'", $s);
            $s = (string) preg_replace('/[\x{201C}-\x{201F}]/u', '"', $s);
            $s = (string) preg_replace('/[\x{2013}\x{2014}\x{2212}]/u', '-', $s);
            $s = mb_strtolower($s);
            $s = (string) preg_replace('/\s+/u', ' ', $s);
            return trim($s);
        };
        return mb_strpos($normalize($haystack), $normalize($needle)) !== false;
    }

    /**
     * Pull the prompt-relevant subset of a cached brief. We deliberately
     * cap each list short — block-level ops produce 50–500 words, so a
     * dozen entities and four subtopics is enough signal without crowding
     * the context window or pulling the model off task.
     *
     * @param  array<string, mixed>|null  $brief
     * @return array{subtopics: list<string>, entities: list<string>, paa: list<string>, recommended_word_count: int, angle: string}|null
     */
    private function extractBriefContext(?array $brief): ?array
    {
        if (! is_array($brief) || ! isset($brief['brief']) || ! is_array($brief['brief'])) {
            return null;
        }
        $b = $brief['brief'];
        $list = static function ($v, int $cap): array {
            if (! is_array($v)) return [];
            $out = [];
            foreach ($v as $item) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                    if (count($out) >= $cap) break;
                }
            }
            return $out;
        };
        return [
            'subtopics' => $list($b['subtopics'] ?? [], 6),
            'entities' => $list($b['must_have_entities'] ?? [], 8),
            'paa' => $list($b['people_also_ask'] ?? [], 4),
            'recommended_word_count' => (int) ($b['recommended_word_count'] ?? 0),
            'angle' => trim((string) ($b['angle'] ?? '')),
        ];
    }

    /**
     * @param  list<string>  $additionalKeywords
     * @param  array{subtopics: list<string>, entities: list<string>, paa: list<string>, recommended_word_count: int, angle: string}|null  $briefContext
     * @return array{0: string, 1: string} [systemPrompt, userPrompt]
     */
    private function buildPrompt(string $mode, string $text, string $command, string $focusKeyword = '', string $title = '', array $additionalKeywords = [], ?array $briefContext = null, string $targetLanguage = '', string $tone = '', string $surroundingText = ''): array
    {
        // SEO-first system prompt. Every block is part of a page that needs to
        // rank for a target query — the model should think like a search-led
        // content editor, not a generic copywriter. Grammar mode opts out of
        // SEO rewrites because proofing should never change phrasing.
        $system = "You are a senior SEO content editor working inside a WordPress Gutenberg post. The user is creating content meant to rank in Google search and earn organic traffic, every choice you make should serve that goal.\n\n"
            ."When generating or modifying text:\n"
            ."- Match user search intent. If the focus keyword is informational, explain; if commercial, persuade; if navigational, be direct.\n"
            ."- Use the focus keyword and natural semantic variants the way a human writer would, never stuffed, never robotic.\n"
            ."- Add specifics: real numbers, concrete examples, named entities. Generic AI-flavored prose ('in today's fast-paced world') ranks poorly.\n"
            ."- Keep paragraphs scannable (2 to 4 sentences). Prefer concrete nouns and active voice.\n"
            ."- Stay on the page's topic. Do not drift into adjacent themes.\n\n"
            ."OUTPUT FORMAT:\n"
            ."- Default to plain prose. Do NOT wrap plain prose in any HTML tags, the editor adds <p> automatically.\n"
            ."- When the user's instruction clearly implies STRUCTURED content, return clean HTML using the appropriate elements:\n"
            ."  Comparisons: <table> with <thead>/<tbody>/<tr>/<th>/<td>\n"
            ."  Step-by-step / ordered process: <ol><li></li></ol>\n"
            ."  Feature lists / bullet points: <ul><li></li></ul>\n"
            ."  Multi-section content: <h2>/<h3> for section titles, plain prose between\n"
            ."  Inline emphasis: <strong>, <em>, <a href=\"\">\n"
            ."- Never use <html>, <head>, <body>, <div>, <span>, inline style attributes, classes, scripts, or any wrapper containers.\n"
            ."- Never use markdown syntax. Specifically: NO pipe tables (` | col | col | `), NO `-` or `*` bullet lines, NO `#` headings, NO `**bold**`, NO `*italic*`, NO ``` code fences, NO `[text](url)` links. ALWAYS use the equivalent HTML tags: <table>/<tr>/<td>, <ul>/<li>, <ol>/<li>, <h2>, <strong>, <em>, <a href=\"\">.\n"
            ."- Match the input language.\n"
            ."- Return ONLY the result, no preamble, no explanation.\n\n"
            .\App\Services\AiSnippetRewriterService::HUMAN_VOICE_RULES;

        // Build a short SEO context block to prepend to user prompts when we
        // know the page's focus keyword, additional keyphrases, title, or
        // cached audit/brief signals. Skipped on grammar mode (proofreading)
        // because any extra context tempts the model to rephrase.
        $seoContext = '';
        if ($mode !== self::MODE_GRAMMAR) {
            $parts = [];
            if ($title !== '') {
                $parts[] = "Page title: {$title}";
            }
            if ($focusKeyword !== '') {
                $parts[] = "Focus keyword: {$focusKeyword}";
            }
            if (! empty($additionalKeywords)) {
                $parts[] = 'Additional keyphrases (weave naturally where they fit, never forced): '
                    .implode(', ', array_slice($additionalKeywords, 0, 10));
            }
            if ($briefContext !== null) {
                if ($briefContext['angle'] !== '') {
                    $parts[] = "Search intent: {$briefContext['angle']}";
                }
                if ($briefContext['recommended_word_count'] > 0) {
                    $parts[] = "Target full-page word count: {$briefContext['recommended_word_count']} (the block you write is one part of this — write at the right level of depth for that scale)";
                }
                if (! empty($briefContext['subtopics'])) {
                    $parts[] = 'Subtopics top SERP results cover: '.implode(' · ', $briefContext['subtopics']);
                }
                if (! empty($briefContext['entities'])) {
                    $parts[] = 'Entities to mention if relevant: '.implode(', ', $briefContext['entities']);
                }
                if (! empty($briefContext['paa'])) {
                    $parts[] = 'People also ask: '.implode(' | ', $briefContext['paa']);
                }
            }
            if (! empty($parts)) {
                $seoContext = "SEO context for this page (use it to guide vocabulary, search intent, depth, and emphasis — DO NOT mention this context, the keyword list, or the entity list in your output):\n- "
                    .implode("\n- ", $parts)
                    ."\n\n";
            }
        }

        return match ($mode) {
            self::MODE_COMMAND => [
                $system,
                $text !== ''
                    ? $seoContext."Apply the user's instruction to the existing text below. Change tone, structure, length, or style as instructed — but DO NOT invent facts, drop key information, or contradict the source. Preserve any natural mentions of the focus keyword.\n\n"
                        ."If the instruction asks for headings, multiple sections, an outline expanded into content, lists, or tables, follow the OUTPUT FORMAT rules in the system prompt and return clean HTML (`<h2>`/`<h3>` for section titles, `<p>` between, `<ul>`/`<ol>`/`<li>`, `<table>`). Otherwise return plain prose with no wrapper tags. Return ONLY the result.\n\nInstruction:\n{$command}\n\nExisting text:\n{$text}"
                    : $seoContext."Fulfil the user's instruction. Aim it at the search intent for the focus keyword (if provided). Be concrete and useful.\n\n"
                        ."If the instruction implies multiple sections, headings, an outline, lists, or a table, follow the OUTPUT FORMAT rules in the system prompt and return clean HTML (`<h2>`/`<h3>`, `<p>`, `<ul>`/`<ol>`/`<li>`, `<table>`). Otherwise keep it as a single concise prose block with no wrapper tags. Return ONLY the result.\n\nInstruction:\n{$command}",
            ],
            self::MODE_EXTEND => [
                $system,
                $seoContext."Continue the following text with one or two more paragraphs of closely related content that deepens topical coverage. CRITICAL: return ONLY the NEW continuation paragraphs. DO NOT include, repeat, paraphrase, or rewrite any part of the existing text — it will be appended automatically by the editor. Your output should read naturally as the next thing the reader sees after the existing text. Add specific, search-useful detail (numbers, examples, sub-aspects of the topic) and naturally include the focus keyword or a semantic variant where it fits — never forced. Do not start with phrases like 'In addition' or 'Furthermore' that signal you are continuing — write as if these are standalone paragraphs.\n\nExisting text (for context only — do not output this):\n{$text}",
            ],
            self::MODE_SUMMARISE => [
                $system,
                $seoContext."Summarise the following text into a single concise paragraph (2–4 sentences) suitable for a meta description, intro paragraph, or excerpt. Preserve the focus keyword (if it appears in the source), the key claims, and any specific names, numbers, or product terms.\n\nText:\n{$text}",
            ],
            self::MODE_GRAMMAR => [
                $system,
                "Proofread the following text. Fix every spelling mistake, typo, grammar error, punctuation error, capitalization error, and obvious style slip. Be thorough — do not skip subtle errors. DO NOT change the meaning, tone, voice, factual content, paragraph structure, or word choice. DO NOT rephrase sentences that are already correct. Preserve all keyword mentions exactly as written. Return only the fully corrected text.\n\nText:\n{$text}",
            ],
            self::MODE_REWRITE => [
                $system,
                $seoContext."Rewrite the following text into a stronger version: clearer, more engaging, tighter, and better aligned to the search intent for the focus keyword. Keep the same meaning, claims, and approximate length. Improve specificity (replace vague phrases with concrete ones), add natural semantic variants of the focus keyword where helpful, and trim filler. Do not invent facts. Return only the rewritten text.\n\nText:\n{$text}",
            ],
            self::MODE_SHORTER => [
                $system,
                $seoContext."Make the following text 30–50% shorter. Preserve all key facts, the focus keyword, named entities, and specific numbers. Remove filler, redundancy, and adjectives that don't add information. Maintain the same tone. Return only the shortened text.\n\nText:\n{$text}",
            ],
            self::MODE_LONGER => [
                $system,
                $seoContext."Expand the following text by 30–50% with relevant, search-useful detail (specifics, examples, named entities, sub-aspects of the topic). Preserve the existing meaning and tone — do not contradict or replace what's there. Naturally include the focus keyword or a semantic variant where it fits. Do not invent facts. Return only the expanded text.\n\nText:\n{$text}",
            ],
            self::MODE_TRANSLATE => [
                $system,
                "Translate the following text to {$targetLanguage}. Preserve names, brand terms, URLs, code, numbers, and HTML tags exactly as written. Match the natural style and idiom of native {$targetLanguage} speakers — do not produce a literal word-for-word translation. Return only the translated text.\n\nText:\n{$text}",
            ],
            self::MODE_TONE => [
                $system,
                $seoContext."Rewrite the following text in a {$tone} tone. Keep the meaning, claims, approximate length, and the focus keyword. Adjust word choice, sentence rhythm, and openness/formality to fit a {$tone} register. Do not invent facts. Return only the rewritten text.\n\nText:\n{$text}",
            ],
            self::MODE_CONVERT_TO_LIST => [
                $system,
                $seoContext."Restructure the following text into a clear bulleted list using <ul><li>…</li></ul> HTML. Each <li> should be a self-contained point — short enough to scan, long enough to be useful. Preserve all key facts and the focus keyword. Group closely-related items together. If the text is genuinely a sequence of steps, use <ol><li>…</li></ol> instead. Return only the resulting HTML.\n\nText:\n{$text}",
            ],
            self::MODE_CONVERT_TO_TABLE => [
                $system,
                $seoContext."Restructure the following text into a comparison table using <table><thead><tr><th>…</th></tr></thead><tbody><tr><td>…</td></tr></tbody></table> HTML. Pick logical column headers based on what's being compared in the source. If the text doesn't have parallel comparable items, infer the most useful columns (e.g., Feature / Description / Benefit) and decompose the prose into rows. Preserve all key facts. Return only the table HTML — no preamble paragraph.\n\nText:\n{$text}",
            ],
            self::MODE_TITLE => [
                $system,
                $seoContext."Generate exactly 10 SEO-optimized title suggestions for this page. Each title MUST:\n"
                    ."- Be 30–60 characters inclusive (Yoast's industry-standard SEO range; Google truncates anything longer than ~60 on SERPs). Count every character — letters, digits, spaces, punctuation. Aim for 50–60 as the sweet spot. Verify the count BEFORE returning each title and rewrite if outside range\n"
                    ."- Contain the EXACT focus keyword verbatim (case-insensitive match) — no paraphrases, no synonyms, no partial matches. The full phrase must appear, ideally in the first 60 characters\n"
                    ."- Match the search intent indicated by the SEO context\n"
                    ."- Be compelling and click-worthy without resorting to clickbait or vague hype\n"
                    ."- Use sentence case (only first letter capitalized) unless a brand name requires capitals\n"
                    ."- Avoid quotation marks, brackets, or trailing punctuation\n"
                    ."- Lean on at most one or two power words where they make the title sharper "
                        ."(e.g., proven, essential, complete, exclusive, fast, easy, definitive, avoid, guide, ultimate, expert, tested, free) — "
                        ."never more than two, never stuffed, never sacrificing clarity for the boost\n\n"
                    ."The 10 titles MUST be visibly different in style — cover this set of angles:\n"
                    ."  1. Straightforward / informational\n"
                    ."  2. Question form (ends with a question mark)\n"
                    ."  3. Number-led list (e.g., '7 things…')\n"
                    ."  4. Year-led / current ('… in 2026', '… 2026 Guide')\n"
                    ."  5. Benefit-focused (lead with the user outcome)\n"
                    ."  6. How-to / tutorial framing\n"
                    ."  7. Comparison or 'X vs Y' framing\n"
                    ."  8. Authority / expert-tested framing\n"
                    ."  9. Mistake-to-avoid / warning framing\n"
                    ." 10. Curiosity / contrast-driven hook\n\n"
                    ."Return ONLY the 10 titles, one per line. No numbering, no quotes, no commentary, no preamble.\n\n"
                    .($text !== '' ? "Page content excerpt for context:\n{$text}" : "(No page content yet — base titles on the focus keyword and SEO context only.)"),
            ],
            // ─── Workstream D: SEO + media-block + structural modes ───
            self::MODE_ALT_TEXT => [
                $system,
                $seoContext."Generate concise, descriptive alt text for the image attached to this prompt (or, if no image is attached, infer from the editorial context below). Rules:\n"
                    ."- 8 to 125 characters\n"
                    ."- Describe what the image actually shows in plain language a screen reader would speak\n"
                    ."- Lead with the visual subject, not generic framing\n"
                    ."- Do NOT start with \"image of\", \"picture of\", \"photo of\" — assistive tech announces image already\n"
                    ."- Do NOT repeat the caption verbatim if a caption is provided\n"
                    ."- Naturally include the focus keyword or a semantic variant ONLY when the image genuinely depicts it; never force it\n"
                    ."- Tie the description to the editorial context (what the surrounding paragraphs are explaining) so the alt is relevant, not generic\n"
                    ."- No emojis, no quotation marks, no trailing punctuation beyond a single period\n"
                    ."- Match the page language\n\n"
                    ."Return ONLY the alt text on a single line.\n\n"
                    .($text !== '' ? "Caption (for context, do NOT repeat): \"{$text}\"\n" : "(No caption provided.)\n")
                    .($surroundingText !== '' ? "\nSurrounding paragraphs (the prose immediately above/below this image — describes its role on the page):\n{$surroundingText}\n" : ''),
            ],
            self::MODE_CTA => [
                $system,
                $seoContext."Write a single, compelling call-to-action button label. Rules:\n"
                    ."- 2 to 6 words\n"
                    ."- Lead with a strong verb (Get, Try, Start, Read, Download, Book, See, Compare, Join, Save)\n"
                    ."- Match the surrounding page intent (informational vs commercial)\n"
                    ."- May use ONE power word from this set when natural: free, instant, complete, exclusive, proven, easy, fast, ultimate\n"
                    ."- No quotation marks, no trailing punctuation\n"
                    ."- Title Case is fine; ALL CAPS is not\n\n"
                    ."Return ONLY the button label on a single line.\n\n"
                    .($text !== '' ? "Current label (improve on it): \"{$text}\"" : '(No current label — generate from the SEO context above.)'),
            ],
            self::MODE_SIMPLIFY => [
                $system,
                $seoContext."Rewrite the following text to be MUCH easier to read, target a Flesch Reading Ease score of 60 or higher. Specifically:\n"
                    ."- Split sentences over 20 words into two\n"
                    ."- Replace 3+ syllable words with 1 to 2 syllable equivalents where the meaning is preserved\n"
                    ."- Cut redundant clauses and adverb stacks\n"
                    ."- Keep the focus keyword and any proper nouns / numbers exactly as written\n"
                    ."- Match the input language; preserve paragraph breaks\n\n"
                    ."Return ONLY the simplified text.\n\nText:\n{$text}",
            ],
            self::MODE_BOOST_FLESCH => [
                $system,
                $seoContext."Aggressively boost the Flesch Reading Ease score of the text below. Target Flesch >= 70 (\"fairly easy\", grade-7 level). This is a stronger rewrite than Simplify: keep every claim and named entity, but rebuild sentence-by-sentence for maximum clarity.\n"
                    ."Hard rules (must hit all of them):\n"
                    ."- Average sentence length 12 to 16 words. Cap any single sentence at 18 words. Split long sentences.\n"
                    ."- Use one or two syllable words for everything except proper nouns, brand names, and unavoidable technical terms.\n"
                    ."- Active voice. No passive constructions ('is being done by'), no nominalisations ('the implementation of').\n"
                    ."- Drop hedges and filler ('really', 'very', 'quite', 'in order to', 'a number of').\n"
                    ."- Use concrete subjects (people, things, products) rather than abstract nouns.\n"
                    ."- Preserve the focus keyword and all numbers / dates / proper nouns exactly as they appear.\n"
                    ."- Preserve paragraph and list structure if present.\n"
                    ."- NEVER use em-dashes (U+2014) or en-dashes (U+2013) anywhere in the output. Use a comma plus connecting word, a period, parentheses, or a colon instead.\n\n"
                    ."Return ONLY the rewritten text.\n\nText:\n{$text}",
            ],
            self::MODE_ADD_FOCUS_KEYWORD => [
                $system,
                $seoContext."Edit the following text so the EXACT focus keyword appears at least once verbatim, integrated naturally with grammar-clean phrasing. Rules:\n"
                    ."- Do NOT add the keyword if the text is unrelated to it — return the original unchanged in that case (you cannot lie)\n"
                    ."- Do NOT echo the keyword more than necessary; one natural mention is the goal\n"
                    ."- Preserve every other fact, claim, and proper noun\n"
                    ."- Match the input language and approximate length\n\n"
                    ."Return ONLY the edited text.\n\nText:\n{$text}",
            ],
            self::MODE_SEO_OPTIMIZE => [
                $system,
                $seoContext."Lightly SEO-tune the following text. Rules:\n"
                    ."- Mention the focus keyword once if missing; weave a natural semantic variant somewhere if it fits\n"
                    ."- Vary the opening word from common heading-starters (avoid 'The' / 'A' / 'Discover' / 'Welcome')\n"
                    ."- Aim for keyword density around 1 to 1.5 percent (do not stuff)\n"
                    ."- Tighten filler; preserve meaning, claims, and any proper nouns\n"
                    ."- Match input language and approximate length\n\n"
                    ."Return ONLY the SEO-tuned text.\n\nText:\n{$text}",
            ],
            self::MODE_FAQ => [
                $system,
                $seoContext."Convert the following paragraph into a Frequently Asked Questions section. Rules:\n"
                    ."- Generate 3 to 6 question + answer pairs that cover the topic of the source paragraph\n"
                    ."- Each question is a real query a user would type into Google, ending with '?'\n"
                    ."- Each answer is 1 to 3 sentences, factually grounded in the source\n"
                    ."- Mention the focus keyword in at least one question or answer when natural\n"
                    ."- Output as HTML using <h3> for questions and <p> for answers — pairs separated only by the next <h3>\n\n"
                    ."Return ONLY the HTML, no preamble.\n\nSource paragraph:\n{$text}",
            ],
            self::MODE_COUNTER_ARGUMENT => [
                $system,
                $seoContext."Read the following text and write ONE additional paragraph that addresses the most likely objection or counter-argument a thoughtful reader would raise. Rules:\n"
                    ."- DO NOT echo or restate the source paragraph — return ONLY the new counter-argument paragraph\n"
                    ."- Acknowledge the objection in concrete terms, then resolve it without overclaiming\n"
                    ."- 2 to 4 sentences\n"
                    ."- Do NOT begin with 'However' / 'On the other hand' / 'That said' — open in a fresher way\n"
                    ."- Match the input language and tone\n\n"
                    ."Source paragraph (read-only context, do NOT output it):\n{$text}",
            ],
            // ─── Workstream F: cross-block ops (text bundles 2+ blocks) ───
            self::MODE_SUMMARISE_SECTION => [
                $system,
                $seoContext."Summarise the following section of multiple paragraphs into ONE concise paragraph (3 to 5 sentences). Rules:\n"
                    ."- Preserve all key claims, named entities, and numbers from the source\n"
                    ."- Mention the focus keyword once if relevant\n"
                    ."- Match the input language and tone\n\n"
                    ."Return ONLY the summary paragraph.\n\nSection:\n{$text}",
            ],
            self::MODE_GENERATE_HEADING => [
                $system,
                $seoContext."Read the following section of paragraphs and generate ONE H2-level heading that accurately introduces them. Rules:\n"
                    ."- 30 to 60 characters\n"
                    ."- Contains the focus keyword OR a close semantic variant when natural\n"
                    ."- Sentence case, no trailing punctuation\n"
                    ."- Reads as a real subheading a human would write — not a clickbait title\n\n"
                    ."Return ONLY the heading text on a single line.\n\nSection:\n{$text}",
            ],
            self::MODE_HARMONISE_TONE => [
                $system,
                $seoContext."Rewrite the following bundle of paragraphs so they all read in a CONSISTENT tone — pick the tone that best matches the majority of the source, then bring outliers into line. Rules:\n"
                    ."- Preserve every fact, claim, named entity, and number\n"
                    ."- Preserve paragraph breaks (separate paragraphs with two newlines so we can split them back into blocks)\n"
                    ."- Match input language; do not add or drop paragraphs\n\n"
                    ."Return ONLY the rewritten paragraphs, separated by blank lines.\n\nSource:\n{$text}",
            ],
            self::MODE_OUTLINE => [
                $system,
                $seoContext."Read the following post content and produce a recommended heading outline (H2 + H3 structure) the page should use. Rules:\n"
                    ."- Output as a markdown outline using '## H2' and '### H3' prefixes\n"
                    ."- 5 to 10 H2s; nest H3s under H2s where they belong\n"
                    ."- Cover the focus keyword and the major semantic clusters in the source\n"
                    ."- Each heading 30 to 70 characters\n\n"
                    ."Return ONLY the outline.\n\nPost content:\n{$text}",
            ],
            default => [$system, $text],
        };
    }

    /**
     * One-line description of the operation for the selection-aware
     * prompt's "Operation:" line. Maps mode constants to human-readable
     * verbs so the model knows what to do with the selection slice.
     */
    private function selectionOperationLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_REWRITE => 'Rewrite this slice for clarity and flow without changing meaning.',
            self::MODE_GRAMMAR => 'Fix every grammar, spelling, and punctuation error in this slice. Preserve voice and word choice.',
            self::MODE_SHORTER => 'Make this slice shorter (about 30 to 50 percent fewer words). Preserve all key facts.',
            self::MODE_LONGER => 'Expand this slice with one or two more useful sentences. Preserve every existing fact.',
            self::MODE_TONE => 'Adjust the tone of this slice as the surrounding context implies. Preserve meaning and length.',
            self::MODE_SIMPLIFY => 'Rewrite this slice at a Flesch reading-ease score of 60 or higher. Shorter sentences, simpler words.',
            self::MODE_BOOST_FLESCH => 'Rewrite this slice for Flesch reading ease 70 or higher. Sentences 12 to 16 words, plain everyday vocabulary, active voice, preserve names and numbers exactly. No em-dashes or en-dashes.',
            self::MODE_COMMAND => 'Apply the user instruction to this slice. Preserve all named entities and numbers.',
            self::MODE_TRANSLATE => 'Translate this slice while preserving names, numbers, and product terms. Match natural target-language style.',
            default => 'Improve this slice while preserving meaning, named entities, and numbers.',
        };
    }

    private function temperatureFor(string $mode): float
    {
        return match ($mode) {
            self::MODE_GRAMMAR, self::MODE_TRANSLATE => 0.1,
            self::MODE_SUMMARISE, self::MODE_SHORTER, self::MODE_CONVERT_TO_LIST, self::MODE_CONVERT_TO_TABLE => 0.3,
            self::MODE_REWRITE, self::MODE_EXTEND, self::MODE_LONGER, self::MODE_TONE => 0.6,
            self::MODE_COMMAND, self::MODE_TITLE => 0.7,
            default => 0.5,
        };
    }

    /**
     * Per-mode output budget. The old global 1500-cap chopped command-mode
     * outline expansions mid-sentence. Command-mode is the open-ended one
     * (users expand long outlines, generate full sections, paste lists of
     * 20+ items) so it gets the largest budget. Short modes stay tight so
     * the model can't ramble.
     */
    private function maxTokensFor(string $mode): int
    {
        return match ($mode) {
            self::MODE_ALT_TEXT, self::MODE_GENERATE_HEADING => 200,
            self::MODE_SUMMARISE => 500,
            self::MODE_TITLE => 1500,
            self::MODE_EXTEND, self::MODE_TONE, self::MODE_GRAMMAR, self::MODE_TRANSLATE => 4000,
            self::MODE_REWRITE, self::MODE_SHORTER, self::MODE_LONGER => 5000,
            self::MODE_FAQ, self::MODE_CONVERT_TO_LIST, self::MODE_CONVERT_TO_TABLE,
            self::MODE_COUNTER_ARGUMENT, self::MODE_CTA => 6000,
            self::MODE_COMMAND => 12000,
            default => 5000,
        };
    }

    private function stripCodeFences(string $text): string
    {
        $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*\n?/', '', $text) ?? $text;
        $text = preg_replace('/\n?```\s*$/', '', $text) ?? $text;

        return trim($text);
    }
}
