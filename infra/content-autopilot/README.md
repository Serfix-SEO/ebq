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
- **Wizard analyzes ONE competitor** (`ContentKeywordInsights::MAX_COMPETITORS = 1`,
  2026-07-20). The keyword server is concurrency-1, so seed + own domain + 3
  competitors = 5 serialized requests choked the step ("stuck on competitor
  analysis"). Now just seed + own + top competitor; a prominent step-6 note tells
  the client deeper competitor research continues in the background per-article.
  (The earlier DataForSEO clickstream volume-enrichment path was removed — it
  billed per batch and stalled the step.)
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

## Site types — the offer spine (Phase A, 2026-07-23)

`content_plans.site_type` (nullable enum, migration `2026_07_23_090000`) +
`site_type_source` (`auto|user`) + `audience` (one line, ≤500). The taxonomy
and ALL per-type behavior live in `app/Support/ContentSiteTypeProfiles.php`
(pure config: intent weights, query shapes, guard default, CTA style, voice,
TOFU/MOFU/BOFU mix): `blog | affiliate | brand | ecommerce_reseller |
local_service | saas | tool | b2b_services | nonprofit | other`.
(`tool` added 2026-07-23 #5: free browser tools/generators — the
namesforfreefire/pubgnamegenerator archetype fit nothing and flip-flopped
other/saas between runs. Utility query shapes ("free {offer}", "{offer}
ideas"), guard `protect` (traffic IS the product), CTA `trial` framing,
informational-heavy mix. Classifier prompts got explicit tool-vs-saas hints:
free-no-signup ⇒ tool, sign-up/pay ⇒ saas. Verified live: namesforfreefire
reclassified `tool` on first try.)

(2026-07-23 #6 — three more types + per-site YMYL: `creator`
(person-is-the-brand; first-person voice, CTA "course or newsletter"),
`marketplace` (two-sided platforms/directories; find/compare/price shapes,
CTA "browse the listings"), `education` (paid courses; the old "Nonprofit /
education" chip conflated a course academy with a charity — nonprofit is now
"Nonprofit / charity"). All three guard-default `protect`. Plus
**`content_plans.ymyl`** (nullable bool, migration `2026_07_23_120000`):
classifier-assessed, TYPE-INDEPENDENT — the extractor prompts return a
`ymyl` boolean and the writer's CARE rule fires on `profile.ymyl_care OR
plan.ymyl === true`, including for type-blind null-type plans. No UI —
silently persisted by both wizard hosts + the backfill command (never
clobbers an existing value). Live-verified: a freelance-writing coach
profile → `creator`/ymyl false; a UAE dentist-booking platform →
`marketplace`/**ymyl true** — exactly the health-subject-on-non-health-type
case the flag exists for. 13 chips in the step-1 picker — near the UI
ceiling; next type addition should rethink the layout.)

- **Detection**: `SiteProfileExtractor` returns `site_type` + `audience` from
  the SAME single LLM call (no extra spend); invalid enum values become null.
  Profile cache key bumped `content:site-profile:v1:` → **`:v2:`** (old
  entries lack the new keys and age out naturally). Remember the 7-day
  empty-profile cache when retesting a failed site — bust `:v2:` now.
- **Confirmation**: wizard step 1 renders a chip selector (shared partial);
  `selectSiteType()` exists on BOTH hosts (`ContentWizard` trait +
  `ContentCalendar`) — a click records `site_type_source='user'`, which
  re-detection/backfill NEVER overwrites. Persisted at step 2 alongside the
  profile (`toHowItWorks` twins + `saveSettings`).
- **Null = type-blind**: every consumer must treat null/`other` exactly like
  the pre-site-type pipeline. Classification failure is a degradation, not an
  error.
- **Backfill**: `ebq:content-classify-plans {--dry-run} {--limit=50}` — one
  flash call per already-profiled plan (stored text only, NO page fetches,
  `Http::assertSentCount(1)`-tested), spend-metered, idempotent, skips
  user-classified rows. Run dry-run first; failures stay null and retry.

Tests: `SiteProfileExtractorTest` (enum validation, backfill classification),
`ContentClassifyPlansCommandTest`, `ContentPublicOnboardingTest` (full-flow
persists chip choice as `user`).

## Offer-grounded keywords + winnability ranking (Phase B, 2026-07-23)

- **`OfferQueryGenerator`** (`app/Services/Content/OfferQueryGenerator.php`):
  one flash call per plan turns confirmed offers × the type's query shapes ×
  audience into ≤30 buyer-shaped candidate queries, EACH carrying its offer
  lineage. Cache `content:offer-queries:v1:{plan}:{input-hash}` (30d — the
  hash makes offer/type edits self-invalidating). LLM-free fallback fills the
  shapes mechanically, so a plan with offerings NEVER yields []. `attribute()`
  maps server-expanded keywords back to offers by token overlap (≥0.5
  confidence, candidate queries extend their offer's token set).
- **`KeywordWinnability`** (same dir): pure math — `difficultyCeiling(ownDa)`
  (DA band → max winnable KD, unknown DA assumes small site) and
  `score(difficulty, competitionTier, ownDa)` 0..1 (never 0). Own DA read
  from the shared `domain_metrics.moz_da`. Difficulty comes from the DFS
  harvest's `keyword_metrics.keyword_difficulty` — captured since 07-20,
  first actually USED here.
- **`ContentKeywordInsights` changes**: `seeds()` now leads with up to 10
  generator queries (offer heads + GSC still follow, MAX_SEEDS 20 unchanged);
  `build()`'s opportunities are ranked `winnability × intent-weight
  (ContentSiteTypeProfiles)` with volume as TIEBREAK ONLY, and each pick
  carries `origin` (the offer, null when unconfident). Digest cache key
  bumped `content:kw-insights:v1:` → **`:v2:`**. Head terms are never hidden,
  just outranked. `PrepareContentKeywordInsightsJob` timeout 60 → 120 (the
  generator's ≤40s call runs inside `seeds()` on cache miss).
- **Behavior change note**: for type-less plans the 'other' profile weights
  informational ≥ transactional — `test_insights_classify_intent_questions_
  and_opportunities` was updated accordingly (winnability-first is the
  intended new semantics, not a regression).

Tests: `KeywordWinnabilityTest` (unit), `OfferQueryGeneratorTest` (lineage
snap, mechanical fallback, attribution), `ContentKeywordInsightsTest::
test_opportunities_rank_by_winnability_not_volume_and_carry_offer_lineage`.

## Confirmed terms → first articles 1:1 (Phase C, 2026-07-23)

Step 6 now opens with a **"Your best search terms" card** (Google-mockup
teaser + the winnability-ranked opportunity picks as keep/✕ chips, each
showing "because you sell: {offer}"). Crossing a term out is wizard state
(`removedTerms` + `toggleTerm()` — BOTH hosts); leaving the step
(`toFirstArticles()`, both hosts) calls the SHARED
`ContentKeywordInsights::confirmTerms($plan, $removed)`:

- Kept opportunities upsert into `content_plan_keywords` as
  **`ContentPlanKeyword::TYPE_CONFIRMED`** (stored value `'chosen'` — the
  `type` column is string(8), 'confirmed' wouldn't fit; do NOT widen the
  column, use the constant). Flipping an existing gap row is safe (unique
  plan+hash; the classifier uses insertOrIgnore so it never crashes back).
- `ContentTopicPlanner::materializeConfirmedTopics()` then creates exactly
  ONE topic per confirmed term — deterministic (`source='confirmed'`,
  deterministic title via `confirmedTitle()`, no LLM required, runs even when
  `llm->isAvailable()` is false and BEFORE the pool-cap math). Idempotent: a
  term that EVER had a topic (any status) never re-materializes. Pool full →
  the farthest-out unstarted `llm` filler topic is skipped
  (`last_error='superseded_by_confirmed_term'`) and the confirmed topic takes
  its calendar slot. Cap 10 per run.
- Research still pending on step-6 exit → `confirmTerms` returns 0, the
  transition never blocks (terms can still be confirmed on a Settings
  revisit).
- Step 7 badges confirmed topics "Your pick"; planner source whitelist for
  LLM candidates unchanged (LLM output can never claim `confirmed`).

Tests: `ContentConfirmedTermsTest` (6 — materialize/idempotent/supersede/
wizard persist/pending-safe/gap-flip).

## Peer competitor split + honest gap (Phase D, 2026-07-23)

`ContentSetupInsights::withOverrides()` now ends in `withPeerClasses()` — a
READ-TIME overlay (never cached: it depends on the guard classification and
manual overrides, both of which can land after the 30-day snapshot). Each
competitor row gains `class`:

- `reference` — in the guard's `competitor_guard['references']` list;
- `peer` — rival in the client's weight class: |DA delta| ≤ 25 when both DAs
  known (own DA preferred from `domain_metrics.moz_da`, falling back to the
  citation score), else referring domains ≤ 5× the client's, else (no data)
  **peer** — unknown must never quietly demote a real rival. A weaker rival
  is also peer (only clearly-above rivals are `aspirational`).
- `aspirational` — real rival, clearly above the weight class.

Payload adds `peer_median` / `peer_gap` / `peer_behind` (same math, PEERS
ONLY; falls back to the all-rows figures when nothing classifies). The old
`median`/`gap`/`behind` are untouched for other readers. Zero new API calls —
pure re-labeling of data already fetched.

Step-5 UI: stats lead with the peer median/gap; the table shows peers +
aspirationals ranked by referring domains **with the client's own "YOU" row
inserted in rank position** (references are out of that ladder, so the old
directories-on-top failure cannot return); aspirationals get an "Ahead of
you" chip; references collapse into an "Also on your results pages" chip
section explicitly excluded from gap math. Fresh sites keep SERP order, no
YOU row.

Tests: `ContentPeerClassesTest` (3).

## Guard evolution (Phase E, 2026-07-23)

- **Policy modes** — `CompetitorMentionGuard::mode($plan)`: explicit
  `guard['mode']` wins, else the site type's `guard_default`
  (brand/local/saas/b2b → `protect`; affiliate → `brands_required`; reseller
  → `stocked_only`; blog/nonprofit → `off`; null type → `protect` = exact
  pre-mode behavior). `modeBlocks()` gates BOTH `termsForTopic()` (an
  affiliate's articles may name any brand even with the toggle on) and
  assess()'s auto-enable (never auto-protect an affiliate/blog).
  `stocked_only` additionally drops any blocked term appearing in the
  sell-offerings — a reseller's stocked brands are the content; only
  competing retailers stay blocked.
- **Aliases** — the SAME classify call now returns ≤4 aliases per blocked
  brand ("uc" for Urban Company); stored on `auto[i].aliases`, merged into
  `terms()`, removed together with their parent brand. No extra LLM cost.
- **Value counter** — `guard['stats'].articles_checked/mentions_removed`,
  bumped by `ContentArticleProducer` after READY (checked when the guard was
  active; removed when an earlier version carried the `competitor_mentions`
  lint and the final doesn't). Rendered on the guard card ("2 articles
  checked · 1 competitor mention removed"). Fail-soft — a lost tick never
  fails an article.
- **Retroactive scan** — `ScanPublishedForBlockedTermsJob` (dispatched by
  `addTerm()`; unique per plan, deterministic, no LLM): lints PUBLISHED
  articles' current version against blocked terms/domains → stamps
  `topic.meta['brand_safety']` (and clears stale flags). Flags only — never
  edits published content.
- **UI** — guard card gains an "Allowed as sources" reference-chip group
  (block-anyway button per chip → `blockReference()` on BOTH hosts, via the
  new public `brandForDomain()`), plus the counter line.

Tests: `ContentGuardEvolutionTest` (7); the pre-existing
`CompetitorMentionGuardTest` (24) is untouched and green.

## Type-aware writing (Phase F, 2026-07-23)

Instruction-text only — the chunked write protocol, scorer, and revise loop
are untouched:

- `ContentArticleProducer::siteTypeRules()` (in `templateInstructions()`):
  VOICE per the type profile (personal / brand-"we" / friendly-professional /
  professional / warm), AUDIENCE line from `plan.audience`, and a CARE rule
  when the profile sets `ymyl_care` (b2b_services). `ctaFraming()` refines
  the existing CTA rule per `cta_style` (product/category/contact/trial/
  consultation/subscribe/support) — only when the client already enabled a
  CTA URL; nothing is added otherwise.
- `ContentTopicPlanner::ideate()` gains a SITE TYPE block: the profile's
  TOFU/MOFU/BOFU `article_mix` as percentage guidance + the audience line.
- Null/unclassified site type adds ZERO text in both places — byte-identical
  prompts to the pre-site-type pipeline (test-asserted).

Tests: `ContentTypeAwareWritingTest` (4).

## Brand-hygiene round (2026-07-23 #2 — the kayali onboarding vs getautoseo)

Side-by-side of our live step 6 against the competitor's for kayali.com
exposed five gaps; all fixed same day, deployed prod + staging:

1. **Candidates now surface in "best search terms" with real volumes.**
   The opportunities gate (`volume > 0`) silently excluded every
   `OfferQueryGenerator` candidate the keyword server didn't price. Now:
   `ContentKeywordInsights::enrichCandidateMetrics()` (called in
   `ensureStarted()`, i.e. inside the queued job) prices candidates via the
   NEW `DataForSeoBacklinkClient::keywordOverview()`
   (`/dataforseo_labs/google/keyword_overview/live`, ≤50 kw/call, spend-
   metered, admin-sandboxed, mock never persisted) into the shared
   `keyword_metrics` asset (`dfs_labs`, 30d TTL, insert-if-missing). The
   digest merges candidates as first-class opportunity rows (volume/difficulty
   from the asset; null volume allowed) with exact offer lineage and a
   volume-aware fit factor — ×1.25 when priced with real demand, ×1.1 when
   unpriced, **×0.4 when DFS priced it ZERO** (offer-true-but-unsearched must
   sink; live round #2 had six vol=0 candidates crowd out a 5,500/mo term) —
   plus a mix guard of ≤4 candidates among the 6 picks so the list stays
   anchored in observed demand. `labsGeo()` on the client is the geo map
   (twin of `HarvestDomainKeywordsJob::geoFor` — keep in sync).
2. **Guard-blocked brand keywords purged from the WHOLE digest** (rows + gap)
   — the guard used to forbid writing about Sephora while the digest
   recommended "sephora birthday gift sets" and grew a 1,633-keyword
   "Sephora Perfume" pillar.
3. **Own brand is never a competitor, never blocked, never an opportunity.**
   `CompetitorMentionGuard::ownBrandToken()` (domain-derived, ≥4 chars) —
   filters `competitorDomains()` (kayaliofficial.shop was classified a rival
   of kayali.com), `terms()` (safety net vs stale assessments — works without
   reassessing), and the digest's opportunities (brand searchers already
   found the site; own-brand demand stays visible in pillars/searches).
4. **"Est. monthly traffic" sums PEER-class competitors only** (Phase D
   classes via `withOverrides`) — summing Sephora+Kohl's ETV had produced a
   74M headline for a niche brand. The per-competitor table is unchanged.
5. **`avoid_patterns` per site type** (`ContentSiteTypeProfiles`): brand
   sites down-weight ×0.2 affiliate-shaped queries (`reviews?`, `comparison`,
   `vs`) — "perfume reviews" belongs to affiliates, not a brand's own blog.

Behavioral note: candidates joining opportunities also means `confirmTerms`
persists them → `ContentConfirmedTermsTest` expectations were relaxed
accordingly (contains/not-contains instead of exact list).

Tests: `ContentBrandHygieneTest` (5).

## DFS demand discovery (2026-07-23 #3 — hybrid sourcing)

Three-way comparison (our step 6 vs getautoseo vs a raw-DFS simulation)
showed DFS `keyword_suggestions` seeded from offer heads finds bigger real
demand than both ("best vanilla perfume" 12,100/mo for kayali). Now a THIRD
candidate source feeding the SAME ranking pipeline:

- **`DataForSeoBacklinkClient::keywordSuggestions()`** — one call per offer
  head (≤5 offers × ≤30 rows, ~$0.08/onboarding), volume-ordered. The
  endpoint reports KD=0 where it has none → normalized to null (competition
  float tiers those rows instead; never treat "no data" as "trivially easy").
- **`ContentKeywordInsights::ensureDfsSuggestions()`** (in `ensureStarted()`,
  queued): token-signature dedupe (one query cluster arrives as several
  word-order variants), demand floor 50/mo, cap 8/offer & 40 total, then the
  SAME `llmRelevantItems` vetting questions/gap use (kills cross-brand drift
  like "nespresso exclusive offers"; fails open). Candidates cached per plan
  30d (`content:kw-dfs-sugg:v1:{plan}:{raw-offers-hash}`); metrics persisted
  into shared `keyword_metrics` (sandbox never persists/bills). `build()`
  merges them with the LLM/mechanical candidates — dedupe by query, first
  source wins.
- **`OfferQueryGenerator::isPromoOffer()`** — promo-mechanics offerings
  ("Exclusive offers", "Complimentary samples with orders") are HOW a shop
  sells, not WHAT; they are never used as query seeds by either the
  generator or the suggestions pass (LLM vetting can't catch these — the
  junk offer is in the plan's own offerings, so the model believes it).
  The real fix is the client editing their offerings; this is the pipeline
  defense.

Live kayali result: best signature perfumes (110, lineage) · signature scent
perfumes (390) · best scents (5,500) · top 10 luxury perfume brands (550) ·
layering-collection candidate — zero junk. Caveat: the 12,100/mo vanilla
term only flows once the plan's offerings name the actual product line
(this plan's stored offers predate the v2 extractor); fresh onboardings get
it automatically.

Tests: `ContentBrandHygieneTest::test_dfs_suggestions_discover_demand_and_
join_best_terms_with_lineage` (dedupe, demand floor, lineage, tiering,
shared-asset persist).

## Signal-based giant detection + entity types (2026-07-23 #4, LIVE TEST)

The static GiantDomains list is precision-high/recall-bounded — sephora and
kohls walked through it for kayali.com. Now a signal layer catches unlisted
giants, **flag-gated for instant no-deploy revert**:

    Setting::set('content.giant_signals.enabled', false)   // ← the revert
    (default true; mind the Setting cache-bust landmine)

- **`GiantDomains::isScaleGiant()`** — listless, from stored metrics only:
  organic keyword count > 500k, OR DA ≥ 70 AND ≥ 20× the client's referring
  domains, OR (client unknown) DA ≥ 75 AND > 100k refs. Conservative on
  purpose — a merely-larger niche rival never demotes.
- **Entity types** — the guard's SAME classify call now tags every domain
  `brand|retailer|marketplace|directory|media|service|other`
  (`guard['entities']`, `entityFor()`). Retailer/marketplace/directory/media
  ⇒ giant-class regardless of scale.
- **`CompetitorMentionGuard::isGiantClass()`** is the single authority
  (entity OR scale); consumed by (a) `rankAndFilter()` — giants sink to the
  END of the research order, never dropped, so a client whose ONLY SERP
  competitors are giants still gets research; (b) `withPeerClasses()` —
  class `giant`, grouped with references in the step-5 collapsed section,
  excluded from peer math automatically.
- **Peer stats honesty**: zero peers now yields peer_median/gap **null**
  ("—"), NOT a fallback to the all-rows median (which re-imported the
  giant-skewed 12,294/30× the split exists to kill).
- **`brandFromDomain()` rewritten**: the last hostname label is ALWAYS
  dropped as a TLD (the old known-TLD allowlist turned unknown TLDs into the
  brand: kayaliofficial.shop → "shop", so the own-brand filter missed it and
  the client's own shop won the research slot). Own-brand variants are now
  also filtered from `rankAndFilter()` candidates and from the displayed
  competitor rows.

Live kayali end-state: retailers all `giant` (collapsed), directories
`reference`, own shop domain gone, peer stats honestly "—" (its SERP cache
holds no brand peer yet — a future SERP refresh supplies them), research
falls back to giants as designed.

Tests: `ContentPeerClassesTest` (7 — incl. flag-off reverts to prior
behavior, scale rule, entity demotion, research-slot demotion).

## Setup wizard v2 (6 steps, 2026-07-17; keyword-research step added 2026-07-18)

`ContentCalendar` Livewire component drives a 6-step wizard:
1. **Business** — brand (guessed from domain), article language, **target
   country** (2026-07-22 — `KeywordFinderLocations::countryOptions()`
   dropdown, auto-guessed from the domain's ccTLD via `ContentCalendar::
   detectCountryFromDomain()` / the `ContentWizard` trait twin; generic-use
   TLDs like .co/.io/.me are deliberately never guessed), auto-detected
   description (`SiteProfileExtractor`, wire:init spinner).
2. **Offerings** — multi-item sell / don't-sell lists (add/remove/reorder/
   inline-edit), auto-filled from the site profile. On Continue creates a
   **DRAFT `ContentPlan`** (`ContentPlan::STATUS_DRAFT`) and dispatches
   `PlanContentTopicsJob` — topic ideation runs in the BACKGROUND while the
   user reads the next steps.
3. **How it works** — 3-step explainer (research → daily article → traffic;
   the reference's backlinks step is deliberately omitted).
4. **Competitors & authority** — `ContentSetupInsights`. **Source order
   flipped 2026-07-22 (owner decision)**: LAYER 1 is now **SERP discovery**
   (`DiscoverContentCompetitorsJob` → `ReportEnrichmentService::
   discoverCompetitorsFor`, geo-targeted, cached 30d at
   `content:serp-competitors:{website_id}`) — whoever actually ranks for the
   plan's real target searches, displayed **in SERP tally order** (most
   appearances / best positions first; the old strongest-backlinks re-sort is
   GONE — it's what surfaced directories above real rivals). The backlink
   report snapshot is now only (a) the AUTHORITY side (your referring domains
   vs median + gap multiplier) and (b) the competitor FALLBACK when SERP
   discovery found nothing. **`GiantDomains` filter applies to BOTH sources**
   (expanded 2026-07-22 from 15 to ~90 entries — amazon/ebay/netflix/booking/
   tripadvisor/nytimes-class platforms; extendable, deliberately NOT
   exhaustive, and niche-scale sites stay out because for some client they
   ARE the competitor). Cap raised **top-3 → top-10** (`MAX_COMPETITORS`).
   `ensureGenerating()` dispatches SERP discovery first, paid report second;
   step 4 polls (`refreshCompetitors`) until either lands. Note the Moz
   free-tier math: 10 competitor rows/site against the 40-row monthly meter.
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
   - **Batched competitor traffic estimation** (2026-07-20, owner request):
     once `build()` resolves a plan's competitor list it dispatches
     `App\Jobs\Content\EnrichCompetitorDomainMetricsJob(websiteId, domains)`,
     which makes ONE flat-priced DataForSEO Labs
     `/dataforseo_labs/google/bulk_traffic_estimation/live` call for ALL
     competitor domains at once (`DataForSeoBacklinkClient::bulkTrafficEstimation()`,
     ≤1000 targets/task) and stores "whatever is provided" per domain on the
     shared asset (`domain_metrics.dfs_metrics` JSON + `dfs_metrics_refreshed_at`,
     30-day freshness). Backs the keyword-step "monthly searches" teaser +
     future reuse. Idempotent (fresh domains skipped → repeat dispatch is
     free), async (`content` queue), spend-metered (`DataForSeoSpendMeter`),
     admin-sandboxed (mock data never persisted). Dispatch sits inside
     `build()` (30-day cache miss) so it fires at most once per freshness
     window — no per-poll re-billing.
   - **Keyword GAP via DataForSEO Labs — 3 competitors, monthly accumulation**
     (2026-07-20, `DATAFORSEO_KEYWORD_GAP_PLAN.md`): the competitor gap no longer
     uses the concurrency-1 keyword server. `HarvestDomainKeywordsJob` pulls a
     domain's `ranked_keywords/live` (≤1,000/run, volume-cursor for dupe-free
     month-over-month growth) into the SHARED assets `keyword_metrics` (facts) +
     `domain_keyword_rankings` (domain↔keyword link) with a per-domain
     `domain_keyword_harvest` cursor. `ContentKeywordInsights::ensureHarvest()`
     dispatches the client + top-3 competitors once/domain/month (shared);
     `dfsGap()` overlays the gap fresh each read (competitor rankings MINUS the
     client's, LLM-relevance-vetted). `ebq:content-keyword-harvest` (monthly)
     accumulates +1,000/competitor and rolls to the next competitor when one is
     exhausted. Self-hosted keyword server UNTOUCHED (client seeds + other product).
   - Surfaced as the step-6 **"Est. monthly
     traffic"** stat card (`ContentKeywordInsights::estimatedMonthlyTraffic()`
     → `$kw['traffic']['estimated']`): the SUM of competitors' organic ETV
     from `domain_metrics.dfs_metrics` (`metrics.organic.etv`). Overlaid
     FRESH on every `get()` (even on a cached digest) since the async DFS
     enrichment can land after the digest is first cached; card hides when 0
     (not landed / no DFS data) and is gated behind `$showVolumes` so public
     onboarding keeps it behind the teaser.
5. **Keyword research** (2026-07-18; this doc's step numbering is stale — the
   live wizard has 7 steps, this is actually `wizardStep === 6`, right after
   Competitors at `=== 5`) — `ContentKeywordInsights`: the client-facing
   digest of the research behind their plan. Also where the
   **competitor-mention guard card** now renders (moved off the competitors
   step, 2026-07-22) — the competitor list is only FINAL once the user
   leaves that step, and nothing there guaranteed a job had been queued for
   the current state (only the `needsReportGen`-triggered `wire:init` and
   explicit add/remove actions dispatched `AssessCompetitorGuardJob`) — a
   site with an already-cached competitor report could sit on the
   competitors step with the card polling forever, nothing ever landing.
   `toKeywordResearch()` (the competitors→keyword-research transition)
   already dispatches the job unconditionally when unassessed; the card's
   own `wire:init="loadCompetitors"` (reused, not a new method) is the
   belt-and-braces trigger for anyone who navigates back without re-running
   that transition. `ContentCalendar::render()` computes `$wizard['guard']`
   for `wizardStep === 6` now (was `=== 5`). Background flow:
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
(num_images=1) → `download()` the short-lived URL → `Storage::disk(
ContentImage::disk())->put('content/images/{ulid}.png')` (prod disk is
`content_s3` = Hetzner Object Storage, see the incident below) → `ContentImage`
row (status generated)
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

### ⚠️ "A plan row exists" ≠ "the wizard has run" (prod, 2026-07-21)

Since the billing phase, `ContentEntitlements::coverWebsite()`
(`ContentEntitlements.php:114`) creates a **covered DRAFT stub plan** the moment
a site is activated or a trial starts — long before the user enters anything.
Any code still treating "a ContentPlan row exists" as "onboarding is done" is
now wrong. Two bugs from exactly this, both user-visible:

1. **Auto-detect went dead for every billed site.** `ContentCalendar::analyzeSite()`
   bailed on `plan() !== null`, so the business profile generated on the user's
   FIRST visit (before the stub) and never again — an empty field on every later
   visit with no way to regenerate. `mount()` also hard-set `analyzing = false`
   whenever a plan existed. Both now gate on the **profile** (`blank(
   $this->businessDescription)` / `filled($plan?->business_description)`), not on
   the row. Tests: `ContentPagesTest::test_auto_detect_still_runs_for_a_covered_
   stub_plan` + the skip-once-filled counterpart.
2. **Topic ideation ran on profile-less stubs.** `ContentAutopilotDispatcher::
   topUpThinCalendars()` selects purely on `status = ACTIVE` + topic count, and
   `ContentTopicPlanner` treats `business_description` as optional (`:219` casts
   null to `''`, relevance filter fails open). A stub flipped ACTIVE therefore
   produced a full, plausible GSC-gap calendar for a business it knew nothing
   about — which is why the missing profile went unnoticed for a day.

**Rule:** to ask "has this user onboarded?", check `filled($plan->
business_description)` (or `$plan->topics()->exists()`), never `$plan !== null`.

**Both fixed 2026-07-21.** `PlanContentTopicsJob::handle()` now returns early
(logging `content_autopilot.topics_skipped_no_profile`) when
`business_description` is blank — an empty calendar is the honest state, and the
15-min dispatcher re-runs the job so it self-heals the moment the wizard saves.
The don't-sell list also now reaches `filterRelevant()` (it previously fed the
ideation prompt ONLY, so a candidate drifting onto an explicit exclusion had no
second net); the exclusion sentence is omitted entirely when the list is empty,
since a constraint naming nothing is exactly the hollow-guardrail failure above.
Tests: `ContentTopicPlannerGuardrailsTest`.

### Competitor-mention guard (2026-07-21)

A serfix.io article recommended **Semrush** — the writer has no idea a brand is
a competitor unless told. `CompetitorMentionGuard`
(`app/Services/Content/CompetitorMentionGuard.php`) fixes the class of bug:

- **Classification** (`assess()`, one flash `completeJson`, spend-metered,
  triggered by `AssessCompetitorGuardJob` on the wizard's competitors step /
  competitor edits, and lazily in `ContentArticleProducer::produce()`): given
  the business profile + the plan's merged competitor list, split domains into
  **block** (product competitor — brand name recorded) vs **reference**
  (google.com for an SEO tool — valid citation, links stay allowed). Fail-soft
  with no LLM: block every competitor domain under its domain-derived brand —
  over-blocking is the safe default and the list is editable.
  - **Classifier inputs hardened (2026-07-22 #2, the justlife.com misfire)**:
    each prompt line now carries the domain's own homepage title + meta
    description (fetched via `CrawlFetcher`, SSRF-guarded, ≤8 fetches × 8s
    per assessment, cached 30 days per host at `content:guard-ctx:{host}`,
    1-day cache for failures, per-domain fail-soft → bare line), and
    client-added domains are marked `(added by the client as their
    competitor)` (`competitorDomains()` now returns `{domain, manual}`
    pairs, manual-first ordering). Prompt bias flipped: client-added ⇒
    block unless unmistakably a pure reference; unknown/no-context ⇒ block
    — matching `failSoft()`'s over-blocking philosophy.
    `AssessCompetitorGuardJob` timeout raised 120→180 for the fetch budget.
- **Auto-enable**: a harmful verdict turns `toggles['block_competitor_mentions']`
  on ONLY while the client never decided; `auto_enabled_at` drives the wizard's
  prominent "we turned this on for you" banner, cleared by any human toggle
  (`setEnabled`), which is a decision re-assessment never overrides.
- **Enforcement**, same two layers as the style contract: a STRICT BRAND RULE in
  the writer/revise prompt (`templateInstructions`), and
  `HumanizerService::lint($html, $blockedTerms, $blockedDomains)` → issue
  `competitor_mentions` (text mentions word-boundary + `<a href>` to blocked
  domains) → `style_issues` → the revise loop's `hasStyleIssue` hard gate. An
  article cannot ship READY while a mention remains.
- **Topic exemption**: `termsForTopic()` drops any term contained in the topic's
  own target/secondary keywords — "semrush alternatives" is a legitimate,
  high-value article, not a leak.
- **UI**: `partials/competitor-guard.blade.php` card on the wizard competitors
  step AND the Settings layout — toggle, removable brand chips, add-input,
  auto-enabled banner. State in `content_plans.competitor_guard` (json,
  migration `2026_07_21_150000`); `ArticleReview` passes the same per-topic
  terms so the editor's live checks agree with the pipeline.

- **Research pick** (2026-07-21, the thryv.com screenshot): the keyword step's
  single competitor slot used to take the report list's FIRST domain — authority
  order, which surfaces directories/platforms (thryv.com for a cleaning company)
  ahead of the actual rival, and ignored the client's manual add/remove edits.
  `ContentKeywordInsights::topCompetitorDomains()` now merges `withOverrides()`
  and re-orders by the guard's classification: blocked product rivals first,
  classified references never picked. `PrepareContentKeywordInsightsJob` runs
  `assess()` inline when the plan is unassessed so the pick cannot race ahead of
  the classification; with no assessment at all it falls back to raw order
  rather than stalling research.

Tests: `CompetitorMentionGuardTest` (24).

### The Laravel receiver is a published package

`serfix/content-ai-laravel` lives in its own repo
(github.com/Serfix-SEO/serfix-laravel) and installs from Packagist:

    composer require serfix/content-ai-laravel

Prod consumes it that way as of 2026-07-21 (v0.1.0). The earlier `packages/`
path-repo vendoring is gone — with it goes its trap, worth remembering if a path
repo is ever used again: `composer update` reports *"Nothing to install"* when
the package's `version` string has not changed, even though the source differs,
so `vendor/` silently keeps the old code (a security fix shipped to `packages/`
was absent from `vendor/`). Packagist has no such problem — the git tag is the
version.

Publishing a new package release: tag it in the package repo, then
`composer update serfix/content-ai-laravel` here.

### ⛔ Product independence: the dashboard plan limit must never gate content

Serfix SEO and Content Autopilot are **separate products with separate billing**.
The dashboard side has a website limit (`User::websiteLimit()`); sites past it are
**frozen** (`User::frozenWebsiteIds()` → `Website::isFrozen()`). Content Autopilot
has its own subscription/trial and its own explicit per-site coverage
(`content_plans.billing_covered_at`).

Freeze kept leaking across that line. Every case was invisible — no error, just a
paid product quietly not working (all found 2026-07-21, one prod account):

| Leak | Symptom |
|---|---|
| `effectiveFeatureFlags()` froze **all** flags first | wizard step 2: "Content Autopilot is not included in your plan" for a covered site |
| `CrawlWebsitePagesJob` / `CrawlSitemapDeltaJob` skipped frozen sites | no crawl → no business profile, no internal linking, no keyword seeds → steadily worse articles |
| `SyncSearchConsoleData` skipped frozen sites | no GSC gap → `ContentTopicPlanner` loses its PRIMARY ideation signal |
| `canAddWebsite()` counted only the dashboard allowance | a customer who paid the per-extra-site addon could not add that site |

**The rule:** before a dashboard-limit branch disables anything, ask
`Website::contentAutopilotEntitled()` (public for exactly this). If the site is a
content site, the dashboard limit has no say.

Deliberately still frozen, because they ARE dashboard concepts: `isPro()`,
`effectiveTier()`, the WP plugin's upgrade CTA, the GA sync (content never reads
GA), and the 365-day historical backfill. A frozen content site therefore keeps
Content Autopilot and nothing else — `array_filter($flags)` on such a site returns
exactly `['content_autopilot']`.

Tests: `ContentFrozenWebsiteTest`, `ContentProductIndependenceTest`.

### 🔥 Image-storage + worker-box incident (prod, 2026-07-20)

Prod articles had **zero images** for two days. Three independent faults,
each silent on its own — worth knowing all three, they stack:

1. **Ideogram key 401.** The token stopped being accepted (401 "Access denied").
   `IdeogramClient::generate` returns `['ok'=>false]` and the job's per-image
   `continue` swallows it → article ships imageless, no failed job, no alert.
   Fix: new key in `.env` on **both** boxes.
2. **Worker box B ingress ~1 Mbit.** After the key fix, generation succeeded
   (and **billed**) but `download()` of the ~6 MB PNG hit its 60s timeout at
   ~600 KB. Measured: box A 50 MB/s vs box B **14 KB/s** download (8 parallel
   streams still totalled ~113 KB/s → aggregate cap, and it applies to the
   private network too). Box B **egress** is fine (2.3 MB/s). Ruled out: `tc`
   shaper, iptables limits, MTU (clean at 1472), packet loss (0%, 5.7 ms RTT),
   conntrack, NIC counters, CPU (99% idle). Hetzner API: `locked=false`, no
   abuse action, 5.6 GB of 22 TB traffic used, and its own metrics show inbound
   never above ~180 KB/s for ≥4 days — so this is **not new**, it was just
   barely inside the 60s timeout until the images grew. Cause looks like a
   degraded host/virtio receive path (`rx_kicks` 2320 vs `tx_kicks` 29.7 M).
   Fix: **moved the `content` queue to box A** — `worker-content` added to the
   `local`/`production` Horizon envs and removed from `worker`
   (`config/horizon.php`). Move it back once B's receive path is rebuilt.
3. **`content_s3` silently discarded every upload.** `CONTENT_S3_ENDPOINT` and
   `CONTENT_S3_URL` were **never set** on either box, so the S3 disk fell back
   to AWS defaults and addressed `s3.nbg1.amazonaws.com` (does not resolve).
   With `'throw' => false` the `put()` was a **silent no-op**: images were paid
   for, `ContentImage` rows written, and `<img>` tags injected pointing at a
   dead host — strictly worse than no images. Fixed by adding both vars on both
   boxes (bucket `serfix` @ `https://nbg1.your-objectstorage.com`, creds were
   always valid) and flipping the disk to **`'throw' => true`**
   (`config/filesystems.php`) so a storage failure lands in `failed_jobs` +
   the ops digest instead of corrupting the article. The 9 bogus rows were
   deleted, their `<figure>` blocks stripped from the article HTML, and the
   images regenerated (9/9 now public, HTTP 200).

**Also found:** box B's `vendor/` is missing `league/flysystem-aws-s3-v3`
(`PortableVisibilityConverter not found`), so it could never have written to
`content_s3` even with the endpoint set — a second reason content belongs on
box A. And box B's IP is not whitelisted with DataForSEO (`40207`), so
competitor traffic metrics fail from there.

**Rule:** any job that spends money before storing a result must fail loudly.
`throw => false` on a paid artifact's disk turns a config gap into silent
financial loss.

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
- Length adherence on broad keywords — RESOLVED 2026-07-22 by **chunked
  writing** (below); per-section word budgets are the one thing the model
  actually respects, since each call physically cannot exceed its small cap.

## Chunked article writing (2026-07-22)

**Why**: the write stage was ONE 16k-max_tokens `completeJson` call returning
the whole article. Hub topics ("The Ultimate Guide to …") made v4-pro write
past the cap — prod incident: two attempts truncated at exactly 16000
completion tokens → `llm_parse_failed`, ~21k tokens billed each for zero
output, topic failed.

**How** (`AiWriterService::chunkedDraft()`, opt-in via `$input['chunked']` —
set ONLY by `ContentArticleProducer`; the WP-plugin wizard keeps the legacy
single call):
1. **Outline call** (max_tokens 2500, keeps `reasoning`): same full prompt +
   a PLANNING STEP instruction → `{h1, summary, outline: [{heading, focus,
   word_target}]}`, 6-14 entries, FAQ last when required. One retry.
2. **Per-section calls** (max_tokens 4000, `reasoning` stripped, timeout 90):
   full prompt + WRITING STEP instruction naming exactly one section →
   `{heading, html}`. Structurally cap-proof (a section is 150-800 words).
   Parse failure → ONE retry at half the word budget → skip the section
   (article completes with the rest). Wall-clock budget: past ~900s no more
   retries (produce job timeout is 1800s).
3. Assembled into the exact single-call response shape (`sections[]` with
   `kind: add`/`title`/`proposed_html`) — everything downstream
   (normalizeSections, anchors, humanizer, scorer, revise loop) unchanged.
   Fewer than 3 usable sections → null → the producer's normal
   `draft_failed` path.

**Cost**: prompt context re-sent per call ⇒ roughly 1.5-2× tokens/article
(~$0.06-0.10 → ~$0.12-0.20). Owner-approved trade-off; on articles that
previously failed-and-retried it is net cheaper (no more 21k-token
zero-output attempts). Pipeline tests' `fakeLlm()` speaks the chunked
protocol (PLANNING STEP / "write ONLY section N" markers).

## "Connect a destination" banner (renamed 2026-07-22)

`resources/views/components/content/connect-integration.blade.php` (was
`connect-wordpress.blade.php` — renamed, the old name became misleading once
Laravel/custom-webhook joined WordPress as integration options). Shown in the
calendar view (`content-calendar.blade.php`) and the article review page
(`article-review.blade.php`) whenever the website has **no
`ContentIntegration` row with `status = STATUS_CONNECTED`, regardless of
platform** — previously it only checked `PLATFORM_WORDPRESS`/
`PLATFORM_WORDPRESS_APP_PASSWORD`, so a client who connected via the Laravel
or Custom tab (both stored as `PLATFORM_WEBHOOK`, see
[Publishing](#publishing-phase-3-2026-07-18)) kept seeing "Connect your
WordPress site" forever. Copy is now platform-neutral too ("Connect a
destination to publish").

## Two parallel wizard implementations — a recurring drift trap

`ContentCalendar` (dashboard) and the `ContentWizard` trait + `PublicOnboarding`
(anonymous funnel) are two FULLY INDEPENDENT PHP implementations of the same
7-step wizard — shared only via one blade partial
(`livewire/content/partials/wizard.blade.php`), so the markup stays pixel-
identical but the LOGIC does not. **The competitor-mention guard was built
2026-07-21 into `ContentCalendar` only** — `AssessCompetitorGuardJob` was never
dispatched anywhere in the trait (`loadCompetitors()`, `addCompetitor()`,
`removeCompetitor()`, `resetCompetitors()`, `toKeywordResearch()`), the guard
card's action methods (`toggleCompetitorGuard`/`addBlockedTerm`/
`removeBlockedTerm`) didn't exist on `PublicOnboarding` at all, and
`wizardViewData()` never set the `guard` view key — so every anonymous-
onboarding user saw "Checking which of your competitors could pull readers
away…" forever, with nothing ever able to resolve it (found 2026-07-22,
reported as "still loading" against mkccleaningservices.com, which onboards
exclusively through the anonymous funnel). Fixed by mirroring all of the above
into the trait, and extracting the one genuinely shared piece —
`CompetitorMentionGuard::stateFor(ContentPlan $plan): array` — so both
`ContentCalendar::guardState()` and the trait's `wizardViewData()` build the
card's view state from one place instead of two copies that can silently
diverge again. **Any future Content Autopilot wizard feature must be added to
BOTH `ContentCalendar` and `ContentWizard`/`PublicOnboarding`, or it silently
only works for logged-in dashboard users.**

Same investigation also found the "Est. traffic/mo" / "Organic keywords"
columns going blank for a MANUALLY added competitor forever: those come from
`DomainMetric.dfs_metrics`, populated only by `EnrichCompetitorDomainMetricsJob`
— which `ContentSetupInsights::build()` dispatches for the raw
auto-discovered competitor list only. `withOverrides()` backfills DA/PA for a
manual add (via `metricsForDomain()`) but never touched `dfs_metrics`. Fixed
by dispatching `EnrichCompetitorDomainMetricsJob` for the single new domain
from both `addCompetitor()` implementations (idempotent — skips domains
already fresh, so this is cheap).

## Country / geo-targeting (2026-07-22)

`ContentPlan.country` (lowercase code, e.g. `ae`; `'global'` = worldwide) is
selected in wizard step 1 and threads through the whole pipeline:
- **Keyword research**: `ContentKeywordInsights` (already correct before this
  change) — `KeywordFinderPool` country key, Serper `gl`.
- **Competitor discovery** (step 4/5 SERP fallback):
  `DiscoverContentCompetitorsJob` resolves `KeywordFinderLocations::
  serperGl($plan->country)` and passes it into `ReportEnrichmentService::
  discoverCompetitorsFor(..., $gl)` (new optional 4th param, default `'us'` —
  other callers of this SHARED service, e.g. the client backlink report, are
  unaffected).
- **Topic ideation** (`ContentTopicPlanner::ideate()`): a `TARGET MARKET:
  {country name}` line is added to the prompt when country ≠ `'global'` (no
  line at all for worldwide — nothing to localize to).
- **Article research/write** (`ContentArticleProducer`) — already correct
  before this change (`AiContentBriefService` + the writer's locale block).
- **NOT threaded, deliberately**: `ContentKeywordInsights::competitorData()`
  (the competitor metrics/traffic UI table) and the wizard's own competitor-
  detection list — both intentionally show every real SERP competitor
  regardless of market, same reasoning as showing directories there (see the
  Landmines entry below).

Detection is a zero-cost ccTLD heuristic only — there is no IP-geolocation or
GSC-country-breakdown signal wired in yet (`app/Support/Countries.php` +
`PluginInsightResolver::countryBreakdown` exist for a DIFFERENT feature and
aren't connected here). A future improvement could prefer GSC's top country
when the site already has Search Console connected.

## Site-profile detection vs bot-hostile / region-routed sites (2026-07-22)

kayali.com/en-ae onboarded to a blank step 1: the crawl came back `blocked`
(Shopify bot protection; even robots.txt serves an error page), the root URL
is a bare 302 to /en-ae, and `SiteProfileExtractor`'s live fallback fetched
only the bare root with the honest bot UA → nothing → empty profile cached
7 days. Two fixes:
- **(a) Entered-path preference** — `PublicOnboardingStartController` caches
  the URL the visitor actually typed (path included) at
  `content:entered-url:{website_id}` (30d, only when a path is present);
  `liveSignals()` tries it before the bare host.
- **(b) Hardened live fetch** — `SiteProfileExtractor::
  fetchFollowingRedirects()`: manual redirect-following up to 4 hops with
  EVERY hop re-checked through `SafeHttpGuard` (CrawlFetcher's per-hop SSRF
  policy), honest `SerfixBot/1.0` UA first, one retry with a browser-like UA
  when the bot is blocked (403/406/429/5xx/transport error).
Verified live against the real kayali.com: full description + sell/dont_sell
extracted. Remember the 7-day EMPTY-profile cache when retesting a
previously-failed site — bust `content:site-profile:v1:{website_id}` or the
blank result persists.

## Landmines (2026-07-22 prod incident)

- **Bare-domain LLM classification misfires on unknown brands.** justlife.com
  and urbancompany.com — real UAE cleaning rivals the client HAND-ADDED at
  mkccleaningservices.com — were classified "reference" because the flash-tier
  classifier got only `- domain.com` lines: no page context, no signal that the
  client picked them, and a prompt that biased unknowns toward "not a
  competitor". Any future domain-classification prompt must (a) carry the
  site's own homepage title/meta, (b) carry the manual-add signal, (c) default
  unknowns to block — matching `failSoft()`'s over-blocking philosophy.
  `AssessCompetitorGuardJob`'s timeout is 180 specifically to cover the
  context-fetch budget (≤8 × 8s) on top of the 40s LLM call.
- **Coverage lives ON the `ContentPlan` row, not a separate entitlements
  table.** `ContentEntitlements::hasContentAccessFor()` checks
  `ContentPlan.billing_covered_at`. Hard-deleting a plan (e.g. to reset a test
  site for a clean wizard retest) silently revokes coverage too — the site
  drops out of `preferredWebsite()`, `EnsureContentAccess` bounces it to Get
  Started. Always restore via `ContentEntitlements::coverWebsite()` after a
  plan wipe, never recreate the row by hand.
- **`analyzeSite()` must fail soft on `QuotaExceededException`.** It runs from
  `wire:init` on every fresh mount. An uncaught throw hits the global handler's
  `redirect()->back()`, which for a Livewire XHR resolves to the SAME page
  (Referer header) — Livewire.js turns that 302 into a full navigation, which
  re-mounts, re-fires `wire:init`, throws again: an infinite refresh loop
  ("stuck on wizard step 1"), not just a blank profile field. Fixed in
  `ContentCalendar.php::analyzeSite()` — catch and continue.
- **Content Autopilot LLM calls are exempt from the generic per-user
  `UsageMeter` token cap** (`__unmetered => true` on every `completeJson()`
  call under `app/Services/Content/*` and `app/Jobs/Content/*` +
  `GenerateContentImagesJob`'s prompt-writing call) — real usage is bounded by
  trial/monthly article counts and `ContentLlmSpendMeter` instead. Before this
  fix, `SiteProfileExtractor` and 7 other classification/ideation call sites
  were still gated by the dashboard's shared SEO-feature token cap, so a
  content-only account with no `current_plan_slug` (coverage was ever only
  granted at the `ContentPlan` level, never a dashboard `Plan` tier) could get
  quota-blocked by unrelated SEO-tool usage pooled into the same counter. Any
  NEW content-autopilot LLM call site must carry `__unmetered => true` or it
  silently re-introduces this. `ContentArticleProducer`'s write/de-ai/revise
  stages and `ContentKeywordInsights`'s People Also Ask call (Serper, not an
  LLM call) are unaffected/out of scope.
- **Guard-aware competitor RANKING must be the ONE place every keyword-research
  call site derives "the competitor(s)" from — `CompetitorMentionGuard::
  rankAndFilter(ContentPlan $plan, array $candidates): array`.** The raw
  `ContentSetupInsights::competitorAuthority()` report ranks by SERP authority,
  which systematically surfaces directories/platforms (thryv.com for a
  cleaning company) ahead of the real rival. `ContentKeywordInsights::
  topCompetitorDomains()` was fixed first (2026-07-21), but THREE more call
  sites independently re-derived their own raw, unfiltered top-N list and kept
  the bug alive: `ClassifyPlanKeywordsJob::handle()` (the job that actually
  populates the client-visible gap-keyword set — this was the highest-impact
  miss), the monthly `ebq:content-keyword-harvest` command (which feeds that
  same job's `domain_keyword_rankings` source data), and none of them called
  `ContentSetupInsights::withOverrides()` either, so manual competitor
  add/remove edits were ALSO silently ignored in both. Fixed 2026-07-22 by
  extracting the rivals-first/references-excluded/unassessed-raw-order logic
  into the shared `rankAndFilter()` helper and wiring `withOverrides()` +
  `rankAndFilter()` into all three sites. `ContentKeywordInsights::
  competitorData()` (the competitor metrics/traffic UI table) is DELIBERATELY
  left unfiltered — like the wizard's own competitor-detection list, its job is
  to show every real SERP competitor, directories included; only research/
  harvesting call sites need the guard's rival-vs-reference judgment. Any NEW
  "pick the competitor(s)" call site must go through `rankAndFilter()`.

## Env (staging QA values 2026-07-17)

Staging `.env` gained real `DEEPSEEK_API_KEY`/`MISTRAL_API_KEY` (copied from
prod) + `IDEOGRAM_API_KEY`, with `CONTENT_LLM_MONTHLY_CAP_USD=1` and
`IDEOGRAM_MONTHLY_CAP_USD=1`. Production values land at prod-deploy time
(cap proposals: LLM $25, images $30). Both boxes + staging must carry the vars
(worker-box env-drift landmine).
