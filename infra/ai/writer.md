# AI Writer, chat & content tooling

> The long-form side of the suite: the multi-step Writer wizard, the in-editor
> block ops, brand voice, the Rank Assist chatbot, and the supporting brief /
> snippet / keyword services. These bypass the tool registry and drive the LLM
> directly, but share the same client, guardrails, and credit accounting.

## Key components

| Component | File | Role |
|---|---|---|
| Writer core | `app/Services/AiWriterService.php:59` | One-call section generator (`draft()`). Used by the editor draft flow and the wizard's strict final step. |
| Wizard orchestrator | `app/Services/WriterProjectService.php` | Owns the wizard state machine + **all** credit accounting; delegates brief/generate/strategy. |
| Brand voice | `app/Services/BrandVoiceService.php:35` | Extract + cache a per-site voice fingerprint from sample posts. |
| Block editor | `app/Services/AiBlockEditorService.php:95` | 25 single-shot Gutenberg block ops, sub-15s target, no cache/chaining. |
| Custom-prompt guard | `app/Services/AiWriter/CustomPromptGuard.php:33` | Validates user "additional instructions" before they enter the system prompt. |
| Content brief | `app/Services/AiContentBriefService.php:81` | SERP → LLM → internal-link brief (subtopics, outline, schema, entities, PAA). |
| Snippet rewriter | `app/Services/AiSnippetRewriterService.php:290` | AI title + meta-description rewrites with hard spec validation. |
| Related keywords | `app/Services/AiRelatedKeywordsService.php:40` | 12 AI keyphrase suggestions from the focus keyword alone. |
| Chat | `app/Services/AiChatService.php:116` | Rank Assist SEO chatbot; function-calling; one structured action per turn. |

## Writer flow (wizard → blog post)

`WriterProjectService` drives a `WriterProject` through
`topic → brief → strategy → images → summary → completed`:

1. **Brief** (`generateBrief` → `AiContentBriefService::brief`): Serper top-10 SERP →
   one `completeJson` call (temp 0.4, 1500 tokens, 45s) → bolt on internal-link targets
   from the site's own GSC (90d). Cached **7 days** (`ai_content_brief_v3:*`).
2. **Strategy** (`generateStrategy`): runs registry tools (`seo-title`, `seo-meta`,
   `seo-description`, `faq-generator`, `keyword-suggestions`, internal/external link
   suggestions) — **multiple LLM calls**, with a retry for meta descriptions when <3
   land in the 120–158 char band.
3. **Images** (`searchImages`): Serper image search, flat **1 credit**.
4. **Generate** (`generate` → `AiWriterService::draft` in strict mode): the final draft.

### `AiWriterService::draft` — essentially ONE big LLM call

`completeJson`, **temp 0.5, max_tokens 16000, 240s timeout** (`AiWriterService.php:237`).
The model emits ALL sections in a single JSON object `{summary, sections[]}`; there is
no outline→sections two-phase (the outline arrives pre-built in the brief). Heavy PHP
post-processing follows: strict-mode strips per-section `<h1>`/`replace` ops, enforces
locked anchors, re-injects dropped manual links, audits LSI usage, builds schema
suggestions, strips dashes. Caps: `MAX_SECTIONS = 20`, `SECTION_HTML_CAP = 6000` chars
(~900 words). Prompt is version **v24** (changelog in-file); cache key `ai_writer_v24:*`
(24h TTL). Then `WriterProjectService::assembleHtml` concatenates sections (no leading
`<h1>` — the WP post title is the H1) and injects `<figure>` blocks by H2 match.
Everything is **synchronous / in-request — no queues**.

### Writer guardrails / moat

- **Two-layer dash defense**: prompt HARD-bans U+2014/U+2013/`--`, plus a post-process
  `stripDashes()` net (`AiWriterService::stripDashes`, static, reused by the controller).
- **Locked anchors**: manual links (`anchor_locked:true`) must appear verbatim — prompt
  rule + hard post-process `enforceLockedAnchors()`. A manual-link backstop injects any
  URL the model dropped, appending a "Further reading" line only if no section fits.
- **Smart internal links** (`resolveSmartInternalLinks`): scores GSC click data (89d) as
  `signal × (1 + token_overlap)`, WordPress-pages fallback at a low synthetic signal;
  caps at 12 candidates.
- **SEO honouring**: the `SIGNAL_SEO_ANALYSIS` block tells the writer which gaps to close
  (kw density, missing H1, flat sections, reading grade) so it writes to fix them.

### Credits (`WriterProjectService::recordCredits`)

Char-based: `DEFAULT_CHARS_PER_CREDIT = 400` (overridable via
`services.ebq_credits.chars_per_credit`, floored 50), `ceil(chars/perCredit)`, min 1.
Brief-chat uses a token ratio (`ceil(tokens/100)`); image search is a flat 1 credit.
One `client_activities` row per step (provider `ebq_content_credits`,
`meta.writer_project_id`) + increments `writer_projects.credits_used`. Dashboard wizard
is `app/Http/Controllers/AiStudioWriterController.php` (session-resolved, Pro-gated 402).

## Brand voice

`BrandVoiceService::extract` sends 2–5 cleaned samples (HTML-stripped, ≥200 / ≤8000
chars each) in ONE `complete` call (temp 0.3, 1500 tokens, json, 120s) → a fingerprint
JSON (tone, person, avg_sentence_words clamped 5–60, vocabulary_band, formality 0–100,
≤8 signature_phrases, ≤12 avoid_phrases always seeded with "delve/leverage/tapestry of…",
opening/closing patterns, hooks). Persisted via `updateOrCreate` on `BrandVoiceProfile`,
cached 24h (`brand_voice:v1:{id}`). `summaryForPlugin` **redacts** signature/avoid
phrases — that's the prompt-engineering moat. `BrandVoiceBlock` renders the fingerprint
into the system prompt; injection into Studio tools happens via `ContextBuilder`.

## Block editor ops

`AiBlockEditorService::generate` — 25 modes (rewrite/grammar/extend/summarise/tone/
list/table/title/alt_text/cta/faq/SEO…). ONE plain-text `complete` call (not JSON, not
streaming), per-mode temperature (0.1 grammar → 0.7 title/command) and max_tokens (200
alt-text → 12000 command), 180s. **Model routing**: default tier, but **title** mode →
`mistral-medium-latest`, **alt_text + image_url** → multi-modal `pixtral-12b-latest`
(vision). Post-processed by `AiSnippetRewriterService::humanizePunctuation()` (strip
em/en-dashes, straighten curly quotes). Brief context is cache-only (never a fresh run).

## Chat (Rank Assist)

`AiChatService::chat` — one turn over `LlmClient::completeWithTools` (function-calling,
multi-round, **non-streaming**): temp 0.3, max_tokens 1100, timeout 70, `max_tool_rounds
4`. Single tool `get_related_keywords(keyword, limit)`. Emits strict JSON
`{reply, action}` where `action` is one of 18 structured proposals the WP side renders as
Apply/Discard — **never mutates silently**. Heavy server-side `validateAction`:
tag whitelist on `rewrite_paragraph`, rejects `on*` handlers / `javascript:` URIs, length
caps, heading-level restrictions, enum validation. Free-text replies (non-JSON) are
salvaged as a plain reply with `action = null`. History caps: 20 messages, 4000 chars
each. The system prompt encodes the `update_post_title` vs `prepend_heading`
disambiguation (never interchangeable) and declines long-form drafts (points to AI Writer).

## Content tooling specifics

- **Brief** (`AiContentBriefService`): `cachedBrief` never triggers a paid run;
  `internalLinkTargets` pulls top GSC-clicked URLs matching kw tokens (90d, ≤6),
  SQL-excludes the current URL. Errors short-circuit (`no_serp_data`, `llm_parse_failed`).
  Cache namespace v3 — bump on prompt change.
- **Snippet rewriter** (`AiSnippetRewriterService`): `mistral-medium-latest`, temp 0.6
  (retry 0.4), 3200 tokens, 60s; up to 2 calls (initial + feedback-driven retry).
  **Hard validation**: every rewrite must contain the verbatim focus keyword in title +
  meta, title 30–60 chars, meta 130–155 chars — out-of-spec rewrites are **dropped, not
  truncated** (v7 removed padding/truncation). `PROMPT_VERSION` v9. Competitor titles are
  passed in (no live SERP here).
- **Related keywords** (`AiRelatedKeywordsService`): `mistral-medium-latest`, temp 0.5,
  700 tokens, 30s; 12 suggestions, cached 7d. Output shape matches the old GSC contract so
  `RelatedKeyphrases.jsx` is unchanged.

## Gotchas

- **`CustomPromptGuard` fails OPEN** — if the classifier LLM (json, temp 0.0, 8s) is
  unavailable or malformed, the prompt is allowed; the writer already scopes custom text
  as advisory-only, so a flaky classifier never blocks legit users.
- **Writer generation is fully synchronous** (up to ~4 min wall time at 16k tokens). The
  controllers raise `set_time_limit(360)`; the Mistral timeout ceiling is 300s.
- **Brand voice is NOT injected by `AiWriterService`/`AiBlockEditorService` directly** —
  those build their own prompts; voice injection rides the `ContextBuilder`/tool path.
- **Snippet-rewriter heredoc gotcha**: identifier is `EBQ_PROMPT_MODE_BLOCK` because a
  plain `MODE` collided with a `MODE:` line in the body and closed the heredoc early.
</content>
