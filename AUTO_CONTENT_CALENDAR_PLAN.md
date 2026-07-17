# Content Autopilot — Auto Content Calendar (production plan)

> Feature: fully automatic content calendar for client websites — evidence-driven topic
> ideation → AI writing (hard to detect as AI) → **technical-SEO verification loop with
> scoring + revisions** → Ideogram image generation → auto-publish to WordPress/Shopify/
> webhook — surpassing getautoseo.com. Planned 2026-07-17. All work lands on the
> `staging` branch, staging-first, prod only after operator approval (invariant 2b).

---

## 1. Competitive target (what getautoseo does, what we beat)

Recon: 25 product screenshots (`/root/autoseo`) + site. Their shape:

- 5-step onboarding wizard (business info → sell/don't-sell lists → competitors →
  search terms → first 5 articles), $1 trial → $149/mo, 30 articles/mo, 1,500–3,000
  words, hero images + infographics, publishing window, weekly frequency picker.
- Calendar dashboard: month grid of article cards (title + search term + write date),
  content-strategy mindmap, "Add search terms".
- Article Settings page: auto-publish toggle, 24h delay, hero/YouTube/infographic/key-
  takeaways/TOC/external-links toggles, length picker, frequency picker, extra
  languages, business docs upload, global writing instructions, internal link URLs,
  CTA link, location targeting, competitors, image style prompt + logo, author box,
  disclaimer.
- 13 integrations (WP plugin, Shopify app, Webflow API, Wix, Squarespace **via stored
  password + 2FA off** (!), BigCommerce, Duda, HubSpot, GoHighLevel, Lovable, RSS/JSON
  feed, hosted blog, generic webhook w/ HMAC + AI scaffold prompt). Social auto-share
  (FB/X).

**Where we structurally win (they can't copy this quickly):**

| Their gap | Our asset |
|---|---|
| Generate + publish blind, no verification | Post-write **SEO scoring + revision loop** against hard checks (we own a crawler + audit stack) |
| Site knowledge = scraped homepage + typed lists | Real data at write time: GSC queries/impressions/positions, keyword volumes (self-hosted finder), crawl pages/titles/headings/`content_terms`, internal link graph, competitors, Trust/Citation scores |
| User pastes internal-link URLs manually | Auto internal-link candidates from GSC clicks + crawl graph (`resolveSmartInternalLinks` already exists) |
| Topic list from one SERP scrape | Striking-distance GSC gaps + keyword-finder volumes + competitor coverage + **cannibalization guard** (we know every existing title/H1/query) |
| Squarespace = password sharing + 2FA off | We refuse that class; WP plugin (installed base), Shopify token app, signed webhook |
| No AI-detection stance | Explicit humanization layer (see §6) — existing two-layer dash defense extends to a full style lint |

Non-goals now: backlink-exchange network, hosted blog, Wix/Squarespace/Webflow/Duda/
HubSpot/GoHighLevel drivers (webhook + RSS cover them), social auto-share (phase 7,
optional), YouTube embeds, infographics.

---

## 2. What already exists (reuse, don't rebuild)

| Piece | File | Reuse |
|---|---|---|
| One-call article draft, strict JSON, dash-stripping, locked anchors, LSI audit+retry, smart internal links | `app/Services/AiWriterService.php:59` (prompt v25) | The writing engine. Extend prompt for autopilot template (TOC/key-takeaways/FAQ/CTA blocks) |
| SERP → brief (subtopics, outline, entities, PAA, internal links) | `app/Services/AiContentBriefService.php:81` | Research stage per topic (7d cache) |
| LLM plumbing: Mistral + DeepSeek V4 (`deepseek-v4-pro`), thinking mode, pooled token metering, admin provider/model settings | `app/Services/Llm/*`, `app/Support/{LlmProviderConfig,AiModelConfig}.php` | All LLM calls. Writer stage pins **DeepSeek v4-pro + `reasoning=>true`** (admin-overridable) |
| Async generation job pattern (202 + poll, `failStaleGeneration`) | `app/Jobs/GenerateWriterDraftJob.php`, `writer_projects.generation_*` | Job lifecycle template |
| Humanization primitives | `AiWriterService::stripDashes`, `AiSnippetRewriterService::humanizePunctuation()`, brand-voice avoid-phrases | Seed of `HumanizerService` (§6) |
| Brand voice fingerprint | `app/Services/BrandVoiceService.php:35` | Optional voice matching for existing blogs |
| Spend circuit-breaker pattern | `app/Services/Reports/DataForSeoSpendMeter.php` | Clone for LLM-autopilot + Ideogram meters |
| Admin runtime settings | `settings` table (`app/Models/Setting.php:22`), `/admin/settings` (`PlatformSettingsController`) | All autopilot admin knobs |
| WP plugin + per-Website Sanctum token | `WordPressConnectController.php:77`, `/var/www/ebq/ebq-wordpress-plugin/` (separate repo) | Publish transport (§7): plugin v2.1 gains a receive endpoint |
| Keyword data | `KeywordMetricsService::metricsOrQueue`, keyword-finder fleet, `keyword_metrics` (30d cache) | Ideation volumes — cache-first, never blocking |
| GSC data | `SearchConsoleData`, `ReportDataService` | Striking-distance/gap ideation, internal links; degrade per `hasGsc()` rule |
| Crawl data | `CrawlReportService`, `website_pages` (titles/headings/`content_terms`) | Cannibalization guard, internal links, business understanding |
| Usage/credits | `UsageMeter`, `client_activities`, plan `api_limits` | Plan gating + per-article credits |
| Scheduler | `routes/console.php` | Dispatcher command registration |

Nothing about the calendar/publishing exists today — `content_briefs` table was dropped
2026-07-06 (was orphaned); do not resurrect its name.

---

## 3. Data model (new tables, ULID PKs, central DB)

1. **`content_plans`** — one per website. `website_id` (unique), `status`
   (active|paused), `articles_per_week` (1–7) + `publish_days` JSON, `publish_window`
   (start/end hour, site TZ), `article_length` (1500|2000|2500|3000),
   `auto_publish` bool + `review_hours` (0 = instant, default 24 — publish waits so the
   client can veto), toggles JSON (`toc`, `key_takeaways`, `faq`, `external_links`,
   `author_box`, `cta_enabled`), `cta_url`, `custom_instructions` (guarded by
   `CustomPromptGuard`), `business_description`, `offerings` JSON (sell[] / dont_sell[]),
   `internal_urls` JSON (optional manual additions), `image_style_prompt`,
   `language`, `country`.
2. **`content_topics`** — calendar cells. `plan_id`, `website_id`, `title`,
   `target_keyword`, `secondary_keywords` JSON, `intent`, `source`
   (gsc_gap|keywords|competitor|llm|manual), `keyword_volume`, `scheduled_for` date,
   `status`: `suggested → approved → researching → writing → scoring → revising →
   ready → scheduled → publishing → published | failed | skipped`, `position` (manual
   ordering), `meta` JSON (dedupe fingerprint, cannibalization check result).
3. **`content_articles`** — one row per draft **version**. `topic_id`, `version`,
   `h1`, `meta_title`, `meta_description`, `html`, `markdown`, `outline` JSON,
   `word_count`, `seo_score` int, `seo_issues` JSON (machine-readable check results),
   `style_issues` JSON (humanizer lint), `generation_meta` JSON (provider, model,
   tokens, cost_usd, duration, reasoning on/off), `is_current` bool.
4. **`content_images`** — `article_id`, `role` (featured|inline), `section_anchor`,
   `prompt`, `negative_prompt`, `params` JSON (speed/style/aspect/seed), `disk_path`,
   `width/height/bytes`, `alt_text`, `caption`, `filename` (keyword slug), `cost_usd`,
   `status` (pending|generated|failed|rejected). Files under
   `storage/app/public/content-images/{website}/{topic}/` as WebP (+1200w variant);
   Ideogram URLs **expire — download immediately** in the job.
5. **`content_integrations`** — `website_id`, `platform`
   (`wordpress|wordpress_app_password|shopify|webhook`), `credentials` **encrypted
   cast** (WP: plugin secret; app-password: user+pass; Shopify: shop domain + Admin API
   token; webhook: url + signing secret), `config` JSON (author, category/blog handle,
   post status), `status` (connected|error), `last_verified_at`, `last_error`.
6. **`content_publications`** — `article_id`, `integration_id`, `external_id`,
   `external_url`, `status` (queued|sent|confirmed|failed), `attempts`, `response`
   JSON, `published_at`, `verified_at` (post-publish fetch OK).

Indexes: `content_topics(website_id, scheduled_for)`, `(plan_id, status)`;
`content_articles(topic_id, is_current)`. FK semantics follow
`infra/reference/database.md` conventions (app-enforced across shard tiers).

---

## 4. Pipeline (queued jobs, worker box, queue `heavy`)

Dispatcher: **`ebq:content-autopilot`** (scheduled `everyFifteenMinutes`,
`withoutOverlapping`, in `routes/console.php`). Each tick:
- requeue stuck rows (status-age reaper, same philosophy as `failStaleGeneration()`),
- claim topics due for writing (scheduled_for within lead window — write **24–48h ahead**
  of publish date so revision loops + review windows never slip a slot),
- claim `ready` articles whose review window elapsed + auto_publish → publish,
- top up thin calendars (< 7 days of future topics → `PlanTopicsJob`).

Per-topic chain (each stage its own job, `Bus::chain`, `tries=1` writing stages /
`tries=3` publish, `timeout` < redis `retry_after` 1320):

1. **`PlanTopicsJob`** (per website, also run at plan creation): ideation from
   evidence — GSC striking-distance queries (pos 8–30, impressions high, no dedicated
   page), keyword-finder ideas + volumes (cache-first), competitor coverage terms,
   `content_terms` clusters; **cannibalization guard**: reject any topic whose keyword/
   title fingerprints ≈ existing `website_pages` titles/H1s or already-planned topics
   (token-overlap threshold). One `completeJson` call turns the evidence into 30 dated
   topics; volumes attached; degrade gracefully with no GSC (website-mode keywords —
   `gsc-ga-degradation` rule).
2. **`ResearchTopicJob`** — `AiContentBriefService::brief` (Serper SERP, cached), plus
   autopilot context block: offerings, don't-sell list, business description, CTA,
   internal-link candidates.
3. **`WriteArticleJob`** — `AiWriterService::draft` strict mode with a new
   `%AUTOPILOT_TEMPLATE%` extension: key-takeaways box, TOC anchors, consolidated FAQ,
   CTA placement, external authority links (toggle-driven), 1,500–3,000 word target,
   output-locale block (reuse v25 machinery). DeepSeek `deepseek-v4-pro`,
   `reasoning => true`, 16k tokens. Writes `content_articles` v1.
4. **`ScoreArticleJob`** — NEW pure service **`ContentSeoScorer`** (§5). Deterministic,
   no LLM, no I/O beyond the site-context payload passed in. Emits score + issues.
5. **`ReviseArticleJob`** — if score < target (admin setting, default **85**) and
   iterations < max (default **3**): one targeted `completeJson` revision call — sends
   ONLY failing checks + affected sections, not a full rewrite (cheap + convergent);
   re-score; new version row. Exit on target reached, max iterations, or diminishing
   delta (<+3). Below-floor final (< 60) → topic `failed`, surfaced in review UI, never
   auto-published.
6. **`GenerateArticleImagesJob`** (config-gated) — Ideogram v3 (§8): featured 16:9 +
   up to N inline (admin default 2); prompts from H1/section content + plan
   `image_style_prompt`; negative prompt bans text-heavy/watermark; download → WebP →
   `content_images`; alt text written by the cheap-model stage w/ keyword context
   (≤125 chars); reject `is_image_safe=false`. Failure = article proceeds without
   images (never blocks publishing).
7. **`PublishArticleJob`** — driver per integration (§7). Idempotency: a
   `content_publications` row is claimed (unique topic+integration) before the HTTP
   call; retries update the same row; driver upserts by `external_id` when present
   (never double-posts). After success: **post-publish verify** — SSRF-guarded fetch of
   `external_url`, assert 200 + H1 present + no `noindex`; store `verified_at`; WP
   installs may also trigger the existing Indexing API submit path.

Failure philosophy: any stage failure → topic `failed` + `last_error`; ops digest line
(existing `Queue::failing` buffer); reaper flips stuck rows; client sees neutral copy
only ("Scheduled", "In progress", "Needs attention" — never internal states, invariant 9).

---

## 5. `ContentSeoScorer` — the verification loop's referee

`app/Services/Content/ContentSeoScorer.php` — pure, versioned (`const VERSION`),
weights renormalize on missing context. Checks (each → pass/fail + fix hint the
reviser consumes):

- **Keyword placement**: target keyword verbatim in meta_title (30–60 chars), H1
  (≤65), first 100 words, ≥1 H2, meta_description (130–155, keyword verbatim), URL slug.
- **Structure**: word count within ±15% of target; ≥4 H2 sections; no H3 without
  parent H2; no section > 900 words (existing `SECTION_HTML_CAP` alignment); TOC/
  key-takeaways/FAQ present when toggled.
- **Keyword usage**: density 0.5–2.5%; ≥60% of secondary/LSI phrases used (reuse
  `auditLsiUsage` data); no keyword-stuffed headings (>2 exact repeats).
- **Linking**: ≥2 internal links to **valid, existing** site URLs (validated against
  crawl `website_pages` — a hallucinated internal URL is a hard fail); ≥1 external
  authority link when toggled; no more than 1 link per 150 words; CTA link present
  when enabled.
- **Media**: every `<img>` has non-generic alt (keyword-adjacent, not stuffed).
- **Readability**: Flesch-adapted grade band per language; average sentence ≤ 24
  words; paragraph ≤ 5 sentences.
- **Style/AI-detection lint** (from `HumanizerService`, §6) — counted into the score
  so revisions fix style too.
- **Uniqueness**: title/H1 fingerprint vs existing site pages + published topics
  (cannibalization re-check post-write).

Score = weighted 0–100. Unit-tested against fixtures with exact expected values
(pattern: `AuthorityScoreCalculatorTest`).

## 6. `HumanizerService` — hard-to-detect writing

`app/Services/Content/HumanizerService.php`, two layers (extends the proven
two-layer dash defense):

1. **Prompt contract** (block injected into write + revise calls): absolute ban on
   em/en dashes and `--` (existing rule); ban curly quotes; **banned-phrase list**
   (delve, leverage, tapestry, landscape, realm, unlock, elevate, "In conclusion",
   "Moreover", "Furthermore", "It's important to note", "In today's fast-paced world",
   "game-changer" … admin-editable Setting, seeded ~60 phrases); require contractions;
   vary sentence length (mix <8 and >20 word sentences); no more than 2 consecutive
   sections opening with the same pattern; concrete specifics from the brief/site data
   instead of generic claims; at most one rhetorical question per article; lists only
   where genuinely list-shaped (no listicle-itis).
2. **Deterministic post-lint** (feeds `style_issues` + scorer): dash/curly-quote strip
   (reuse `stripDashes` + `humanizePunctuation`), banned-phrase scan, sentence-length
   variance floor, paragraph-uniformity flag, transition-word-density ceiling,
   em-dash-replacement artifacts (" - " mid-sentence), repeated n-gram detector
   (same 6-gram 3+ times). Lint failures become revision instructions like any SEO
   issue.

No external AI-detector API (cost, flakiness, and they'd log our content). The lint
is our proxy; the banned list is a live admin setting so new tells get added without
deploys.

---

## 7. Publish integrations

Driver interface `app/Services/Content/Publishing/PublishDriver.php`:
`verify(ContentIntegration): Result`, `publish(ContentArticle, ContentIntegration):
PublishResult{external_id, external_url}`, `update(...)` (idempotent re-push).

1. **WordPress via our plugin (primary; plugin v2.1)** — the plugin (separate repo at
   `/var/www/ebq/ebq-wordpress-plugin/`, own release cycle via `plugin_releases`)
   gains a REST route `POST /wp-json/ebq/v1/content` that `wp_insert_post`s (title =
   H1 rule per writer.md, HTML content, meta title/description via our existing
   on-page module, featured image sideload, category, status). Auth: per-install
   **publish secret** minted at connect time alongside the Sanctum token, sent as
   HMAC-SHA256 signature over the JSON body + timestamp (replay-guarded ±5min). Server
   driver posts from the web box. Needs plugin release + WP QA on
   pubgnamegenerator.net.
2. **WordPress via Application Passwords (fallback, ships same phase)** — for sites
   unwilling to install the plugin: standard `POST /wp-json/wp/v2/posts` with Basic
   auth (application password), media upload via `/wp/v2/media`. Credentials encrypted;
   verify = authenticated `GET /wp/v2/users/me`.
3. **Shopify** — merchant creates a custom app (Admin API token, scopes
   `read_content,write_content`); driver `POST /admin/api/2025-07/blogs/{id}/articles.json`
   (title, body_html, image src/alt, metafields for meta description, tags, author).
   Blog picked at connect (list via `GET /blogs.json`). Connect wizard = paste shop
   domain + token, step-by-step guide page (their pattern, our design).
4. **Generic webhook** — POST full JSON payload (h1, slug, html, markdown, meta,
   hero image URL + alt, images[], keywords, faq schema, language, timestamps, status)
   signed `X-Serfix-Signature: sha256=...` over body with per-integration secret;
   3 retries expo backoff; expects 2xx + optional `{url}` back for the live link.
   Covers Webflow/Wix/static/custom sites day one, incl. an AI "build the receiver"
   copy-prompt in the docs page (their best idea, worth matching).

RSS/JSON feed of published articles (token URL, 60s cache) — cheap add-on in the same
controller, phase 5.

Client copy never names vendors or mechanics (invariant 9): "WordPress", "Shopify",
"Custom (webhook)".

---

## 8. Ideogram integration

`app/Services/Content/IdeogramClient.php` — `POST
https://api.ideogram.ai/v1/ideogram-v3/generate`, header `Api-Key`, fields prompt /
aspect_ratio / rendering_speed / style_type / negative_prompt / num_images / seed.
Response `data[].url` **expires** → download in-job, convert WebP. Costs: TURBO $0.03,
DEFAULT $0.06, QUALITY $0.09 per image.

- Env `IDEOGRAM_API_KEY` (`config/services.php` → `ideogram.key`); staging + prod
  `.env` hand-set (never committed). phpunit blanks it (same landmine class as
  KE/Mistral keys). Key was shared in chat 2026-07-17 — treat as live secret, consider
  rotation after launch.
- **`IdeogramSpendMeter`** — clone of `DataForSeoSpendMeter` (Redis
  `ideogram:spend:YYYY-MM`, cap `IDEOGRAM_MONTHLY_CAP_USD`, prod ~$30 start, staging
  $1, fail-open, admin-ops banner + 80%/100% digest lines). Over cap → articles publish
  without images, admin-only signal. Same for a new **`LlmAutopilotSpendMeter`**
  guarding autopilot LLM spend separately from interactive usage.
- Admin settings (§9) control counts/speed/style; per-plan image caps in
  `plans.api_limits`.

Cost model per 2,500-word article: DeepSeek draft ~$0.02 + brief/revisions ~$0.02 +
3 TURBO images $0.09 ≈ **$0.13–0.20**. 30 articles/mo ≈ **<$6/site** vs competitor's
$149 price point.

---

## 9. Admin settings (Settings table, `/admin/settings` new "Content Autopilot" card)

- **Models per stage** (provider + model selects, validated against live model lists
  via `AiModelConfig::listAvailableModels`): `content.model.ideate` (default cheap —
  mistral-small / v4-flash), `content.model.write` (default `deepseek-v4-pro`,
  reasoning on), `content.model.revise` (default = write model), `content.model.image_prompts`
  (cheap). Falls back to platform default provider when unset.
- **Images**: `content.images.enabled`, `featured_enabled`, `max_inline` (0–4,
  default 2), `rendering_speed` (TURBO default), `style_type` (AUTO), monthly cap USD.
- **Quality loop**: `content.revise.target_score` (85), `max_iterations` (3),
  `publish_floor` (60).
- **Humanizer**: banned-phrases textarea (seeded list).
- **Caps**: per-plan monthly articles (plans UI, `api_limits.content_articles.monthly`
  — trial 5, solo 15, agency 60, enterprise custom), LLM autopilot monthly USD cap.
- All admin-only; nothing cost/vendor-shaped ever reaches client UI.

---

## 10. Client UI (Serfix proprietary design — brand orange #F26419, Livewire 3, full-width like the new onboarding shell)

Nav: **Content** (website-scoped, plan-gated `content_autopilot`).

1. **Setup wizard (3 steps — beat their 5 by prefilling from our data)**:
   ① Confirm business profile — description auto-drafted from crawl `content_terms` +
   homepage meta (editable), offerings sell/don't-sell chips (LLM-prefilled,
   drag-to-rank); ② Cadence & style — articles/week picker w/ per-plan cap, length,
   toggles (TOC/takeaways/FAQ/CTA), review-first vs auto-publish; ③ Connect
   publishing — WP (detect + plugin CTA or app-password form), Shopify, webhook, or
   "review-only for now". Then `PlanTopicsJob` builds the first 30-day calendar.
2. **Calendar page** — month grid + list toggle. Day cells: topic card (title,
   keyword + volume chip, status pill: Suggested / Approved / Writing / Ready for
   review / Scheduled / Published w/ live link). Actions: approve, edit title/keyword,
   reschedule (move), skip, add topic (inline suggestions w/ volumes). Empty states
   self-explaining. Status pills use existing band colors; RTL-safe.
3. **Article review screen** — rendered preview, **SEO score ring** (existing
   `<x-score-gauge>` component) + check list (✓/✗ with plain-language labels), images
   gallery (regenerate single image, upload replacement, edit alt), inline title/meta
   edit, buttons: Approve & schedule / Publish now / Request new draft.
4. **Settings tab** — everything from the wizard + internal-link URLs, image style,
   custom instructions, integration management (re-verify, disconnect).
5. Website-overview hub gains a **Content** tab pill (pattern:
   `WebsiteOverviewController::tabStatus()`).
6. i18n: `lang/en.json` + `lang/ar.json` keys throughout; compiled-Tailwind check for
   every new utility class (`public/build` grep — known landmine).

---

## 11. Plan gating & billing

- `plans.features.content_autopilot` flag (plans UI checkbox), monthly article cap in
  `api_limits`; `UsageMeter` charges per generated article (content credits:
  existing 400 chars = 1 credit rule via `WriterProjectService::recordCredits`
  equivalent) so autopilot consumption is visible in `/admin/usage`.
- Trial: small taste (5 articles, review-only, no auto-publish) — pushes upgrade
  without draining spend caps.

## 12. Testing (sqlite guard first — always)

- Unit: `ContentSeoScorerTest` (fixture articles → exact scores/issues),
  `HumanizerServiceTest` (lint catches each tell; clean text passes),
  `IdeogramClientTest` (`Http::fake`, expiring-URL download, unsafe-image reject),
  publish drivers (fake HTTP, idempotent re-push, HMAC signature correctness).
- Feature: pipeline end-to-end with faked LLM (`Http::fake` + fake keys via config) —
  topic → article → score → revise loop convergence → publish row; reaper; cadence
  dispatcher claims only due topics; plan-cap enforcement; wizard Livewire tests;
  webhook signature verified by receiver-side test.
- phpunit.xml pins: blank `IDEOGRAM_API_KEY` (+ keep existing blanks).
- Staging QA script: real DeepSeek + real Ideogram (staging caps $1), publish to
  pubgnamegenerator.net WP (plugin QA install), full visual pass via headless Chrome.

## 13. Rollout phases (each = staging → operator approval → prod)

| Phase | Scope | Gate |
|---|---|---|
| **0** | Migrations, models, config, admin settings card, both spend meters, `IdeogramClient` | suite green on staging |
| **1** | Pipeline core: `PlanTopicsJob` → `ResearchTopicJob` → `WriteArticleJob` → `ContentSeoScorer` → revise loop; manual CLI trigger; minimal review screen | 3 real articles ≥85 on staging, style lint clean |
| **2** | Calendar UI + setup wizard + dispatcher/scheduler + reaper + plan gating | full client flow QA on staging |
| **3** | Publishing: WP plugin v2.1 receive endpoint + app-password fallback + webhook driver + post-publish verify | live publish to WP QA install |
| **4** | Ideogram images end-to-end (featured + inline, alt text, gallery UI) | image QA + spend meter visible in ops |
| **5** | Shopify driver, RSS/JSON feed, article translations (reuse locale machinery) | Shopify dev-store QA |
| **6** | Polish: strategy visualization (our take on their mindmap — topic-cluster view from `content_terms`), author box, social share (optional) | operator call |

Worker-box note: new jobs run on box B — every phase's prod deploy = rsync + Horizon
restart both boxes (deployment-and-queues.md), and `.env` additions must hit **both**
boxes + staging (worker-box env-drift landmine, twice bitten).

## 14. Docs & memory (same-change requirement)

New `infra/content-autopilot/` (README + pipeline.md + publishing.md), System-Map row
+ changelog line in `infra/main.md`, `reference/configuration.md` env additions,
`reference/jobs-and-scheduler.md` job/command rows, plugin docs for v2.1 endpoint.
Memory: feature memory file + MEMORY.md line at phase 1 completion.

## 15. Open decisions (operator)

1. Auto-publish default: recommend **review-first ON, 24h window** (their default is
   full-auto; trust is our differentiator) — confirm.
2. Article pricing/packaging: which plans get autopilot + caps per plan (my defaults
   in §11 are placeholders).
3. Ideogram monthly cap start value (proposed $30 prod).
4. Social auto-share (FB/X) in scope later? (phase 6+, needs Meta/X apps).
5. Their "24h delayed publishing" + "publishing window 9–11am" — adopt window
   randomization? (recommended yes: publish at a randomized minute inside the client's
   chosen window; looks organic.)
