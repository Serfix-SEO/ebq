# Content Autopilot — auto content calendar

> Automatic content pipeline: evidence-driven topic ideation → AI writing →
> **deterministic technical-SEO scoring + revision loop** → (Phase 4: Ideogram
> images) → (Phase 3: auto-publish WP/Shopify/webhook). Competitor benchmark:
> getautoseo.com — full analysis + roadmap in repo-root
> `AUTO_CONTENT_CALENDAR_PLAN.md`. Built 2026-07-17 (Phases 0–1).

## Status (2026-07-17)

| Phase | Scope | State |
|---|---|---|
| 0 | Schema, models, config, spend meters, IdeogramClient, admin settings card | ✅ shipped (staging) |
| 1 | Pipeline core: ideation → write → score → revise loop + dispatcher | ✅ shipped (staging) |
| 2 | Client calendar UI + setup wizard + plan gating | ⬜ next |
| 3 | Publish drivers (WP plugin v2.1 / app-password / webhook) + live-URL verify | ⬜ |
| 4 | Ideogram images end-to-end (client exists; job pending) | ⬜ |
| 5 | Shopify, RSS/JSON feed, translations | ⬜ |

## Data model (migration `2026_07_17_120000`)

One `content_plan` per website (cadence, toggles, business profile,
`custom_instructions` guarded by CustomPromptGuard) → dated `content_topics`
(status machine: suggested → approved → researching → writing → scoring →
revising → ready → scheduled → publishing → published | failed | skipped;
`stage_started_at` anchors the reaper) → versioned `content_articles`
(`is_current` flag, `seo_score`, `seo_issues`, `style_issues`,
`generation_meta`) → `content_images` (per-article, expiring-URL downloads) +
`content_integrations` (**encrypted** credentials cast) + `content_publications`
(unique per article+integration — publish idempotency).

## The pipeline

| Piece | File | Notes |
|---|---|---|
| Ideation | `app/Services/Content/ContentTopicPlanner.php` | GSC striking-distance (pos ≥6, impressions ≥20, 90d) + business profile + existing crawl titles; ONE completeJson; deterministic dedupe (token-overlap ≥0.75 = cannibalization) + cadence date assignment. Degrades without GSC. |
| Production | `app/Services/Content/ContentArticleProducer.php` | brief (`AiContentBriefService`, fail-soft) → write (**reuses `AiWriterService::draft` v25**, sections assembled, humanizer-cleaned) → score → targeted revise loop (only failing checks sent; stops at target / max iterations / delta ≤ +2) → ready or failed (`publish_floor`). |
| Referee | `app/Services/Content/ContentSeoScorer.php` | Pure, VERSION 1. Weighted checks: keyword placement (title/H1/meta/first-100/slug), structure, density band 0.4–2.5%, secondary coverage ≥60%, **internal links validated against real `website_pages` URLs**, readability, title-uniqueness, style. Weights renormalize when context is missing. |
| Humanizer | `app/Services/Content/HumanizerService.php` | promptRules() (hard style contract: dash ban, ~60 banned phrases from `ContentAutopilotConfig::bannedPhrases()`, sentence-variance rules) + clean() (mechanical strip) + lint() (deterministic tells → feeds scorer `style_clean` weight 10). NO external AI-detector APIs by design. |
| Jobs | `app/Jobs/{PlanContentTopicsJob,ProduceContentArticleJob}.php` | queue `content` on **redis-long** (heavy pool, retry_after 3900); tries=1 (retries re-bill LLM); unique per plan/topic; `failed()` marks the topic. |
| Dispatcher | `app/Console/Commands/ContentAutopilotDispatcher.php` | `ebq:content-autopilot` every 15 min: reap stuck (>45 min), top-up thin calendars (<7 future topics), claim due topics (48h write-ahead, ONE in-flight per website, 5/tick, ContentLlmSpendMeter gate). |

## Config & admin

- **Admin settings card** (`/admin/settings`, `PlatformSettingsController`):
  per-stage model pins (`content.model.{ideate|write|revise|image_prompts}`,
  "auto" = platform default with write/revise preferring DeepSeek when
  configured — `ContentAutopilotConfig::modelFor()`), image toggles/counts/
  quality, revision-loop knobs, banned-phrases textarea. All Settings-table
  backed, fail-safe reads (missing table → defaults).
- **Spend circuit-breakers** (clone family of `DataForSeoSpendMeter`, shared
  base `app/Services/Spend/MonthlySpendMeter.php`, fail-open, admin-only):
  `ContentLlmSpendMeter` (Redis `content:llm:spend:Y-m`, cap
  `CONTENT_LLM_MONTHLY_CAP_USD`, flat conservative estimates per call —
  completeJson exposes no usage) and `IdeogramSpendMeter` (`ideogram:spend:`,
  `IDEOGRAM_MONTHLY_CAP_USD`). Exhausted LLM cap ⇒ dispatcher stops claiming
  (dates shift silently); exhausted image cap ⇒ articles publish without images.
- **Ideogram** (`app/Services/Content/IdeogramClient.php`): v3 generate,
  `Api-Key` header, TURBO $0.03/img default; **returned URLs EXPIRE — always
  download in-job**. `IDEOGRAM_API_KEY` in `.env` (phpunit blanks it — billable-
  key landmine class).

## Invariants

1. **Client copy never exposes internals** — no scores-below-floor talk, no
   spend/cap/vendor names. Topics read "Scheduled" / "In progress" /
   "Needs attention".
2. **tries=1 everywhere LLM bills** — requeue is an explicit dispatcher/UI act.
3. **A topic never wedges silently** — every in-flight state has
   `stage_started_at`; the reaper fails rows >45 min.
4. **Article versions are append-only** (`ContentArticle::storeVersion`) —
   the revision audit trail is the QA story.
5. **Publishing is idempotent** by the `content_publications` unique key —
   drivers must upsert by `external_id` (Phase 3).

## Testing

`tests/Unit/Content/{ContentSeoScorerTest,HumanizerServiceTest}.php` (pure,
fixture-exact) + `tests/Feature/Content/ContentAutopilotPipelineTest.php`
(end-to-end with `Http::fake`'d LLM: full produce loop, revision versions,
reaper, claim rules, spend-cap gate). Seed `PlanSeeder`; no Serper key in
tests exercises the brief-less degradation path deliberately.

## Env (staging QA values 2026-07-17)

Staging `.env` gained real `DEEPSEEK_API_KEY`/`MISTRAL_API_KEY` (copied from
prod) + `IDEOGRAM_API_KEY`, with `CONTENT_LLM_MONTHLY_CAP_USD=1` and
`IDEOGRAM_MONTHLY_CAP_USD=1`. Production values land at prod-deploy time
(cap proposals: LLM $25, images $30). Both boxes + staging must carry the vars
(worker-box env-drift landmine).
