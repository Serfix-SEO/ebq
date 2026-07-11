# LLM plumbing

> One provider-agnostic contract (`LlmClient`), one shared OpenAI-dialect base
> (`OpenAiCompatibleClient`), two implementations: `MistralClient` and
> `DeepSeekClient`. The active provider + default model per provider are
> admin settings. Token metering, cost, and the function-calling loop all
> live here.

## Contract & binding

`app/Services/Llm/LlmClient.php` is the provider-agnostic interface:

| Method | Purpose |
|---|---|
| `complete($messages, $options)` | Chat completion → `{ok, content, model, usage, tool_calls, message, error?}`. |
| `completeJson($messages, $options)` | Force JSON mode + tolerant decode; returns `null` on parse fail (never throws in an editor save path). |
| `completeWithTools($messages, $tools, $dispatcher, $options)` | Function-calling loop; dispatcher runs each tool call, result fed back as a `role:tool` message; caps rounds. |
| `isAvailable()` | True when the API key is set. |

**Note the undocumented-but-load-bearing keys**: `complete()` returns `tool_calls`
and `message` in addition to the docblock shape — `completeWithTools` replays them.
Any new client must return them.

Bound in `app/Providers/AppServiceProvider.php` via
`LlmClientFactory::make()` (`app/Services/Llm/LlmClientFactory.php`) — the factory
reads `LlmProviderConfig::currentProvider()` and builds the matching client with
`config('services.{provider}.key')` + `AiModelConfig::currentModel($provider)`.
Deliberately `bind()` (per-resolve), **not** singleton: an admin provider/model flip
takes effect on the next request without a deploy. `LlmClientFactory::make('mistral')`
pins a provider regardless of the setting (used by the alt-text vision fallback).

**Failure philosophy**: never throw. Every path returns `ok:false` with an error code so
call sites fall back to non-LLM behaviour without crashing the editor.

## Providers

### Shared base — `OpenAiCompatibleClient`

`app/Services/Llm/OpenAiCompatibleClient.php` holds *all* behavior (complete /
completeJson / completeWithTools / tolerant decode / metering). Subclass hooks:
`endpoint()`, `providerKey()` (drives error-code prefix, `api_usage.{key}` activity
type, and the UsageMeter provider string — stored in `client_activities.provider`,
keep stable), `defaultTimeout()`, `maxOutputTokensCap()`, `prepareBody()`.

| Option | Default | Notes |
|---|---|---|
| `temperature` | 0.2 | per-call sites override |
| `max_tokens` | (unset) | passed through when present; clamped to the provider cap |
| `timeout` | provider default | clamped **2–300s**; writer/JSON jobs pass their own |
| `json_object` | — | sets `response_format: {type: json_object}` |
| `tools` / `tool_choice` | — | OpenAI-style function calling; `tool_choice` default `auto` |
| `model` | client default | per-call override always wins |

HTTP uses `->retry(2, 250, throw:false)`. Errors return codes
`{provider}_api_key_missing`, `{provider}_network_error`, `{provider}_http_{status}`.
`AbstractAiTool::llmErrorMessage` and `WriterProjectService::briefErrorMessage` map
these to UI copy by **suffix pattern** (`_http_429`, `_network_error`, …) — provider
neutral, don't reintroduce `mistral_`-literal matching.

### MistralClient

`app/Services/Llm/MistralClient.php` — thin subclass. Endpoint
`https://api.mistral.ai/v1/chat/completions`, default `mistral-small-latest`,
timeout default 12s. Error codes byte-identical to the pre-refactor client
(regression-tested in `tests/Unit/Llm/MistralClientTest.php`).

### DeepSeekClient

`app/Services/Llm/DeepSeekClient.php` — endpoint
`https://api.deepseek.com/chat/completions`, default `deepseek-chat`,
timeout default **30s** (slower first token). Provider quirks, all handled in-client:

- **Output-token clamp: 32k** (`maxOutputTokensCap()`, clamped silently + logged).
  The V3-era API 400'd above 8,192 and the client originally clamped there — which
  silently truncated the AI Writer's 16k budget on DeepSeek. Live-probed
  2026-07-11: V4 accepts ≥32k, clamp raised; the writer's 16k passes through
  intact.
- **JSON mode requires "json" in the prompt** — `prepareBody()` appends a one-line
  system nudge when no message mentions it.
- **No vision model** — alt-text pins Mistral (see below).
- **Thinking mode ("DeepThink"/"Think" in the DeepSeek app)** — per-call option
  `reasoning => true` → request body gets `thinking: {type: enabled}`, reply
  carries `reasoning_content` next to the answer. **V4 models think BY DEFAULT
  when the flag is omitted**, so `prepareBody()` always sends the flag
  (`disabled` unless the call opts in) — found live 2026-07-11 when a
  blog-post-wizard brief burned its whole 1500 `max_tokens` on
  `reasoning_content` and returned empty content (`llm_parse_failed`): reasoning
  tokens count against `max_tokens`. For the same reason, enabled thinking always
  carries `budget_tokens` (4096, `REASONING_BUDGET_TOKENS`) — unbounded thinking
  consumed the writer's entire output allowance (second live incident same day,
  "generate article" spun then failed). Verified live on V4:
  thinking mode **does** support `json_object` and function calling (old R1
  `deepseek-reasoner` restrictions are gone); reasoning tokens bill through
  `usage.total_tokens` normally. There is no "think-max" API model — the live
  `/models` list is `deepseek-v4-flash` / `deepseek-v4-pro` (the `deepseek-chat`
  and `deepseek-reasoner` aliases resolve to v4-flash, the latter with thinking
  forced on). **Routing rule: thinking only where quality beats latency** — today
  that is exactly one call site, the AI Writer full-article draft
  (`AiWriterService`, 240s budget). Every interactive path (block editor, chat,
  snippet rewriter, briefs, Studio tools, insight extraction) stays non-thinking:
  their 8–90s timeouts and plugin-side limits can't absorb reasoning latency, and
  reasoning tokens would bill on every small op. Mistral ignores the flag.
- **Reasoner-family aliases are denylisted** from the admin model dropdown — not
  because they're broken (they aren't, on V4) but because an always-thinking
  platform default would slow and inflate every small call. Thinking is opt-in
  per call site via `reasoning`, never via the default model.

## Provider & model selection

- **`LlmProviderConfig`** (`app/Support/LlmProviderConfig.php`) — Setting
  `ai.llm.provider` (`mistral` default | `deepseek`), same pattern as
  `KeywordProviderConfig`. `isConfigured($provider)` = key present; the admin
  settings form refuses activating a keyless provider.
- **`AiModelConfig`** (`app/Support/AiModelConfig.php`) — all statics take
  `?string $provider = null` (null = active provider):
  - `currentModel($p)`: Setting (`ai.llm.model` for Mistral — legacy key, prod rows
    keep working; `ai.llm.model.deepseek` for DeepSeek) →
    `config('services.{p}.model')` → provider literal.
  - `listAvailableModels($p)`: live `/models` fetch per provider, cached 1h per
    provider (`ai_model_config:available_models:v3:{provider}`), Mistral rows
    filtered to chat-capable + alias-expanded; DeepSeek's `/models` has **no
    capabilities/aliases fields** and gets the reasoner denylist. Known-good
    fallback lists keep the dropdowns usable when unreachable.
  - `premiumModel($p)`: `mistral-medium-latest` / `deepseek-chat` — the tier used by
    the ex-hardcoded call sites (block-editor title, snippet rewriter, related
    keywords).
  - `visionModel($p)`: `pixtral-12b-latest` / `null`.
  - `clearModelsCache()` clears both providers' lists.
- **Admin UI**: `/admin/settings` (`PlatformSettingsController`) — provider select +
  a model select per provider (`model` field = Mistral for form back-compat,
  `deepseek_model` = DeepSeek), each validated against that provider's live list.

### Models in use

| Model | Where |
|---|---|
| active provider's `currentModel()` | platform default; most Studio tools, briefs, writer, chat |
| `AiModelConfig::premiumModel()` | block-editor **title** mode, snippet rewriter, related keywords |
| `pixtral-12b-latest` (always Mistral) | block-editor **alt_text** with an image URL (vision) |

**Vision fallback**: DeepSeek has no vision model, so when it's active the
alt-text path in `AiBlockEditorService` pins `LlmClientFactory::make('mistral')` +
Pixtral (spend still lands in the pooled cap, logged as provider `mistral`). No
Mistral key → graceful `{ok:false, error:'vision_not_supported'}`.

## Token & credit accounting

Two distinct meters:

1. **`UsageMeter`** (`app/Services/Usage/UsageMeter.php`) — per-user monthly cap on paid
   external APIs, anchored to the subscription start day. The client calls
   `assertCanSpend($user, providerKey(), estimatedTokens)` pre-flight (throws
   `QuotaExceededException` when over) and logs real spend after the call as
   `api_usage.{provider}` (`units_consumed = total_tokens`). The billed user is
   `__user_id` in options, else the auth user, else nobody (background jobs aren't
   billed). Pre-flight estimate = `prompt_chars/4 + max_tokens`.

   **LLM tokens are ONE pool**: `mistral` and `deepseek` both map to the plan cap
   `plans.api_limits.mistral.monthly_tokens` (legacy key name; admin plans UI labels
   it "AI tokens / month"), `consumedInWindow()` sums across both providers, and the
   in-flight reservation key canonicalizes to `mistral`. This is deliberate — a
   platform-wide provider flip mid-month must not reset or bypass anyone's quota
   (per-provider `where provider = X` windows would restart from zero). Activity
   rows still record the real provider, so `/admin/usage` splits cost by provider
   while the per-client utilisation table shows one pooled "AI tokens" column.
2. **EBQ Content Credits** — the user-facing metric, logged separately by the tool runner
   (`100 tokens = 1 credit`) and the writer (`400 chars = 1 credit`) under provider
   `ebq_content_credits`. See [tools.md](./tools.md) and [writer.md](./writer.md).

## Config / env (non-secret)

`config/services.php`:

| Key | Env | Default |
|---|---|---|
| `mistral.key` | `MISTRAL_API_KEY` | — (secret) |
| `mistral.model` | `MISTRAL_MODEL` | `mistral-small-latest` |
| `mistral.cost_per_million_input_usd` | `MISTRAL_INPUT_USD_PER_M` | 0.10 |
| `mistral.cost_per_million_output_usd` | `MISTRAL_OUTPUT_USD_PER_M` | 0.30 |
| `deepseek.key` | `DEEPSEEK_API_KEY` | — (secret) |
| `deepseek.model` | `DEEPSEEK_MODEL` | `deepseek-chat` |
| `deepseek.cost_per_million_input_usd` | `DEEPSEEK_INPUT_USD_PER_M` | 0.27 |
| `deepseek.cost_per_million_output_usd` | `DEEPSEEK_OUTPUT_USD_PER_M` | 1.10 |
| `deepseek.cost_per_token_usd` | `DEEPSEEK_COST_PER_TOKEN_USD` | 0.0000011 (admin Usage page rate) |
| `serper.key` | `SERPER_API_KEY` | — (secret; SERP for briefs/strategy/images) |

Per-user LLM cap is a **plan** value (`api_limits.mistral.monthly_tokens` — shared by
both providers), not an env var. Provider + model overrides live in the `settings`
table (`ai.llm.provider`, `ai.llm.model`, `ai.llm.model.deepseek`), not config.

## Tolerant JSON & the tool loop

(Shared, in `OpenAiCompatibleClient`.)

- **`tolerantJsonDecode`** recovers from markdown fences, leading/trailing
  commentary, a bare object embedded in prose, and trailing commas: strict decode →
  strip fences → extract first balanced `{…}` → strip trailing commas → decode
  again. `OutputNormalizer::unwrapArray` then unwraps `{"items":[…]}` wrappers.
- **`completeWithTools`**: `max_tool_rounds` clamped **1–6** (default 4). Each
  round: `complete()` with the tools array; tool calls dispatched (dispatcher throw
  → `{error:tool_failed}` fed back); loop ends on a tool-free final reply or
  `max_tool_rounds_exceeded`. `json_object` is stripped in this mode — shape is
  controlled by the system prompt. Used by `AiChatService`.

## Gotchas

- **Timeout ceiling is 300s**, floor 2s. A bare `complete()` without an explicit
  `timeout` defaults to the provider's default (Mistral 12s, DeepSeek 30s) — too
  short for anything large, so writer/brief sites pass their own.
- **CLI vs FPM opcache** (see `CLAUDE.local.md`): editing these PHP files needs a full
  `php8.3-fpm` restart; tinker compiles fresh and will mislead you.
- **`__user_id` / `__website_id` / `__source` options** are billing/telemetry hints
  consumed by the client, stripped before the HTTP body. Queue jobs and webhooks must
  pass `__user_id` explicitly (no auth user in those contexts) or the call goes unbilled.
- **phpunit blanks `MISTRAL_API_KEY` + `DEEPSEEK_API_KEY`** (same landmine class as
  the 2026-07-11 Keywords Everywhere leak) — never remove; tests needing HTTP use
  `Http::fake()` + a fake key via `config()`.
- **WordPress plugin needs no change on provider flips** — all plugin AI calls go
  through the main-app API. Its per-call timeouts were tuned to Mistral latency;
  watch the snippet-rewrite/brief paths after switching to DeepSeek.
