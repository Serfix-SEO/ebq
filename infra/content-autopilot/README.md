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
| 2 | Client calendar UI + setup wizard + plan gating | ✅ shipped (staging) |
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

## Client UI (Phase 2, 2026-07-17; Calendar/Settings split 2026-07-18)

Sidebar has a "Content" group with two pages, both backed by the SAME
`app/Livewire/Content/ContentCalendar.php` component distinguished by a
`mode` prop (`'calendar' | 'settings'`, passed via `<livewire:... mode="…">`):

- **Content Calendar** — `/content` (`Route::view` + `mode="calendar"`,
  `feature:content` middleware). Shows the **calendar only** (month grid +
  list toggle, approve/skip/retry/reschedule/add-topic, pause/resume). If no
  plan exists yet, shows a lightweight empty state ("No content plan yet")
  linking to Settings instead of rendering the wizard inline.
- **Settings** — `/content/settings` (new route, `content.settings`,
  `mode="settings"`). **Always** renders the 5-step wizard: first use creates
  the plan (unchanged flow below); revisiting later re-opens the SAME wizard
  to edit an already-**active** plan's business profile / offerings.
  `toHowItWorks()` preserves the existing plan's `status`, cadence, and
  toggle fields on save (`ContentCalendar::toHowItWorks`) — Settings must
  NEVER silently demote a live plan back to draft. `dropTopic()` also gained
  a status guard so reopening the wizard's "first articles" step can't
  accidentally skip an already-published topic.
- `/content/topics/{topic}` → `livewire:content.article-review`: preview
  (script/`on*` attributes stripped), quality ring (`reports/charts/ring`),
  plain-language improvement labels (`ArticleReview::issueLabel` maps scorer
  codes to client-safe copy), search-result preview, Approve → `scheduled`,
  Request-new-draft → re-dispatch. Tenancy via `accessibleWebsitesQuery()`.
- **Gating layers** (landmine, cost 3 test rounds): a new plan feature key
  must be added in FOUR places — `PlanSeeder plan_features`,
  `Plan::FEATURE_KEYS` (featureMap whitelist), `Website::FEATURE_KEYS`
  (effective-flags trim), `Website::FEATURE_DEFAULTS` (which is ALSO the
  global-kill default — `false` there ANDs the feature off platform-wide;
  `content_autopilot` is deliberately `true`).
- Status pills map internal states to neutral copy
  (`ContentCalendar::statusPresentation`) — writing/scoring/revising all
  render as "In progress"; failed renders "Needs attention".
- i18n: 83 en/ar keys. Compiled-CSS audit caveat: grep the bundle with
  ESCAPED colons (`hover\:border-orange-300`) — plain `-F ".hover:…"` false-
  negatives; `min-h-*`/`lg:order-last` are NOT compiled (inline styles used).

## Setup wizard v2 (6 steps, 2026-07-17; keyword-research step added 2026-07-18)

`ContentCalendar` Livewire component drives a 6-step wizard:
1. **Business** — brand (guessed from domain), article language, auto-detected
   description (`SiteProfileExtractor`, wire:init spinner).
2. **Offerings** — multi-item sell / don't-sell lists (add/remove/reorder/
   inline-edit), auto-filled from the site profile. On Continue creates a
   **DRAFT `ContentPlan`** (`ContentPlan::STATUS_DRAFT`) and dispatches
   `PlanContentTopicsJob` — topic ideation runs in the BACKGROUND while the
   user reads the next steps.
3. **How it works** — 3-step explainer (research → daily article → traffic;
   the reference's backlinks step is deliberately omitted).
4. **Competitors & authority** — `ContentSetupInsights`: your referring domains
   vs competitor **median + gap multiplier** (reference-style "13.6×") + a
   top-3 competitor table (favicon, referring domains, **Moz DA/PA**). Reads
   the shared report snapshot (read-only), enriches each competitor's
   referring-domains count via **OpenPageRank free bulk** (snapshot competitor
   rows lack it), caches the whole result 30 days. When no usable snapshot
   exists, `ensureGenerating()` FORCE-dispatches the standard paid report ONCE
   (spend-metered; sandbox on staging; guarded once/30min) and step 4 polls
   (`refreshCompetitors`) until it lands. Graceful "analyzing" / "appears
   shortly" states otherwise.
   - **Moz DA/PA** (2026-07-18, owner request): every competitor row (auto AND
     manual) is enriched via `MozLinksClient::urlMetrics()`, 30-day cached
     **per domain** (`content:moz:{host}`, independent of the insights cache
     so add/remove doesn't cost extra calls) and guarded by a new
     `MozSpendMeter` (mirrors `ContentLlmSpendMeter`, `services.moz.
     monthly_row_cap` default 40) — Moz's account is free-tier (50 rows/month
     total, shared with the client report's own-domain gauge call), so this
     must stay small. Not configured / cap exhausted → renders "—", zero
     HTTP calls (never blocks the page).
   - **Manual add/remove** (2026-07-18): `ContentPlan.competitor_overrides`
     (`{added:[], removed:[]}`) is merged on top of the cached snapshot by
     `ContentSetupInsights::withOverrides()` at render time — never written
     into the 30-day cache itself. Works even before a report snapshot exists,
     so a manually-added competitor shows immediately. `ContentCalendar::
     addCompetitor()/removeCompetitor()` persist directly (same
     immediate-write pattern as `dropTopic()`); domain input validated
     host-shaped + rejects the user's own site, capped at 8 manual entries.
   - **Reset / Refetch** (2026-07-18): removing every competitor previously
     left no way back. A toolbar above the table now offers **Refetch**
     (`refreshCompetitors()` — clears the 30-day insights cache so the next
     render recomputes from the current report snapshot) and **Reset**
     (`resetCompetitors()` — clears `competitor_overrides` entirely,
     restoring the plain auto-discovered list; only shown when overrides
     exist). A distinct empty state ("you've removed every competitor")
     appears when the merged list is empty due to the user's own edits,
     separate from the "still generating" state.
   - **Moz DA/PA is a global asset, not feature-local** (2026-07-18): stored
     on `domain_metrics.moz_da`/`moz_pa`/`moz_refreshed_at` (30-day
     freshness) instead of a `ContentSetupInsights`-only cache — the SAME
     table CC/OPR ranks live on (see the Data asset section of
     `AUTO_CONTENT_CALENDAR_PLAN.md` / authority-scores work). Any subsystem
     touching a domain (backlinks, prospecting, another wizard run for a
     different site) reuses the stored value instead of re-calling Moz,
     which matters given the 50-rows/month free tier.
   - **Competitor referring-domains switched from OpenPageRank to DataForSEO**
     (2026-07-18): owner compared a getautoseo.com screenshot against ours
     for the same site and found the numbers wildly different (nickfinder.com:
     90 in our app vs ~5,800 there) — root cause was mixing the site's own
     ACCURATE DataForSEO-sourced referring-domains figure against
     competitors' figures sourced from free OpenPageRank, which undercounts
     10-100x vs any real backlink index. A live side-by-side pull (OPR vs
     Moz vs real DataForSEO) confirmed Moz and DataForSEO agree within the
     same order of magnitude; OPR was the outlier every time. Switched to
     `DataForSeoBacklinkClient::summary()` (`/backlinks/summary/live`,
     $0.024/domain) — same endpoint/methodology as the site's own number —
     guarded by the existing app-wide `DataForSeoSpendMeter`, stored on
     `domain_metrics.dfs_referring_domains`/`dfs_backlinks`/
     `dfs_refreshed_at` (30-day freshness, same global-asset pattern as
     Moz). Also yields a real backlinks total per competitor (new
     "Backlinks" table column) for free in the same call. `OpenPageRankClient`
     dependency removed from `ContentSetupInsights` entirely.
   - **Admin-owned sites sandbox DataForSEO calls** (2026-07-18, same-day
     follow-up): the new DFS lookup had no billing-policy check, unlike the
     existing report-generation path (`ensureGenerating()` already
     sandboxes admin-owned sites). Fixed: `build()`/`withOverrides()`/
     `metricsForDomain()` all thread `$sandbox = $website->user?->is_admin`
     through to `dfsMetrics()`, which routes to DataForSEO's free mock host
     and — critically — never persists the mock response into the shared
     `domain_metrics` asset or charges the spend meter (sandbox data must
     never contaminate the real-data cache other users' lookups rely on).
     Caught a real, unrelated staging misconfiguration while verifying this:
     staging's `.env` had `DATAFORSEO_FORCE_SANDBOX=false` (contradicting
     its own inline comment and `infra/reference/staging.md`'s documented
     isolation guarantee) — ~$0.28 real spend had already accrued this
     month. Owner's call: intentional — QA (non-admin) accounts should see
     real data on staging; only admin accounts need sandboxing, which is
     exactly what this app-level fix implements (no staging env change made).
5. **Keyword research** (2026-07-18) — `ContentKeywordInsights`: the client-
   facing digest of the research behind their plan. Background flow:
   `PrepareContentKeywordInsightsJob` fires at the end of step 2 (alongside
   topic ideation) and dispatches an UNMETERED ideas request to the
   self-hosted keyword server (`KeywordFinderPool::dispatchIdeas`, seeds =
   short heads of the confirmed sell-offerings + GSC striking-distance
   queries, capped 20). Minutes-long turnaround at concurrency 1 — hence the
   early dispatch; step 5 polls (`wire:poll.5s`). Once complete: topic
   clusters (LLM labels via `AiKeywordClusterService`, monthly-cached;
   `KeywordTermGrouper` fallback), intent mix (deterministic
   `KeywordIntentClassifier`, plain-language labels), audience questions,
   volume×competition opportunity picks with "In your calendar" badges,
   30-day cached per plan (`content:kw-insights:v1:{planId}`). Completed
   research also backfills `ContentTopic.keyword_volume`. **Degradation:**
   failed/absent/overdue (12-min grace) server → insights built from the
   plan's own topics + cached `keyword_metrics` volumes, marked `partial`
   (7-day cache) — never dead-ends. Staging exercises exactly this path
   (no `keyword_api_servers` row there; fleet webhooks point at prod).
6. **First articles** — the background-generated topics (`wire:poll.4s` until
   ready), removable; **Launch** flips the plan to active and article writing
   begins (the dispatcher only claims ACTIVE plans, so nothing bills during the
   draft window). Baked defaults: 1 article/day, ~2,000 words (cadence step +
   CTA field removed on owner request).

Resume: `bootWizard()` reloads an existing plan (draft OR active) on mount
and jumps past the offerings step for drafts. Whether the wizard renders is
now driven by `mode`, not plan status — see the Calendar/Settings split above.

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

## Staging QA (2026-07-17, real DeepSeek + real GSC topics)

3 articles end-to-end on pubgnamegenerator.net: two scored **87/100** (1409 and
1866 words, 0 em-dashes, 1-2 minor style flags, ~60-90s each); one broad-keyword
topic parks at 67-69 (v4-pro overwrites 3000+ words vs target → word_count check
fails → below-target score; floor + review-first gating handled it as designed).
Cost ≈ $0.06–0.10 per article. Landmines encoded in code/comments:
- **`deepseek-chat` alias resolves to v4-FLASH** which truncates the 16k-token
  article JSON (`llm_parse_failed`) — write/revise default is hard-pinned to
  `deepseek-v4-pro` (`ContentAutopilotConfig::modelFor`), admin pin wins.
- The reviser must be HANDED the real site-URL list or it invents internal
  links (scorer rejects them) — same "passed-but-dropped" class as writer v25.
- Length rules must be bidirectional (expand under / tighten over) — a
  one-sided "never shorten" made v4-pro balloon 1500→3046 words.
- Open Phase-2 item: length adherence on broad keywords (section budget or
  band widening for hub topics).

## Env (staging QA values 2026-07-17)

Staging `.env` gained real `DEEPSEEK_API_KEY`/`MISTRAL_API_KEY` (copied from
prod) + `IDEOGRAM_API_KEY`, with `CONTENT_LLM_MONTHLY_CAP_USD=1` and
`IDEOGRAM_MONTHLY_CAP_USD=1`. Production values land at prod-deploy time
(cap proposals: LLM $25, images $30). Both boxes + staging must carry the vars
(worker-box env-drift landmine).
