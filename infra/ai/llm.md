# LLM plumbing

> One provider-agnostic contract (`LlmClient`), one implementation today
> (`MistralClient`). Model choice, token metering, cost, and the
> function-calling loop all live here.

## Contract & binding

`app/Services/Llm/LlmClient.php` is the provider-agnostic interface — written so
service code can be swapped per task by binding a different implementation:

| Method | Purpose |
|---|---|
| `complete($messages, $options)` | Chat completion → `{ok, content, model, usage, error?}`. |
| `completeJson($messages, $options)` | Force JSON mode + tolerant decode; returns `null` on parse fail (never throws in an editor save path). |
| `completeWithTools($messages, $tools, $dispatcher, $options)` | Function-calling loop; dispatcher runs each tool call, result fed back as a `role:tool` message; caps rounds. |
| `isAvailable()` | True when the API key is set. |

Bound in `app/Providers/AppServiceProvider.php:41` to `MistralClient`, constructed with
`config('services.mistral.key')` and `AiModelConfig::currentModel()`. Services that want
a different provider for one endpoint (the doc cites Claude for copywriting in a future
phase) can inject the concrete class instead of the interface.

**Failure philosophy**: never throw. Every path returns `ok:false` with an error code so
call sites fall back to non-LLM behaviour without crashing the editor.

## MistralClient

`app/Services/Llm/MistralClient.php`. Endpoint
`https://api.mistral.ai/v1/chat/completions` (`:23`), default model
`mistral-small-latest` (`:24`). OpenAI-compatible request shape.

| Option | Default | Notes |
|---|---|---|
| `temperature` | 0.2 | per-call sites override |
| `max_tokens` | (unset) | passed through when present |
| `timeout` | 12s | clamped **2–300s** (`:55`); writer/JSON jobs pass their own |
| `json_object` | — | sets `response_format: {type: json_object}` |
| `tools` / `tool_choice` | — | OpenAI-style function calling; `tool_choice` default `auto` |
| `model` | client default | per-call override always wins |

HTTP uses `->retry(2, 250, throw:false)`. Errors return codes `mistral_api_key_missing`,
`mistral_network_error`, `mistral_http_{status}` — `AbstractAiTool::llmErrorMessage` maps
these to actionable UI copy (429 rate-limit, 401/403 bad key, 5xx upstream).

### Tolerant JSON (`tolerantJsonDecode`, `:313`)

Recovers from the four ways a JSON-mode model still returns un-parseable text: markdown
fences, leading/trailing commentary, a bare object embedded in prose, and trailing
commas. Strategy: strict decode → strip fences → extract first balanced `{…}` → strip
trailing commas → decode again. `OutputNormalizer::unwrapArray` then unwraps
`{"items":[…]}`-style wrappers (top-level arrays are forbidden in json_object mode).

### Function-calling loop (`completeWithTools`, `:165`)

`max_tool_rounds` clamped **1–6** (default 4). Each round: `complete()` with the tools
array; if the model returns tool calls, the assistant turn is replayed and each call is
dispatched (dispatcher throw → `{error:tool_failed}` fed back); loop ends on a tool-free
final reply (decoded tolerantly) or `max_tool_rounds_exceeded`. `json_object` is stripped
in this mode — shape is controlled by the system prompt instead. Used by `AiChatService`.

## Model selection (`AiModelConfig`)

`app/Support/AiModelConfig.php`. **Resolution order** (`currentModel`): admin Setting
`ai.llm.model` → `config('services.mistral.model')` → literal `mistral-small-latest`.
Per-call `model` options still win over this platform default.

- **Admin dropdown** (`listAvailableModels`): pulled live from Mistral's
  `/v1/models` endpoint, filtered to chat-capable models, alias variants expanded
  (so an admin can pin `mistral-small-2506` = "Mistral Small 3.2" instead of the moving
  `-latest` alias), deprecated models flagged. Cached **1h** (`:33`). Known-good
  `FALLBACK_MODELS` list keeps the dropdown usable when the API is unreachable.
- `setModel` / `clearModelsCache` persist the choice and invalidate the list cache (call
  after an API-key change).

### Models in use

| Model id | Where |
|---|---|
| `mistral-small-latest` (Small 3.2) | platform default; most Studio tools, briefs, writer |
| `mistral-medium-latest` | block-editor **title** mode, snippet rewriter, related keywords |
| `pixtral-12b-latest` | block-editor **alt_text** with an image URL (vision) |

## Token & credit accounting

Two distinct meters:

1. **`UsageMeter`** (`app/Services/Usage/UsageMeter.php`) — per-user monthly cap on paid
   external APIs, anchored to the subscription start day. `MistralClient` calls
   `assertCanSpend($user, 'mistral', estimatedTokens)` pre-flight (throws
   `QuotaExceededException` when over) and logs real spend after the call as
   `api_usage.mistral` (provider `mistral`, `units_consumed = total_tokens`). The
   billed user is `__user_id` in options, else the auth user, else nobody (background
   jobs aren't billed). Plan cap path: `plans.api_limits.mistral.monthly_tokens`
   (null = unlimited). Pre-flight estimate = `prompt_chars/4 + max_tokens`.
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
| `serper.key` | `SERPER_API_KEY` | — (secret; SERP for briefs/strategy/images) |

Per-user Mistral cap is a **plan** value (`api_limits.mistral.monthly_tokens`), not an
env var. Admin model override lives in the `settings` table (`ai.llm.model`), not config.

## Gotchas

- **Timeout ceiling raised 60→300s** for slow JSON-mode / large-output writer jobs; floor
  2s. A bare `complete()` without an explicit `timeout` defaults to **12s** — too short
  for anything large, so writer/brief sites pass their own.
- **CLI vs FPM opcache** (see `CLAUDE.local.md`): editing these PHP files needs a full
  `php8.3-fpm` restart; tinker compiles fresh and will mislead you.
- **`__user_id` / `__website_id` / `__source` options** are billing/telemetry hints
  consumed by `MistralClient`, stripped before the HTTP body. Queue jobs and webhooks must
  pass `__user_id` explicitly (no auth user in those contexts) or the call goes unbilled.
- **Only `MistralClient` is bound.** The interface's multi-provider story (Claude/GPT for
  copywriting-heavy endpoints) is aspirational — no other client class exists yet.
</content>
