<?php

namespace App\Services;

use App\Models\SearchConsoleData;
use App\Models\Website;
use App\Services\Llm\LlmClient;
use Illuminate\Support\Carbon;

/**
 * Rank Assist — SEO-helper chatbot for the WordPress plugin's floating
 * assistant. Acts like a senior SEO editor with the post open in front of
 * it: it sees live editor state + EBQ meta + offline checks (headings,
 * links, images, readability) + live audit (GSC + Lighthouse + CWV) + the
 * cached SERP-derived content brief if one exists, plus the page's actual
 * top 90-day GSC queries.
 *
 * Output is strict JSON: { reply, action }. The `action` field is a
 * structured proposal — the WP side renders Apply/Discard so nothing
 * mutates the editor silently. When the model needs semantic variants for
 * a keyword the user is exploring, it can call the `get_related_keywords`
 * tool via Mistral function calling — the tool dispatcher reaches into
 * PluginInsightResolver for actual GSC + related queries data.
 */
class AiChatService
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public const ACTION_POST_TITLE = 'update_post_title';
    public const ACTION_FOCUS_KEYWORD = 'update_focus_keyword';
    public const ACTION_ADDITIONAL_KEYWORDS = 'update_additional_keywords';
    public const ACTION_META_TITLE = 'update_meta_title';
    public const ACTION_META_DESCRIPTION = 'update_meta_description';
    public const ACTION_SLUG = 'update_slug';
    public const ACTION_CANONICAL = 'update_canonical';
    public const ACTION_OG_TITLE = 'update_og_title';
    public const ACTION_OG_DESCRIPTION = 'update_og_description';
    public const ACTION_TWITTER_TITLE = 'update_twitter_title';
    public const ACTION_TWITTER_DESCRIPTION = 'update_twitter_description';
    public const ACTION_TWITTER_CARD = 'update_twitter_card';
    public const ACTION_SCHEMA_TYPE = 'update_schema_type';
    public const ACTION_ROBOTS_NOINDEX = 'update_robots_noindex';
    public const ACTION_ROBOTS_NOFOLLOW = 'update_robots_nofollow';
    public const ACTION_PREPEND_HEADING = 'prepend_heading';
    public const ACTION_REWRITE_PARAGRAPH = 'rewrite_paragraph';
    public const ACTION_RERUN_LIVE_AUDIT = 'rerun_live_audit';

    public const ACTION_TYPES = [
        self::ACTION_POST_TITLE,
        self::ACTION_FOCUS_KEYWORD,
        self::ACTION_ADDITIONAL_KEYWORDS,
        self::ACTION_META_TITLE,
        self::ACTION_META_DESCRIPTION,
        self::ACTION_SLUG,
        self::ACTION_CANONICAL,
        self::ACTION_OG_TITLE,
        self::ACTION_OG_DESCRIPTION,
        self::ACTION_TWITTER_TITLE,
        self::ACTION_TWITTER_DESCRIPTION,
        self::ACTION_TWITTER_CARD,
        self::ACTION_SCHEMA_TYPE,
        self::ACTION_ROBOTS_NOINDEX,
        self::ACTION_ROBOTS_NOFOLLOW,
        self::ACTION_PREPEND_HEADING,
        self::ACTION_REWRITE_PARAGRAPH,
        self::ACTION_RERUN_LIVE_AUDIT,
    ];

    private const REWRITE_PARAGRAPH_ALLOWED_TAGS = [
        'p', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'strong', 'em', 'b', 'i',
        'a', 'br', 'blockquote', 'code',
    ];

    private const STRING_ACTION_LIMITS = [
        self::ACTION_POST_TITLE => 200,
        self::ACTION_FOCUS_KEYWORD => 100,
        self::ACTION_META_TITLE => 200,
        self::ACTION_META_DESCRIPTION => 320,
        self::ACTION_SLUG => 200,
        self::ACTION_CANONICAL => 2048,
        self::ACTION_OG_TITLE => 200,
        self::ACTION_OG_DESCRIPTION => 320,
        self::ACTION_TWITTER_TITLE => 200,
        self::ACTION_TWITTER_DESCRIPTION => 320,
        self::ACTION_TWITTER_CARD => 32,
        self::ACTION_SCHEMA_TYPE => 64,
    ];

    private const TWITTER_CARDS = ['summary', 'summary_large_image'];
    private const SCHEMA_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'WebPage',
        'Product', 'Recipe', 'HowTo', 'FAQPage', 'Event',
        'Course', 'Person', 'Organization', 'LocalBusiness',
        'VideoObject', 'ImageObject', 'Review',
    ];

    private const MAX_HISTORY = 20;
    private const MAX_MESSAGE_CHARS = 4000;
    private const MAX_CONTENT_EXCERPT = 4000;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PluginInsightResolver $insights,
        private readonly AiContentBriefService $briefs,
    ) {
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, reply?: string, action?: array<string, mixed>|null, tool_calls?: list<array<string,mixed>>, error?: string}
     */
    public function chat(Website $website, array $messages, array $context = []): array
    {
        if (! $this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'llm_not_configured'];
        }

        $history = $this->sanitiseHistory($messages);
        if (empty($history)) {
            return ['ok' => false, 'error' => 'missing_messages'];
        }
        if (end($history)['role'] !== self::ROLE_USER) {
            return ['ok' => false, 'error' => 'last_message_must_be_user'];
        }

        $payload = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt()]],
            [['role' => 'system', 'content' => $this->contextPrompt($website, $context)]],
            $history,
        );

        $tools = $this->toolDefinitions();
        $dispatcher = $this->makeDispatcher($website, $context);

        $result = $this->llm->completeWithTools($payload, $tools, $dispatcher, [
            'temperature' => 0.3,
            'max_tokens' => 1100,
            'timeout' => 70,
            'max_tool_rounds' => 4,
        ]);

        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'llm_failed')];
        }

        $decoded = is_array($result['decoded'] ?? null) ? $result['decoded'] : null;
        if ($decoded === null) {
            // Fallback: model produced free-text instead of JSON. Salvage it
            // as a plain reply with no action — better than failing the turn.
            $raw = trim((string) ($result['content'] ?? ''));
            if ($raw === '') {
                return ['ok' => false, 'error' => 'llm_empty_response'];
            }
            return [
                'ok' => true,
                'reply' => $raw,
                'action' => null,
                'tool_calls' => $result['tool_calls'] ?? [],
            ];
        }

        $reply = trim((string) ($decoded['reply'] ?? ''));
        if ($reply === '') {
            return ['ok' => false, 'error' => 'llm_empty_response'];
        }

        return [
            'ok' => true,
            'reply' => $reply,
            'action' => $this->validateAction($decoded['action'] ?? null),
            'tool_calls' => $result['tool_calls'] ?? [],
        ];
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function sanitiseHistory(array $messages): array
    {
        $allowed = [self::ROLE_USER, self::ROLE_ASSISTANT];
        $clean = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? '');
            $content = trim((string) ($m['content'] ?? ''));
            if (! in_array($role, $allowed, true) || $content === '') {
                continue;
            }
            if (mb_strlen($content) > self::MAX_MESSAGE_CHARS) {
                $content = mb_substr($content, 0, self::MAX_MESSAGE_CHARS);
            }
            $clean[] = ['role' => $role, 'content' => $content];
        }
        if (count($clean) > self::MAX_HISTORY) {
            $clean = array_slice($clean, -self::MAX_HISTORY);
        }
        return $clean;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateAction(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $type = (string) ($raw['type'] ?? '');
        if (! in_array($type, self::ACTION_TYPES, true)) {
            return null;
        }

        $summary = trim((string) ($raw['summary'] ?? ''));
        if (mb_strlen($summary) > 200) {
            $summary = mb_substr($summary, 0, 200);
        }

        // Re-run the live audit fetch on the client. Value MUST be true —
        // false is a no-op and we don't want to render an Apply card for it.
        if ($type === self::ACTION_RERUN_LIVE_AUDIT) {
            $value = filter_var($raw['value'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== true) {
                return null;
            }
            return [
                'type' => $type,
                'value' => true,
                'summary' => $summary !== '' ? $summary : 'Re-fetch the live audit (Lighthouse + GSC + composite SEO score)',
            ];
        }

        // Content-level: replace one existing block (paragraph / heading /
        // list) in the post body with new HTML — typically used to split a
        // long paragraph into shorter ones, swap prose for a bullet list, or
        // tighten sentences for readability.
        if ($type === self::ACTION_REWRITE_PARAGRAPH) {
            $value = $raw['value'] ?? null;
            if (! is_array($value)) {
                return null;
            }
            $match = trim((string) ($value['match_text'] ?? ''));
            $newHtml = trim((string) ($value['new_html'] ?? ''));
            if (mb_strlen($match) < 20 || mb_strlen($match) > 400) {
                return null;
            }
            if ($newHtml === '' || mb_strlen($newHtml) > 6000) {
                return null;
            }
            // Tag whitelist — extract every tag name and require all to be
            // in the safe list. Inline event handlers, scripts, styles,
            // iframes, divs/spans with class spam — all rejected.
            preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/', $newHtml, $matches);
            $tags = array_unique(array_map('strtolower', $matches[1] ?? []));
            foreach ($tags as $tag) {
                if (! in_array($tag, self::REWRITE_PARAGRAPH_ALLOWED_TAGS, true)) {
                    return null;
                }
            }
            // No on* event handlers, no javascript: hrefs.
            if (preg_match('/\son[a-z]+\s*=/i', $newHtml)) return null;
            if (preg_match('/javascript\s*:/i', $newHtml)) return null;

            return [
                'type' => $type,
                'value' => [
                    'match_text' => $match,
                    'new_html' => $newHtml,
                ],
                'summary' => $summary !== '' ? $summary : 'Rewrite a paragraph block',
            ];
        }

        // Content-level: prepend an H1/H2/H3 element to the start of the post body.
        if ($type === self::ACTION_PREPEND_HEADING) {
            $value = $raw['value'] ?? null;
            if (! is_array($value)) {
                return null;
            }
            $level = (int) ($value['level'] ?? 0);
            $text = trim((string) ($value['text'] ?? ''));
            if (! in_array($level, [1, 2, 3], true)) {
                return null;
            }
            if ($text === '' || mb_strlen($text) > 200) {
                return null;
            }
            if (preg_match('/<[^>]+>/', $text)) {
                // Heading text must be plain — no inline HTML, no nested tags.
                return null;
            }
            return [
                'type' => $type,
                'value' => ['level' => $level, 'text' => $text],
                'summary' => $summary !== '' ? $summary : "Insert an H{$level} at the top of the post body",
            ];
        }

        // Boolean actions (robots flags).
        if (in_array($type, [self::ACTION_ROBOTS_NOINDEX, self::ACTION_ROBOTS_NOFOLLOW], true)) {
            $value = filter_var($raw['value'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                return null;
            }
            return [
                'type' => $type,
                'value' => $value,
                'summary' => $summary !== '' ? $summary : $this->defaultSummary($type),
            ];
        }

        // Array action: additional keyphrases.
        if ($type === self::ACTION_ADDITIONAL_KEYWORDS) {
            $value = $raw['value'] ?? null;
            if (! is_array($value)) {
                return null;
            }
            $clean = [];
            foreach ($value as $k) {
                $s = trim((string) $k);
                if ($s === '') continue;
                if (mb_strlen($s) > 100) {
                    $s = mb_substr($s, 0, 100);
                }
                $clean[] = $s;
                if (count($clean) >= 5) break;
            }
            if (empty($clean)) {
                return null;
            }
            return [
                'type' => $type,
                'value' => $clean,
                'summary' => $summary !== '' ? $summary : 'Update additional keyphrases',
            ];
        }

        // String actions — length-limited; some are enum-validated.
        $value = trim((string) ($raw['value'] ?? ''));
        if ($value === '') {
            return null;
        }
        $limit = self::STRING_ACTION_LIMITS[$type] ?? 1000;
        if (mb_strlen($value) > $limit) {
            return null;
        }

        if ($type === self::ACTION_TWITTER_CARD && ! in_array($value, self::TWITTER_CARDS, true)) {
            return null;
        }
        if ($type === self::ACTION_SCHEMA_TYPE && ! in_array($value, self::SCHEMA_TYPES, true)) {
            return null;
        }
        if ($type === self::ACTION_CANONICAL && ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        if ($type === self::ACTION_SLUG) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
                return null;
            }
        }

        return [
            'type' => $type,
            'value' => $value,
            'summary' => $summary !== '' ? $summary : $this->defaultSummary($type),
        ];
    }

    private function defaultSummary(string $type): string
    {
        return match ($type) {
            self::ACTION_POST_TITLE => 'Update post title',
            self::ACTION_FOCUS_KEYWORD => 'Update focus keyword',
            self::ACTION_META_TITLE => 'Update SEO meta title',
            self::ACTION_META_DESCRIPTION => 'Update SEO meta description',
            self::ACTION_SLUG => 'Update post slug',
            self::ACTION_CANONICAL => 'Update canonical URL',
            self::ACTION_OG_TITLE => 'Update Open Graph title',
            self::ACTION_OG_DESCRIPTION => 'Update Open Graph description',
            self::ACTION_TWITTER_TITLE => 'Update Twitter title',
            self::ACTION_TWITTER_DESCRIPTION => 'Update Twitter description',
            self::ACTION_TWITTER_CARD => 'Update Twitter card type',
            self::ACTION_SCHEMA_TYPE => 'Update schema type',
            self::ACTION_ROBOTS_NOINDEX => 'Toggle robots noindex',
            self::ACTION_ROBOTS_NOFOLLOW => 'Toggle robots nofollow',
            self::ACTION_PREPEND_HEADING => 'Insert heading at top of post',
            self::ACTION_REWRITE_PARAGRAPH => 'Rewrite a paragraph block',
            self::ACTION_RERUN_LIVE_AUDIT => 'Re-fetch the live audit',
            default => 'Apply update',
        };
    }

    /* ────────────────────── tools (Mistral function calling) ─────────────────── */

    /**
     * @return list<array{type:string, function:array{name:string, description:string, parameters:array<string,mixed>}}>
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_related_keywords',
                    'description' => 'Look up semantic variants and related search queries for a keyword by combining real Search Console queries on this site with related-keyword data. Use this when the user is exploring keyword alternatives, asking what other keywords they could target, or you need to suggest semantic variants for the focus keyword. Returns a ranked list with volume, position, clicks, impressions, and source (gsc/related/paa).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => [
                                'type' => 'string',
                                'description' => 'The seed keyword to find variants for. Defaults to the post\'s current focus keyword if omitted.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum number of variants to return (1–20). Defaults to 12.',
                                'minimum' => 1,
                                'maximum' => 20,
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function makeDispatcher(Website $website, array $context): callable
    {
        return function (string $name, array $args) use ($website, $context): array|string {
            return match ($name) {
                'get_related_keywords' => $this->dispatchRelatedKeywords($website, $context, $args),
                default => ['error' => 'unknown_tool', 'name' => $name],
            };
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $args
     */
    private function dispatchRelatedKeywords(Website $website, array $context, array $args): array
    {
        $keyword = trim((string) ($args['keyword'] ?? ''));
        if ($keyword === '') {
            $keyword = trim((string) ($context['focus_keyword'] ?? ''));
        }
        if ($keyword === '') {
            return ['error' => 'missing_keyword', 'message' => 'No keyword supplied and no focus keyword set on this post.'];
        }
        $limit = (int) ($args['limit'] ?? 12);
        if ($limit < 1 || $limit > 20) {
            $limit = 12;
        }

        $url = trim((string) ($context['url'] ?? ''));
        try {
            $rows = $this->insights->relatedKeywords($website, $keyword, $url !== '' ? $url : null, $limit);
        } catch (\Throwable $e) {
            return ['error' => 'lookup_failed', 'message' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'keyword' => $keyword,
            'count' => count($rows),
            'variants' => array_map(static function (array $row): array {
                return [
                    'query' => (string) ($row['query'] ?? ''),
                    'volume' => $row['volume'] ?? null,
                    'clicks' => $row['clicks'] ?? null,
                    'impressions' => $row['impressions'] ?? null,
                    'position' => $row['position'] ?? null,
                    'source' => (string) ($row['source'] ?? ''),
                    'score' => $row['score'] ?? null,
                ];
            }, $rows),
        ];
    }

    /* ────────────────────── prompts ──────────────────────────────────────────── */

    private function systemPrompt(): string
    {
        $actions = "- update_post_title (string ≤200; sweet spot 50–60 chars on the rendered title)\n"
            ."- update_focus_keyword (string ≤100; one primary head term)\n"
            ."- update_additional_keywords (array of 1–5 strings; closely-related modifiers, not synonyms)\n"
            ."- update_meta_title (string ≤200; SERP display sweet spot 50–60)\n"
            ."- update_meta_description (string ≤320; SERP display sweet spot 150–160)\n"
            ."- update_slug (string, lowercase ASCII + hyphens only; ideally 3–6 words, includes the focus keyword)\n"
            ."- update_canonical (absolute URL; only when the user wants to point this page at a different canonical)\n"
            ."- update_og_title / update_og_description (Facebook/LinkedIn social card overrides)\n"
            ."- update_twitter_title / update_twitter_description (X/Twitter social card overrides)\n"
            ."- update_twitter_card (string, one of: summary | summary_large_image)\n"
            ."- update_schema_type (string, one of: Article | BlogPosting | NewsArticle | WebPage | Product | Recipe | HowTo | FAQPage | Event | Course | Person | Organization | LocalBusiness | VideoObject | ImageObject | Review)\n"
            ."- update_robots_noindex (boolean; true to hide from search engines)\n"
            ."- update_robots_nofollow (boolean; true to instruct engines not to follow links from this page)\n"
            ."- prepend_heading (object: { level: 1|2|3, text: plain string ≤200 chars }) — inserts a real <h1>/<h2>/<h3> element at the very top of the post body content. Use this — and ONLY this — when the user wants to add an H1/H2/H3 heading inside the post content.\n"
            ."- rewrite_paragraph (object: { match_text: 20–400 chars, new_html: HTML string ≤6000 chars }) — REPLACES one existing block in the post body with new HTML. Use this for paragraph rewrites, splitting long paragraphs into shorter ones, swapping prose for a bullet list, tightening sentences for readability, or restructuring a section. match_text MUST be the first 30–150 chars of the existing block's plain text (taken verbatim from the content excerpt in the context — enough to identify the block uniquely). new_html may contain only these tags: p, h2-h6, ul, ol, li, strong, em, b, i, a, br, blockquote, code. NO div, span, script, style, iframe, image, class/id/style attributes, or inline event handlers — those are rejected by the validator and the action will be silently dropped. Quote attribute values with double quotes.\n"
            ."- rerun_live_audit (boolean: true) — re-fetches the live audit data (Lighthouse mobile/desktop, Core Web Vitals, GSC totals, composite SEO score). Use this when the user asks to refresh / re-run / re-check the audit, or when you suspect the cached audit numbers are stale (e.g., after they applied a structural change like prepend_heading). Note: this re-fetches the latest server-side audit; a fresh Lighthouse re-crawl on the EBQ backend may be queued and take several minutes to complete.";

        return "You are Rank Assist, a senior SEO editor embedded in the WordPress post editor. Talk and reason like a professional SEO expert reviewing a draft over the user's shoulder. Prefer evidence — cite the actual numbers from the supplied context (title length, keyword density, GSC clicks, Lighthouse score, headings hierarchy, link counts). Never offer advice that contradicts the data in front of you.\n\n"
            ."OUTPUT FORMAT — every reply is strict JSON with this exact shape:\n"
            ."{\n"
            ."  \"reply\": \"<message shown to the user>\",\n"
            ."  \"action\": null OR { \"type\": \"<one of the action types>\", \"value\": <value>, \"summary\": \"<one-line label for the Apply button>\" }\n"
            ."}\n"
            ."Allowed action types and value shapes:\n{$actions}\n\n"
            ."WHEN TO PROPOSE AN ACTION:\n"
            ."- The user explicitly asks you to fix, rewrite, change, set, draft, or apply something the actions above can handle.\n"
            ."- You have enough context to write the new value yourself. If you don't, ask one focused clarifying question instead (action = null).\n"
            ."- Only propose ONE action per turn. If multiple things need fixing, fix the highest-impact one and tell the user you can do the rest in follow-up turns.\n"
            ."- Never propose an action for content the user has not asked you to change.\n"
            ."- For boolean actions (robots), only propose them when the user has clearly indicated intent (e.g. 'hide this from Google').\n\n"
            ."CRITICAL DISAMBIGUATION — POST TITLE vs CONTENT H1:\n"
            ."- update_post_title sets the WordPress post title attribute (the field at the top of the editor). Themes decide where it renders — most themes render it as the page H1, but that's a theme choice, not a content element.\n"
            ."- prepend_heading inserts an actual <h1>/<h2>/<h3> HTML element at the START of the post body content.\n"
            ."- These are NEVER interchangeable. If the user says 'add an H1', 'add a heading', 'add a section heading', 'my content has no H1' — that means content body, use prepend_heading.\n"
            ."- If the user says 'change the title', 'rewrite my title', 'shorten the title' — that's the post title attribute, use update_post_title.\n"
            ."- Never propose update_post_title to satisfy a 'add a heading' request. That mutates the wrong thing and looks like a no-op when the title was already set.\n\n"
            ."HEADING COUNT SEMANTICS — IMPORTANT:\n"
            ."- h1_count, h2_count, h3_count, h2_outline, first_h1, headings_skip_levels in offline_audit are ALL computed from the post BODY CONTENT only — not from the rendered page.\n"
            ."- Most WordPress themes render the post title as the page H1 via the theme template, NOT inside the post body content. So h1_count: 0 in the body is the NORMAL case for most posts and is NOT a problem on its own.\n"
            ."- Only treat 'no H1' as an actionable issue when the body has NO headings of any level (h1_count == 0 AND h2_count == 0 AND h3_count == 0) AND the post is long enough (>250 words) to benefit from structure. In that case, propose an H2 (level 2) via prepend_heading — the theme will provide the H1.\n"
            ."- A real heading problem worth flagging is h1_count > 1 in the body (likely a duplicate page H1 once the theme adds its own).\n"
            ."- If the user says 'my post has an H1' or 'I already have a heading', BELIEVE THEM — they may be looking at the rendered page (theme-supplied H1) which we can't see. Do not insist they need an H1 unless headings_skip_levels is true or the body is fully heading-less.\n\n"
            ."CONTENT-LEVEL CHANGES YOU CAN DIRECTLY APPLY:\n"
            ."- Inserting an H1/H2/H3 at the top of the body → prepend_heading.\n"
            ."- Rewriting a single existing paragraph or block (split for readability, convert prose to a list, tighten sentences) → rewrite_paragraph.\n"
            ."- Replacing one section's leading block (e.g., the paragraph immediately after an H2) → rewrite_paragraph; pick a unique match_text from that block.\n\n"
            ."CONTENT-LEVEL CHANGES YOU CANNOT DIRECTLY APPLY (set action = null and provide pastable values + paste-location instructions):\n"
            ."- Fixing typos scattered across the post (no surgical typo-fix action — give a list of corrections).\n"
            ."- Inserting internal links inline mid-paragraph (no inline-link action — name the anchor text and target URL the user should add manually).\n"
            ."- Writing alt text for specific images (no per-image action — list the alt text per image filename).\n"
            ."- Adding FAQ schema items, JSON-LD schema bodies, or other structured-data fields beyond schema_type.\n"
            ."- Bulk-rewriting many paragraphs in one turn — pick the highest-impact one and rewrite it via rewrite_paragraph; tell the user you'll handle the rest in follow-ups.\n\n"
            ."WHEN YOU PROPOSE AN ACTION, the `reply` text MUST:\n"
            ."1. Restate what you understood from the user's message in one sentence.\n"
            ."2. Cite the current value verbatim (or note 'currently empty' / 'currently disabled') with its character count or boolean state when relevant.\n"
            ."3. State the proposed new value (preview the text) and its character count.\n"
            ."4. Explain in one sentence WHY this change helps SEO — be specific (length, keyword placement, intent fit, click-worthiness, schema fit, social CTR, indexation strategy).\n"
            ."5. Make it clear the change is NOT yet applied — phrase as a proposal awaiting user confirmation. Never say 'I've updated', 'I changed', 'I set'.\n"
            ."Keep the `summary` tight, e.g. 'Replace meta description (42 → 158 chars)' or 'Set focus keyword to \"thai green curry recipe\"'.\n\n"
            ."FUNCTION CALLING:\n"
            ."- You have access to one tool: `get_related_keywords(keyword, limit)`. Call it when the user wants to explore alternative keywords, the focus keyword has poor data, or you need to validate a proposed keyword change against real GSC + related-query data on this site.\n"
            ."- Don't call tools the user didn't ask for indirectly. A simple title-tweak request doesn't need keyword research.\n"
            ."- Tool results stream back as JSON; integrate them into your reply, don't dump them raw.\n\n"
            ."WHAT YOU DO (scope):\n"
            ."- Title, meta description, slug, canonical, robots, social cards, schema type — anything in the action list.\n"
            ."- Focus keyword choice & semantic variants (use the related-keywords tool when helpful).\n"
            ."- Heading hierarchy critique (H1 count, missing H2/H3, level-skipping).\n"
            ."- Internal/external link structure, anchor text quality.\n"
            ."- Image alt-text coverage (you'll see how many images lack alt).\n"
            ."- Readability (Flesch reading ease, sentence length, paragraph density).\n"
            ."- Search-intent alignment, content depth vs. recommended word count, E-E-A-T signals, freshness.\n"
            ."- Interpretation of GSC data (clicks, impressions, CTR, average position) and Core Web Vitals (LCP, CLS, INP).\n"
            ."- Cannibalization, striking-distance opportunities, content-brief gaps.\n\n"
            ."WHAT YOU DECLINE:\n"
            ."- Off-topic chat (general coding, personal advice, jokes, news unrelated to this post).\n"
            ."- Generating long-form drafts — point users to the Serfix AI Writer feature instead.\n"
            ."- Politics, medical/legal/financial advice unrelated to SEO copy review.\n"
            ."When asked something off-topic, briefly steer back to this post's SEO and set action to null. Don't lecture.\n\n"
            ."STYLE:\n"
            ."- Concise. The `reply` is 2–6 short sentences or a tight bulleted list. No padding, no boilerplate openings.\n"
            ."- Reference the actual post data (title text, focus keyword, GSC numbers, Lighthouse scores) — never invent.\n"
            ."- If a relevant signal is missing (e.g. no focus keyword set), name the gap and offer to fix it.\n"
            ."- Plain text or simple Markdown lists in `reply`. No HTML tags, no code fences.\n\n"
            ."Always emit valid JSON. Never include any text outside the JSON object.";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function contextPrompt(Website $website, array $context): string
    {
        $lines = [];
        $lines[] = 'CURRENT POST CONTEXT (live data from the WordPress editor + the EBQ backend — refer to these when answering, do not invent values):';

        $site = trim((string) ($website->domain ?? ''));
        if ($site !== '') {
            $lines[] = "- Site: {$site} (tier: ".$website->effectiveTier().')';
        }

        $title = trim((string) ($context['title'] ?? ''));
        if ($title !== '') {
            $lines[] = '- Live editor title: '.$title.' ('.mb_strlen($title).' chars)';
        }

        $url = trim((string) ($context['url'] ?? ''));
        if ($url !== '') {
            $lines[] = "- URL: {$url}";
        }
        $slug = trim((string) ($context['slug'] ?? ''));
        if ($slug !== '') {
            $lines[] = "- Slug: {$slug}";
        }

        $postType = trim((string) ($context['post_type'] ?? ''));
        if ($postType !== '') {
            $lines[] = "- Post type: {$postType}";
        }

        $focus = trim((string) ($context['focus_keyword'] ?? ''));
        if ($focus !== '') {
            $lines[] = "- Focus keyword: {$focus}";
        } else {
            $lines[] = '- Focus keyword: NOT SET (recommend setting one — you may propose update_focus_keyword if the user hints at the topic)';
        }

        $additional = $context['additional_keywords'] ?? [];
        if (is_array($additional) && ! empty($additional)) {
            $clean = array_values(array_filter(array_map(static fn ($k) => trim((string) $k), $additional), static fn ($s) => $s !== ''));
            if (! empty($clean)) {
                $lines[] = '- Additional keyphrases: '.implode(', ', array_slice($clean, 0, 10));
            }
        }

        $metaTitle = trim((string) ($context['meta_title'] ?? ''));
        $lines[] = $metaTitle !== ''
            ? "- SEO meta title: {$metaTitle} (".mb_strlen($metaTitle).' chars)'
            : '- SEO meta title: NOT SET (the rendered <title> falls back to the post title)';

        $metaDescription = trim((string) ($context['meta_description'] ?? ''));
        $lines[] = $metaDescription !== ''
            ? "- SEO meta description: {$metaDescription} (".mb_strlen($metaDescription).' chars)'
            : '- SEO meta description: NOT SET (Google will autogenerate a snippet; setting one is almost always better)';

        $canonical = trim((string) ($context['canonical'] ?? ''));
        if ($canonical !== '') {
            $lines[] = "- Canonical override: {$canonical}";
        }

        // Robots flags — only call out when active (rare).
        $robots = is_array($context['robots'] ?? null) ? $context['robots'] : [];
        $robotFlags = [];
        if (! empty($robots['noindex'])) $robotFlags[] = 'noindex';
        if (! empty($robots['nofollow'])) $robotFlags[] = 'nofollow';
        if (! empty($robotFlags)) {
            $lines[] = '- Robots directives: '.implode(', ', $robotFlags).' — this page is currently restricted from search engines or link-following.';
        }

        // Social-card overrides.
        $social = is_array($context['social'] ?? null) ? $context['social'] : [];
        $socialBits = [];
        foreach (['og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_card'] as $k) {
            if (! empty($social[$k])) {
                $socialBits[] = "{$k}=".(is_string($social[$k]) ? $social[$k] : json_encode($social[$k]));
            }
        }
        if (! empty($socialBits)) {
            $lines[] = '- Social-card overrides set: '.implode('; ', $socialBits);
        }

        $schemaType = trim((string) ($context['schema_type'] ?? ''));
        if ($schemaType !== '') {
            $lines[] = "- Configured schema type: {$schemaType}";
        }

        $excerpt = trim((string) ($context['content_excerpt'] ?? ''));
        if ($excerpt !== '') {
            if (mb_strlen($excerpt) > self::MAX_CONTENT_EXCERPT) {
                $excerpt = mb_substr($excerpt, 0, self::MAX_CONTENT_EXCERPT).'…';
            }
            $lines[] = "- Live editor content (truncated):\n{$excerpt}";
        }

        $offline = $context['offline_audit'] ?? null;
        if (is_array($offline) && ! empty($offline)) {
            $lines[] = '- Offline checks (computed from live editor DOM — these are the freshest signals):';
            foreach ($offline as $key => $value) {
                $k = is_string($key) ? $key : (string) $key;
                $v = $this->scalarise($value);
                if ($v !== '') {
                    $lines[] = "  · {$k}: {$v}";
                }
            }
        }

        $live = $context['live_audit'] ?? null;
        if (is_array($live) && ! empty($live)) {
            $lines[] = '- Live audit (EBQ backend — Lighthouse + GSC totals + composite SEO score):';
            foreach ($live as $key => $value) {
                $k = is_string($key) ? $key : (string) $key;
                $v = $this->scalarise($value);
                if ($v !== '') {
                    $lines[] = "  · {$k}: {$v}";
                }
            }
        }

        // Server-side enrichment: cached SERP brief + page's actual top GSC queries.
        $brief = $focus !== '' ? $this->briefs->cachedBrief($website, $focus) : null;
        $briefSummary = $this->summariseBrief($brief);
        if ($briefSummary !== '') {
            $lines[] = "- Cached SERP-derived content brief for \"{$focus}\":\n{$briefSummary}";
        }

        if ($url !== '') {
            $topQueries = $this->topGscQueries($website, $url, 10);
            if (! empty($topQueries)) {
                $lines[] = '- Top 90-day Search Console queries actually driving traffic to this page (clicks · impressions · avg position · CTR%):';
                foreach ($topQueries as $row) {
                    $lines[] = sprintf(
                        '  · "%s" — %d · %d · %.1f · %.2f%%',
                        (string) $row['query'],
                        (int) $row['clicks'],
                        (int) $row['impressions'],
                        (float) ($row['position'] ?? 0),
                        (float) ($row['ctr'] ?? 0),
                    );
                }
            }
        }

        // Authoritative log of actions the user already approved this session.
        // Rendered last so the model treats it as the most recent state — when
        // the values above are derived from a stale source (e.g. classic-editor
        // DOM lag, or cached savedMeta from page load), this section is the
        // tie-breaker.
        $appliedActions = is_array($context['applied_actions'] ?? null) ? $context['applied_actions'] : [];
        if (! empty($appliedActions)) {
            $lines[] = '';
            $lines[] = 'CHANGES THE USER ALREADY APPLIED IN THIS SESSION (these are LIVE in the editor — the field values above already reflect them. Do not propose them again, and do not tell the user to set them — they are SET):';
            foreach ($appliedActions as $a) {
                if (! is_array($a)) continue;
                $type = (string) ($a['type'] ?? '');
                $summary = trim((string) ($a['summary'] ?? ''));
                if ($type === '') continue;
                $lines[] = '  · '.$type.($summary !== '' ? " — {$summary}" : '');
            }
        }

        $lines[] = '';
        $lines[] = 'When the user asks about "this post", "the title", "my keyword", "my score" — use the data above. Any concrete edit must be proposed via the action field, never described as already applied. If you need semantic variants for a different keyword, call the get_related_keywords tool.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $brief
     */
    private function summariseBrief(?array $brief): string
    {
        if (! is_array($brief) || ! isset($brief['brief']) || ! is_array($brief['brief'])) {
            return '';
        }
        $b = $brief['brief'];
        $bits = [];
        $angle = trim((string) ($b['angle'] ?? ''));
        if ($angle !== '') {
            $bits[] = "  · Search intent: {$angle}";
        }
        $wc = (int) ($b['recommended_word_count'] ?? 0);
        if ($wc > 0) {
            $bits[] = "  · Recommended word count: {$wc}";
        }
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
        $subtopics = $list($b['subtopics'] ?? [], 6);
        if (! empty($subtopics)) {
            $bits[] = '  · Subtopics top SERP results cover: '.implode(' · ', $subtopics);
        }
        $entities = $list($b['must_have_entities'] ?? [], 8);
        if (! empty($entities)) {
            $bits[] = '  · Entities to mention if relevant: '.implode(', ', $entities);
        }
        $paa = $list($b['people_also_ask'] ?? [], 4);
        if (! empty($paa)) {
            $bits[] = '  · People also ask: '.implode(' | ', $paa);
        }
        return empty($bits) ? '' : implode("\n", $bits);
    }

    /**
     * @return list<array{query:string, clicks:int, impressions:int, position:float|null, ctr:float|null}>
     */
    private function topGscQueries(Website $website, string $url, int $limit): array
    {
        try {
            $tz = config('app.timezone');
            $end = Carbon::yesterday($tz)->endOfDay();
            $start = $end->copy()->subDays(89)->startOfDay();

            $rows = SearchConsoleData::query()
                ->where('website_id', $website->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->where('query', '!=', '')
                ->tap(fn ($q) => $this->insights->__publicApplyPageMatch($q, $url))
                ->selectRaw('query, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position, AVG(ctr) as ctr')
                ->groupBy('query')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->get();

            return $rows->map(static fn ($r) => [
                'query' => (string) $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
                'ctr' => $r->ctr !== null ? round((float) $r->ctr * 100, 2) : null,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function scalarise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                if (is_scalar($v)) {
                    $parts[] = is_int($k) ? (string) $v : "{$k}={$v}";
                }
            }
            return implode(', ', array_slice($parts, 0, 12));
        }
        return '';
    }
}
