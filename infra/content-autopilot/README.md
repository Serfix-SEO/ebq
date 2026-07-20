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
| 3 | Publish drivers (app-password + webhook) + live-URL verify | ✅ shipped 2026-07-18 (staging; WP-plugin v2.1 driver deferred) |
| 4 | Ideogram images end-to-end (featured + inline, WP sideload) | ✅ shipped 2026-07-18 (staging) |
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
| Production | `app/Services/Content/ContentArticleProducer.php` | brief (`AiContentBriefService`, fail-soft) → write (**reuses `AiWriterService::draft` v25**, sections assembled, humanizer-cleaned) → score → targeted revise loop (only failing checks sent; stops at target / max iterations / delta ≤ +2) → final `deAiCleanup()` prose pass (only if integrity tells) that is **kept only if it does not regress the score** (2026-07-19: was previously accepted at merely `publish_floor`, so the de-AI rewrite silently diluted density / dropped links / stripped a heading keyword and shipped a lower score) → ready or failed (`publish_floor`). |
| Referee | `app/Services/Content/ContentSeoScorer.php` | Pure, VERSION 1. Weighted checks: keyword placement (title/H1/meta/first-100/slug), structure, density band 0.5–2.5%, secondary coverage ≥80%, **internal links validated against real `website_pages` URLs**, readability, title-uniqueness, style. Weights renormalize when context is missing. **Long-tail fairness (2026-07-19):** the product targets question-style 4-6-word keyphrases, which can never hit exact-phrase density/distribution without robotic repetition. So (a) density = occurrences × **phraseWords** ÷ total (keyword-word share, not verbatim runs); (b) `kw_distribution`/`secondary_coverage` use `keyphrasePresent()` — exact for ≤2-word phrases, **all-words-present** (order-flexible, Yoast-style) for 3+ words; distribution needs 2/3 thirds for 4+ word phrases (3/3 for short); (c) `kw_in_a_heading` scans **H2 AND H3**; (d) `meta_title_length` acceptable 40-60 (sweet spot 50-60); (e) readability/style are **advisory weights** (`sentence_length` 2, `paragraph_length` 1, `title_power_word` 1, `style_clean` **4** — was 10) so a well-optimised article clears 90 even with minor prose nits. Style is still HARD-gated separately by the revise loop (`hasStyleIssue`), not by this weight. A compliant long-tail article now scores 93-96. |
| Humanizer | `app/Services/Content/HumanizerService.php` | promptRules() (hard style contract: dash ban, ~60 banned phrases from `ContentAutopilotConfig::bannedPhrases()`, sentence-variance rules) + clean() (mechanical strip) + lint() (deterministic tells → feeds scorer `style_clean` weight 4). NO external AI-detector APIs by design. |
| Jobs | `app/Jobs/{PlanContentTopicsJob,ProduceContentArticleJob}.php` | queue `content` on **redis-long** (heavy pool, retry_after 3900); tries=1 (retries re-bill LLM); unique per plan/topic; `failed()` marks the topic. |
| Dispatcher | `app/Console/Commands/ContentAutopilotDispatcher.php` | `ebq:content-autopilot` every 15 min: reap stuck (>45 min), top-up thin calendars (<7 future topics), claim due topics (48h write-ahead, ONE in-flight per website, 5/tick, ContentLlmSpendMeter gate), then a **catch-up pass** (`catchUpImminent()`) that force-dispatches anything due within **24h** with no article yet — bypasses the one-per-site/5-per-tick throttle (auto-approves imminent SUGGESTED first; entitlement blocks still apply; per-tick dedup avoids double dispatch). |

## Client UI (Phase 2, 2026-07-17; Calendar/Settings split 2026-07-18)

Sidebar has a "Content" group with two pages, both backed by the SAME
`app/Livewire/Content/ContentCalendar.php` component distinguished by a
`mode` prop (`'calendar' | 'settings'`, passed via `<livewire:... mode="…">`):

- **Content Calendar** — `/content` (`Route::view` + `mode="calendar"`,
  `feature:content` middleware). Shows the **calendar only** (month grid +
  list toggle, approve/skip/retry/reschedule). **No pause + no add-topic** —
  planning is always on (a paused plan is reactivated on load; `pauseOrResume`
  removed 2026-07-19) and topics come only from the planner. Cross-month move:
  List view has a per-topic date picker (grid drag is same-month). A READY
  article whose images are still generating shows **"Finalizing images…"** (not
  "Ready for review") and hides Review/Publish — driven by the
  `content:images:pending:<articleId>` cache flag (set at image dispatch in
  `ProduceContentArticleJob`, cleared on every `GenerateContentImagesJob` exit).
  If no plan exists yet, shows a lightweight empty state ("No content plan yet").

- **Scheduling: planner fills one-article-per-day** — `ContentTopicPlanner::scheduleDates()`
  skips any day the plan already has a topic on. The USER may stack a 2nd article
  on a day via the calendar-icon date picker (grid) / date input (list) — manual
  reschedule is unrestricted (any non-past date). `ContentTopicPlanner::plan()`
  keeps a **fixed pool of `cap` (30) UNPUBLISHED topics** — both the planner cap
  and the dispatcher top-up count every topic not published/skipped (planned +
  in-flight + ready), so it never piles past 30 while articles are being written.
  Publishing one drops the pool; the next tick adds exactly the shortfall (one
  in, one out). `THIN_CALENDAR_TOPICS = 30`.
- **Monthly cap = `content.limits.monthly_articles_per_website` (default 30, was 60).**
  Calendar marks the cap-th article + beyond red (grid border, list badge) with an
  info banner; generation past the cap is blocked by `ContentEntitlements::blockReason`
  (`monthly_limit`). Trial cap (`trial_articles`, default 3) shows a "Subscribe"
  banner on the calendar once hit (CTA → `content.get-started`).
- **Featured image on the review page:** generated even when the "in article"
  toggle is off (it's the WP thumbnail); `ArticleReview` surfaces it above the
  preview with a note when it isn't embedded in the body.
  **Grid interactions (2026-07-19):** every generatable topic card carries a
  small "Write" button (`writeNow()` → dispatch + redirect to the detail page
  where live progress renders); topic cards are `draggable` and each day cell is
  a drop target that calls `reschedule(topicId, date)` (Alpine `dragId` on the
  grid wrapper, per-cell `over` highlight). Same reschedule guard as the list
  (suggested/approved/ready only; past dates rejected).
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
  codes to client-safe copy), a full live plugin-style check list
  (`checkLabel` per scorer code, re-scored on edit), search-result preview,
  Approve → `scheduled`. Tenancy via `accessibleWebsitesQuery()`.
  **2026-07-19 additions:** (a) "What this article is worth" card —
  `ArticleReview::trafficWorth()` → a FAIR conservative monthly-visitor estimate
  from `keyword_volume` (headline = low end via `ContentCalendar::
  fairMonthlyVisits`, e.g. 550 searches → ~8 extra visits/mo; never a best case,
  never a $ figure per [[no-dollar-projections-ui]]); (b) a note that articles
  publish as classic HTML and the client converts to Gutenberg blocks in WP
  themselves (SEO fields + images carry over). Check labels updated to the
  current bands (title 40-60, meta 130-155, density 0.5-2.5%).
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

## Publishing (Phase 3, 2026-07-18)

`app/Services/Content/Publishing/`:
- **`PublishDriver`** interface (`verify`/`publish`/`update`) + `PublishResult`
  DTO (`ok`, `externalId`, `externalUrl`, `error`, `transient`) +
  `PublishDriverFactory` (platform → driver; plugin/shopify return null =
  deferred).
- **`WordPressAppPasswordDriver`** — WP core REST (`/wp/v2/users/me` verify
  incl. `edit_posts` capability check, `/wp/v2/posts` create, `/posts/{id}`
  idempotent update). Credentials `{site_url, username, app_password}`;
  config `{post_status, seo_plugin}`. SSRF-guarded via `SafeHttpGuard`.
  When the **Serfix WP plugin** is present (detected by probing `/wp-json/`
  for the `ebq/v1` namespace at verify time; cached as `config.seo_plugin`),
  publish also fills the plugin's on-page self-check meta via the REST `meta`
  field — `_ebq_title` (meta_title), `_ebq_description`, `_ebq_focus_keyword`
  (topic target keyword), `_ebq_additional_keywords` (≤5 secondary, JSON),
  all registered `show_in_rest`. Plugin-ABSENT sites get NO `_ebq_*` meta —
  WP rejects unregistered protected meta and it would fail the whole publish.
- **`WebhookDriver`** — POSTs full article JSON signed
  `X-Serfix-Signature: sha256=<hmac(raw body, secret)>` (same convention our
  keyword-finder INBOUND webhook verifies); expects 2xx, honors optional
  `{url}` response for the live link; retries live in the job, not the driver.
  Credentials `{endpoint_url, secret}`.

**`PublishContentArticleJob`** (queue `content`, tries=3 backoff 60/300 —
publishing is idempotent so retrying is safe, unlike the tries=1 LLM jobs):
claims the unique `content_publications` (article, integration) row BEFORE
any HTTP; a row carrying `external_id` routes retries through `update()`
(never double-posts); topic transitions SCHEDULED → PUBLISHING → PUBLISHED
when ≥1 integration confirms, FAILED only when all hard-fail, released back
to SCHEDULED on transient-only failures. **No connected integration = topic
WAITS in SCHEDULED** (not a failure) and flushes automatically after
connect. Post-publish verify: SSRF-guarded GET of the live URL, 200 + H1 +
no noindex → `verified_at` (best-effort).

**Dispatcher block 4** (`ContentAutopilotDispatcher::claimPublishable()`):
active plans with ≥1 CONNECTED integration, inside the plan's publish window
(`publish_days` ISO weekdays + `publish_hour_start..end` band, wrapping
supported, in `plan.timezone`): auto_publish plans first promote READY
topics whose `review_hours` veto window (anchored `stage_started_at`)
elapsed → SCHEDULED; then ONE due SCHEDULED topic per plan per tick is
dispatched (steady 15-min drip matches the 1/day cadence).

**Connect UI** — `App\Livewire\Content\PublishingSettings` ("Where your
articles publish" card under the wizard on /content/settings): WordPress
app-password form (with in-WP how-to copy) or webhook form (endpoint +
min-16-char secret, integration contract described inline), live
`verify()` before flipping to `connected`, re-check/disconnect actions,
hands-off (auto_publish) toggle. Secrets go through the encrypted cast,
are never echoed back, and never appear in plaintext in the DB (test-
asserted). Status constants now on ContentIntegration/ContentPublication.

Deferred from the original Phase-3 spec: the WP-plugin v2.1
`POST /wp-json/ebq/v1/content` receive endpoint + publish-secret minting in
`WordPressConnectController` (separate plugin release cycle), and
featured-image sideload (no `content_images` rows exist until Phase 4).

## Images (Phase 4, 2026-07-18)

**Cap: 2 images/article (2026-07-19)** — featured + 1 inline. Driven by
`content.images.max_inline` default **1** (owner cap); `featuredImageEnabled()`
adds the featured on top. Admin can still raise max_inline to 4.

`GenerateContentImagesJob` (queue `content`, tries=1 — images bill real
money, retries would double-charge): chained from `ProduceContentArticleJob`
right after the READY transition (gated on `ContentAutopilotConfig::
imagesEnabled()`). ASYNC — never blocks publish; every failure mode (images
off, Ideogram unconfigured, `IdeogramSpendMeter` cap hit, generate/download
error) degrades to "article without images".

Flow per article: an LLM art-direction call (`llmPrompts()`, stage pin
`content.model.image_prompts`) writes a content-aware featured (hero) prompt +
one per non-boilerplate H2 (FAQ/takeaways skipped), tailored to the article
topic + `business_description` — niche-agnostic (photoreal for real-world
businesses, illustration for digital/gaming/abstract; featured may carry a
title-text overlay, inline stays text-free; no logos/watermarks/brands). Falls
back to a deterministic prompt per item when the LLM is unavailable. Capped at
`maxInlineImages` inline. For each: `IdeogramClient::generate`
(num_images=1) → `download()` the short-lived URL → `Storage::disk('public')
->put('content/images/{ulid}.png')` → `ContentImage` row (status generated)
→ `IdeogramSpendMeter::add(cost)`. Then inject `<figure class="content-image">`
into `$article->html`: featured after any leading TOC nav, each inline right
after its section's `<h2 id>` (the anchor the scorer already stamps).

**Alt text is keyphrase-driven, not decorative:** featured alt = the focus
keyphrase, each inline alt = one of the additional keyphrases — so images
also raise the WP plugin's on-page topical-coverage signal (its image-alt
bonus), lifting the coverage cap that text alone couldn't clear.

**WordPress sideload** (`WordPressAppPasswordDriver::sideloadImages`): on
publish, every generated image is uploaded to `/wp/v2/media` (raw bytes +
Content-Disposition), the featured one sets `featured_media`, and inline
`<img src>` are rewritten from our storage URL to the WP-hosted one so
published posts never hotlink our disk. Best-effort — a media failure leaves
the post text intact. Alt text is set on each media entry too.

Config (`ContentAutopilotConfig`, live-flippable via `settings`):
`content.images.enabled` / `.featured_enabled` / `.max_inline` (0-4, def 2) /
`.rendering_speed` (TURBO) / `.style_type` (AUTO); cap
`services.ideogram.monthly_cap_usd`. Review preview renders the figures
(`.ca-preview figure.content-image` styling in article-review.blade).

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
