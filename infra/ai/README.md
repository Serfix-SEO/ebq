# AI suite

The EBQ AI suite is everything that turns an LLM into SEO-aware content. Three
surfaces sit on one **provider-agnostic LLM client** (`LlmClient` — Mistral or
DeepSeek, admin-switchable via `LlmProviderConfig`):

1. **AI Studio tools** — 47 single-shot, code-defined tools (Research / Writing /
   Improvement / Marketing / Ecommerce / Media / Misc). Catalog-driven; the
   WordPress plugin renders forms from tool metadata and never sees the prompt.
2. **AI Writer** — a multi-step wizard (topic → brief → strategy → images → draft)
   that produces a full, section-by-section blog post in essentially one big LLM
   call, plus brand-voice and SEO-honouring post-processing.
3. **Rank Assist chat** — a function-calling SEO chatbot that reasons over live
   editor state + EBQ signals and proposes one structured, Apply/Discard action
   per turn.

The **moat** is server-side: the plugin only ever receives the typed result. It
never knows which model ran, what the prompt was, or which proprietary signals
(GSC clusters, crawl intel, brand voice, network insight) were loaded.

## Read in this order

| Doc | What it covers |
|---|---|
| [tools.md](./tools.md) | **Start here.** The tool framework: registry, runner, `AbstractAiTool`, `ToolContext`/`ContextBuilder` signals, prompts/guardrails, output normalization, the catalog/run API. |
| [writer.md](./writer.md) | AI Writer pipeline (`AiWriterService`, `WriterProjectService`), brand voice, block editor, content tooling (briefs, snippet rewriter, related keywords), and the chat service. |
| [llm.md](./llm.md) | `LlmClient` contract, providers (`MistralClient`/`DeepSeekClient` on `OpenAiCompatibleClient`), provider switch + model selection (`LlmProviderConfig`/`AiModelConfig`), function-calling loop, pooled token/credit accounting, usage caps, non-secret config + env. |

Crawl-derived signals (`SIGNAL_SITE_INTEL`) come from the crawler's read surface,
`CrawlReportService::pageIntel` — see [../crawler/read-path.md](../crawler/read-path.md);
this doc does not duplicate it.

## One paragraph

Every AI feature flows through `App\Services\Llm\LlmClient`, bound via
`LlmClientFactory::make()` (`app/Providers/AppServiceProvider.php`) to the
admin-selected provider (`LlmProviderConfig`: Mistral default, DeepSeek optional),
default model resolved per provider by `AiModelConfig::currentModel()` (admin
Setting → `services.{provider}.model` → provider literal). Studio tools are PHP classes under `app/AiTools/Tools/*`,
listed explicitly in `AiToolRegistry`, executed by `AiToolRunner` (validate →
build `ToolContext` → cache lookup → `tool->execute()` → cache → log credits). Most
tools extend `AbstractAiTool`, which assembles the system prompt
(guardrails + brand voice + SEO analysis + addendum), calls the LLM, normalizes
output by `outputType`, and strips em/en-dashes as a defensive net. The Writer and
chat services bypass the registry and drive the LLM directly (JSON mode / function
calling) but share the same client, guardrails, and credit accounting.

## Invariants (don't break)

1. **The plugin never sees prompts, model names, or proprietary signals.** Diagnostics
   returned to the plugin must stay non-sensitive (`AbstractAiTool::diagnostics`).
2. **No em-dashes / en-dashes / `--` in any shipped output.** It's the strongest "AI
   tell". Enforced twice: prompt ban (`Guardrails::base`) + post-process strip.
3. **No code output** from content tools (topic-lock guardrail).
4. **Every LLM call is billable.** `OpenAiCompatibleClient` meters tokens through
   `UsageMeter::assertCanSpend` (pre-flight) and logs real spend to `client_activities`;
   Mistral + DeepSeek tokens pool under one plan cap.
5. **Tools never call each other directly** — composition happens at the controller
   layer (`AiTool` contract).

## Key code

- Framework — `app/Services/{AiToolRegistry,AiToolRunner}.php`,
  `app/AiTools/{AbstractAiTool,Categories}.php`,
  `app/AiTools/Contracts/{AiTool,AiToolMeta,AiToolResult,ToolContext,InputField}.php`,
  `app/AiTools/Prompts/{Guardrails,BrandVoiceBlock,SeoAnalysisBlock,BlockShape}.php`,
  `app/Services/Ai/{ContextBuilder,OutputNormalizer}.php`
- Tools — `app/AiTools/Tools/{Research,Writing,Improvement,Marketing,Ecommerce,Media,Misc}/*.php`
- LLM — `app/Services/Llm/{LlmClient,OpenAiCompatibleClient,MistralClient,DeepSeekClient,LlmClientFactory}.php`,
  `app/Support/{AiModelConfig,LlmProviderConfig}.php`, `app/Services/Usage/UsageMeter.php`
- Writer — `app/Services/{AiWriterService,WriterProjectService,BrandVoiceService,AiBlockEditorService}.php`,
  `app/Services/AiWriter/CustomPromptGuard.php`
- Content tooling — `app/Services/{AiContentBriefService,AiSnippetRewriterService,AiRelatedKeywordsService,AiChatService}.php`
- HTTP — `app/Http/Controllers/{AiStudioController,AiStudioWriterController}.php`,
  `app/Http/Controllers/Api/V1/AiToolController.php` (plugin), routes in
  `routes/web.php` (`/ai-studio/*`) and `routes/api.php` (`/ai/tools/*`)
</content>
</invoke>
