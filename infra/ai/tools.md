# AI Studio tool framework

> 47 single-shot tools, each a PHP class, executed through one runner with a
> per-call proprietary-signal context. The plugin sees only the typed result.

## Why this shape

- **Code-defined, not DB-driven** (`AiToolRegistry::TOOL_CLASSES`): missing classes
  fail fast at boot, canonical order is reviewable in VCS, and feature-flagging a
  tool is deleting one line. No filesystem auto-discovery.
- **One execution path** (`AiToolRunner::run`): validation, context, caching, credit
  logging, and error mapping live in one place so 47 tools stay thin.
- **Opt-in context**: a tool that doesn't ask for GSC pays no GSC query — keeps
  cheap utilities (Definition, Sentence) cheap.

## Key components

| Component | File | Role |
|---|---|---|
| Registry | `app/Services/AiToolRegistry.php:31` | Explicit master list (in display order); `all/find/inCategory/forSurface/catalog`. Lazy container resolution. |
| Runner | `app/Services/AiToolRunner.php:52` | Single entry: validate → context → cache → execute → cache → log credits. |
| Tool contract | `app/AiTools/Contracts/AiTool.php:13` | `meta()` + `execute(input, context)`. Stateless; deps via container. Defines `SURFACE_*` and `SIGNAL_*` consts. |
| Base class | `app/AiTools/AbstractAiTool.php:32` | Default skeleton: system prompt + LLM call + normalize + wrap. Most tools just override `meta()` + `buildUserPrompt()`. |
| Tool meta | `app/AiTools/Contracts/AiToolMeta.php:14` | JSON-serialisable: id, inputs, `outputType`, `estCredits`, `surfaces`, `contextSignals`, `cacheTtlSeconds`, `requiresPro`. |
| Result | `app/AiTools/Contracts/AiToolResult.php:20` | Typed by `outputType`; carries usage, model, diagnostics, `ok/error`. |
| Context | `app/AiTools/Contracts/ToolContext.php:17` | Per-call signal bundle; null fields = not loaded. |
| Context builder | `app/Services/Ai/ContextBuilder.php:43` | Loads only the signals the tool opted into. |
| Output normalizer | `app/Services/Ai/OutputNormalizer.php:16` | Coerce raw LLM text → typed value (`text/html/titles/list/table/links/schema/faq/json`). |
| Categories | `app/AiTools/Categories.php:10` | 7 buckets, mirrors RankMath grouping. |

## Tool execution flow (`AiToolRunner::run`)

1. **Resolve** the tool by id; unknown → `unknown_tool` fail (`:54`).
2. **Tier gate**: `requiresPro` tools check `website->effectiveFeatureFlags()['ai_writer']`;
   missing → `tier_required` (`:66`). Controllers map that to HTTP **402** with an
   "Upgrade to <plan>" CTA.
3. **Validate** input against `meta()->inputs` (`:129`): required, type coercion
   (`number/tags/post_picker/select`), `maxLength` clip, `select` option whitelist.
   Pass-through extras (`focus_keyword, country, language, url, post, current_html`)
   survive even if not declared (`:178`).
4. **Cache lookup** when `cacheTtlSeconds > 0` — key `ai_tool:{id}:{websiteId}:{xxh3(input)}`
   (`:207`). Cache hit still logs credits (`:89`).
5. **Build `ToolContext`** via `ContextBuilder::build` (`:103`).
6. **`tool->execute()`** inside try/catch; any throw → `execution_error` (`:107`).
7. **Cache** the result if ok and TTL set; **log credits**.

### Credit accounting (`:213`)

Tokens → EBQ Content Credits at `100 tokens = 1 credit` (`DEFAULT_TOKENS_PER_CREDIT`),
min **1** credit per non-cached run. Logged to `client_activities` as
`credit_usage.ai_tool.{toolId}` with `provider = ebq_content_credits` and
`meta.tool_id` so per-tool usage is queryable. Cached hits with 0 tokens charge 0.

## `AbstractAiTool` (the common skeleton)

`execute()` (`AbstractAiTool.php:73`):
- Bails `llm_not_configured` if the client is unavailable.
- **System prompt** (`buildSystemPrompt`, `:149`) = `Guardrails::base()` + brand-voice
  block (loaded for every tool) + `SeoAnalysisBlock` (only if `SIGNAL_SEO_ANALYSIS`) +
  tool `systemAddendum()` + `Guardrails::json()` when `expectsJson()`.
- **Block shape** (`BlockShape::from`): when the plugin says which Gutenberg block the
  result lands in (`core/heading` etc.), a shape constraint is appended so a "Change
  Tone" on an `<h2>` doesn't return a paragraph.
- **LLM call** via `LlmClient::complete` (or `+['json_object'=>true]`). Default
  `llmOptions()` (`:191`): `temperature 0.5`, `max_tokens 1500`, `timeout 60`.
- **Parse** → `OutputNormalizer::parse(outputType, raw)` (overridable per tool).
- **Defensive shaping** on the way out: `clipForBlockShape` (clip prose to a single
  heading/button/list line) + `stripDashes` (em/en/`--` → comma/period; recursive over
  nested arrays, code/pre preserved).
- LLM error codes (`mistral_http_429/401/5xx`, `*_api_key_missing`, `*_network_error`)
  are mapped to actionable UI copy (`llmErrorMessage`, `:48`).

## Context signals (the moat)

`ContextBuilder` (`app/Services/Ai/ContextBuilder.php:43`) loads, opt-in per
`meta()->contextSignals`:

| Signal const | Source | Loaded when |
|---|---|---|
| `SIGNAL_GSC` | `SearchConsoleData` top queries / clusters (28d) | always-on for tools that request it |
| `SIGNAL_BRIEF` | `AiContentBriefService::cachedBrief` (cache-only) | + focus keyword |
| `SIGNAL_TOPICAL_GAPS` | `TopicalGapService::analyze` | + ≥200 chars body text |
| `SIGNAL_ENTITIES` | `EntityCoverageService` | + url |
| `SIGNAL_RANK_SNAPSHOT` | `rank_tracking_snapshots` (latest) | + focus keyword |
| `SIGNAL_INTERNAL_LINKS` | GSC token-overlap pull (90d, top 10 pages) | + focus keyword |
| `SIGNAL_NETWORK_INSIGHT` | `NetworkInsightService::forKeyword` | + focus keyword |
| `SIGNAL_PAGE_AUDIT` | latest `PageAuditReport` | + url |
| `SIGNAL_SEO_ANALYSIS` | computed in-PHP from `current_html` (kw density, headings, Flesch grade, missing entities) | html or focus kw present |
| `SIGNAL_SITE_INTEL` | `CrawlReportService::pageIntel` (crawler) | + url |

**Brand voice is loaded for ALL tools** — one indexed lookup, the single biggest
differentiator (`ContextBuilder.php:53`). Everything here is server-side only.

## Prompts & guardrails

`app/AiTools/Prompts/Guardrails.php:25` — universal system fragment prepended to every
tool: topic-lock, no-code-output, **no em/en-dashes (hard rule)**, human-voice rules
(banned AI jargon: delve/leverage/tapestry/robust/seamless…), and editor-portable HTML
tag whitelist (no `<div>`, inline styles, `<script>`, `<iframe>`). `Guardrails::json()`
adds the strict-JSON addendum. `BrandVoiceBlock` and `SeoAnalysisBlock` render the
respective context into prompt text; `BrandVoiceBlock` redacts nothing in-prompt but the
plugin-facing summary hides `signature_phrases`/`avoid_phrases`.

## Output types

`OutputNormalizer` recovers from the four ways an LLM mangles structured output (code
fences, preamble prose, JSON-object-wrapping of arrays, trailing commas).
`unwrapArray` (`:211`) unwraps `{"items":[…]}` / `{"faqs":[…]}` style wrappers that
`response_format: json_object` forces (top-level arrays are forbidden in that mode).
Types: `text, html, titles, list, table, links, schema, faq, json`.

## HTTP surfaces

- **Plugin API** — `app/Http/Controllers/Api/V1/AiToolController.php`; routes
  `GET /ai/tools`, `GET /ai/tools/{id}`, `POST /ai/tools/{id}/run` (`routes/api.php:187`).
  Bearer-resolved website. `GET /ai/tools` returns `AiToolRegistry::catalog()`.
- **Dashboard** — `app/Http/Controllers/AiStudioController.php`; session-resolved
  website (`current_website_id`), behind team `ai_studio` permission. `run()` calls the
  same runner; tier failures → **402**, other errors → 422. `@set_time_limit(360)` per
  run.

## Gotchas

- **Cache key ignores `userId`** but includes `website->id`; results are shared across a
  website's team members. Fine because tools are website-scoped, not user-scoped.
- **`stripDashes` is whitespace-bounded** — the code-block stash is a documented no-op;
  unspaced dashes inside identifiers are left alone, so code samples (which guardrails
  forbid anyway) are safe in practice (`AbstractAiTool.php:287`).
- **`requiresPro` reuses the `ai_writer` flag**, not a dedicated `ai_studio` flag — all
  Studio tools gate on the same plan feature.
- **Validation truncates over-length input silently** (`maxLength` clip) rather than
  erroring — keep tool `maxLength` generous.
</content>
