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
   from the site's own GSC (90d). Cached **7 days** (`ai_content_brief_v4:*` — v4
   2026-07-11 added the language cache segment + output-language prompt rule).
   **The project's country/language drive the Serper `gl`/`hl` AND the brief's
   output language** — `generateBrief` hardcoded both to null until 2026-07-11, so
   an Arabic project briefed against the US/English SERP and showed an English
   outline (third layer of the same "input passed but dropped" bug: writer →
   strategy tools → brief).
2. **Strategy** (`generateStrategy`): runs registry tools (`seo-title`, `seo-meta`,
   `seo-description`, `faq-generator`, `keyword-suggestions`, internal/external link
   suggestions) — **multiple LLM calls**, with a retry for meta descriptions when <3
   land in the 120–158 char band. Since 2026-07-11 the bundle also produces:
   - **H1 card** (`h1` / `h1_suggestions` columns): 5 on-page headline options
     (keyword-front, ≤65 chars, in the project's output language) via a direct
     `completeJson` call (wizard-only, deliberately not a Studio catalog tool);
     the user's pick/edit is PATCHed to `h1` and the writer locks onto it.
   - **LSI suggestions** (`lsi_suggestions`): `AiRelatedKeywordsService::suggest`
     (7d cache) — one-click add to `lsi_keywords`.
   - **Keyword data** (`keyword_data`): volume/competition/trend map for every
     surfaced keyword via `KeywordMetricsService::metricsOrQueue` — cache-first
     (shared 30-day `keyword_metrics`), misses queued for the admin-selected
     provider and filled on the next strategy view. Never a blocking fetch, never
     $ projections.
3. **Images** (`searchImages`): Serper image search, flat **1 credit**.
4. **Generate** (`generate` → `AiWriterService::draft` in strict mode): the final draft.
   **Async since 2026-07-11**: both wizard UIs POST generate → the controller queues
   `App\Jobs\GenerateWriterDraftJob` (queue `default`, web-box Horizon; `tries=1` — a
   retry would re-bill the whole article; `timeout=400` < redis `retry_after` 1320) and
   answers **202** with `{generation:{status}}`; clients poll
   `GET …/generate-status` every 4s (returns `generation` + the full project once
   `done`). Lifecycle on `writer_projects`: `generation_status`
   (idle|queued|running|done|failed) + `generation_error` + `generation_started_at`
   (migration `2026_07_11_120000`). `WriterProjectService::generate` persists
   done/failed itself; `failStaleGeneration()` (called from the status endpoints)
   flips rows stuck >10min to failed so pollers never spin forever. The plugin API
   keeps the old blocking path unless the request carries `async=1` (old installed
   plugin versions). The job passes `__user_id` into the LLM call — without it a
   queued generation would be unmetered (no Auth user in a worker).
   Tests: `tests/Feature/WriterProjectAsyncGenerationTest.php`.

### `AiWriterService::draft` — essentially ONE big LLM call

`completeJson`, **temp 0.5, max_tokens 16000, 240s timeout**.
The model emits ALL sections in a single JSON object `{summary, h1, sections[]}`;
there is no outline→sections two-phase (the outline arrives pre-built in the brief).
Heavy PHP post-processing follows: strict-mode strips per-section `<h1>`/`replace`
ops, enforces locked anchors, re-injects dropped manual links, audits LSI usage,
builds schema suggestions, strips dashes. Caps: `MAX_SECTIONS = 20`,
`SECTION_HTML_CAP = 6000` chars (~900 words). Prompt is version **v25** (changelog
in-file); cache key `ai_writer_v25:*` (24h TTL; locale/voice + strategy selections
are part of the key — an Arabic request must never hit a cached English draft).

**v25 — full wizard-input coverage (owner reports 2026-07-11).** The wizard passed
`language/country/tone/audience`, strategy `keyword_suggestions` and curated `faqs`
into `draft()` since day one — and `draft()` silently dropped ALL of them (an
Arabic project wrote English; strategy picks never reached the article). Now:
- **`%OUTPUT_LOCALE%` block**: hard rule to write everything in the picked
  language and TRANSLATE the (SERP-language) brief/PAA; focus keyword +
  additional/LSI phrases + proper nouns stay verbatim. Plus target-market /
  tone / audience lines.
- **`%H1_SPEC%`**: top-level `"h1"` — user's Strategy pick echoed verbatim when
  set, else an SEO-optimized headline (keyword-front, ≤65 chars, output
  language). Persisted to `generated_h1`; consumers use `h1 → generated_h1 →
  title` (dashboard preview/download heading, plugin WP `post_title`).
- **Secondary keywords + curated FAQs blocks**: strategy keyword suggestions are
  woven; user-approved FAQs are REQUIRED members of the consolidated FAQ section
  (which now triggers on PAA *or* curated FAQs).
- **LSI enforcement retry**: `auditLsiUsage` misses used to be a log line — now
  ONE corrective retry replays the draft with the missing phrases called out and
  keeps whichever response covers more. Coverage is persisted to
  `writer_projects.generation_meta` and shown on the review step (both UIs). Then `WriterProjectService::assembleHtml` concatenates sections (no leading
`<h1>` — the WP post title is the H1) and injects `<figure>` blocks by H2 match.
Brief/strategy/images stay synchronous in-request; **the final generate runs as a
queued job** (see step 4 above). On DeepSeek the draft call opts into thinking mode
(`reasoning => true`, budget-capped — see [llm.md](./llm.md)).

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
- **504 footgun: `set_time_limit` is the innermost of three nested timeouts** — Apache's
  proxy_fcgi wait to PHP-FPM and FPM's `request_terminate_timeout` both sit outside it and
  will kill the request first if they're shorter. Fixed 2026-06-20: FPM `request_terminate_timeout`
  120→400 and vhost `ProxyTimeout` 400 added (`ebq.io-le-ssl.conf`) — see
  [server-deployment.md](../server-deployment.md). Any future bump to the writer's
  `set_time_limit`/Mistral timeout must raise these two outer layers to match, or 504s come
  back.
- **Brand voice is NOT injected by `AiWriterService`/`AiBlockEditorService` directly** —
  those build their own prompts; voice injection rides the `ContextBuilder`/tool path.
- **Snippet-rewriter heredoc gotcha**: identifier is `EBQ_PROMPT_MODE_BLOCK` because a
  plain `MODE` collided with a `MODE:` line in the body and closed the heredoc early.
</content>
