# AEO — getting our clients quoted by the AI answers

## Context

Owner (2026-09-26): *"We want to provide AEO to our users. I want a full-fledged
feature for clients."* This is the feature plan, not the code plan.

Decisions taken with the owner before planning:

1. **All three pillars, phased** — measure → optimize → readiness, each phase
   shippable and sellable on its own. One deliberate reordering: the readiness
   checks (robots, llms.txt, who crawled you) *are* the zero-cost measurement,
   so they ship together as Phase 1, and the engine probes — the only part that
   costs anything or can break — come last, once the page they live on exists.
2. **Own infrastructure only, no new paid vendors.** Existing rails only:
   Serper, DeepSeek/Mistral, self-hosted Firecrawl, GSC + GA4, our WP plugin,
   our PHP kit.
3. **Included in Content Autopilot**, no new billing; capacity capped
   internally through `ContentAutopilotConfig` settings.
4. **Brand-level AND article-level**, rolled into one visibility score.
5. Its own `/content/aeo` page; one author + organization entity per site;
   bot-hit logging in both the WP plugin and the PHP kit; the Google
   AI-Overview probe is experimental and labelled as such.

### The honest constraint, stated once

**ChatGPT and Gemini have no scrapeable query URL** — there is no page to
fetch, so without those vendors' APIs we cannot say "you were cited in
ChatGPT". Perplexity and Google AI Overviews *can* be scraped, unreliably.

So this plan does not sell a number we cannot stand behind. It sells three
signals we can prove, at zero new spend:

| Signal | Source | Truth value |
|---|---|---|
| **Which AI bots fetched which of your pages** | our plugin / our kit, first-party | Unarguable |
| **Humans arriving from ChatGPT, Perplexity, Gemini, Copilot** | GA4 `sessionSource`, already synced | Unarguable |
| **Whether the models name you when a buyer asks** | DeepSeek/Mistral with no browsing | Real, and it is what "the model knows you" means |
| Google AI Overview presence | Firecrawl scrape | Best-effort, labelled, off by default |

That combination is defensible and, because of the plugin, it is something a
keyword tool cannot copy.

### What exists today (verified, 2026-09-26)

Zero AEO code — `grep` for AEO/llms.txt/GPTBot/AI Overview across `app/`,
`resources/`, `infra/`, `config/`, `database/` returns nothing. But most of
the machinery is already here and idle:

- `app/Support/Crawler/RobotsTxtParser.php` — `isBlocked($txt, $path, $userAgentTokens)`
  **already supports custom bot tokens**; every caller passes only `['googlebot']`.
  robots.txt is fetched (`SiteIssueDetector::fetchRobotsTxt():151`) and thrown away.
- `app/Support/Audit/HtmlAuditor.php:357` `structuredData()` returns fully decoded
  JSON-LD (handles `@graph`) — but the crawler path keeps only a list of `@type`
  strings (`:323`).
- `analytics_data` (website_id, date, **source**, users, sessions) is already
  populated daily for every website by `SyncAnalyticsData` — AI referral
  measurement needs **no new sync, no new API call**, just a source filter.
  **Checked against production (2026-09-26, read-only): the last 90 days
  already hold 1,110 sessions from `chatgpt.com` across 7 client sites, plus
  `gemini.google.com`, `claude.ai` and `perplexity.ai`** — petrotek.ae 536,
  namesforfreefire.com 441, pubgnamegenerator.net 133. Phase 1 therefore lights
  up with real history on the day it ships, for the 12 sites with GA connected
  (of 48 covered plans) — which also makes "connect GA" the page's own upsell.
- `app/Services/Content/ContentSeoScorer.php` — pure scorer, `VERSION` const,
  rules added through one `$add(code, weight, passed, fixMessage)` closure, and
  the fix message doubles as the LLM revision instruction. The AEO scorer is
  the same shape.
- `app/Services/Content/ContentArticleSchema.php` — emits **FAQPage only**, has
  exactly **one caller** (`WordPressAppPasswordDriver:209`).
- `ContentPlan::toggle('author_box')` exists, defaults false, and is consumed by
  **nothing** — a dead switch waiting for this feature.
- `ArticleReview::seoKit():568` already builds Article JSON-LD that **no driver
  ever publishes** — copy-paste only.
- `resources/snippets/php/articles/index.php:102` — our PHP kit already emits a
  BlogPosting + BreadcrumbList `@graph`. We own that template.
- `docs/architecture/30-simulation.md:231` already specifies an AEO formula
  (short definition / FAQ-HowTo JSON-LD / question H2s / outbound citations /
  clear author-date). Nobody built it. Phase 2 builds it and goes further.
- Patterns to copy: `CheckTrackedSerp` → `CheckTrackedKeywordSerpJob` (weekly
  per-site dispatcher, `ShouldBeUnique`), `KeywordTrackerQuota` (capacity),
  `ContentRankGainsMail` + its one-per-site-per-day `Cache::add` throttle,
  `ContentRankHistoryService` + inline-SVG charts (no chart library),
  `imageHealthLine()` in `SendFailedJobsAlert` (the silent-failure alarm).

---

## The feature, in the client's words

> **AEO — are the AI answers recommending you?**
> - *Can the AI engines even read your site?* Which AI crawlers you allow, which
>   you block by accident, and whether you have an `llms.txt`.
> - *Are they actually reading it?* GPTBot fetched 42 of your pages last week.
>   ClaudeBot fetched 11. PerplexityBot was blocked.
> - *Are people arriving from AI?* 38 sessions from ChatGPT this month, 12 from
>   Perplexity.
> - *Do the models know you?* We ask the questions your buyers ask. Last week
>   you were named in 6 of 25 answers — up from 3. Here is who gets named
>   instead of you.
> - *Every article is written to be the answer.* Answer-first paragraphs,
>   question headings, a real author, and full structured data on every page.

---

## Phase 1 — "Can the AI engines read you, and are they coming?" ✅ SHIPPED 2026-09-26

The truth-only phase. No LLM calls, no scraping, nothing that can lie.

**Built:** `App\Support\Aeo\AiAgents`, `AeoReadinessService`,
`AeoSignalReader`, `AeoIngestController` (plugin + HMAC kit doors),
`AuditAeoReadinessJob` + `ebq:aeo-audit` (Tue 06:40), `/content/ai-visibility`
(`App\Livewire\Content\AiVisibility`), the `aeoIngestLine()` stale-reporter
alarm, PHP kit 1.1.0 (hit logging, post-response reporting, llms.txt), and
24 tests in `tests/Feature/Aeo/` plus `tests/fixtures/php-kit/aeo-kit-check.php`.

**WordPress plugin v2.1.0** (separate repo) adds `EBQ_Ai_Bot_Logger` and
`EBQ_Llms_Txt`, verified by `tests/ai-visibility-check.php` there and by a live
round-trip against the deployed endpoint.

**Still open in this phase:** publishing the plugin release — that pushes an
auto-update to every installed site, so it is the owner's call, not ours.

**Client-visible:** new `/content/aeo` page with an AEO readiness score, a
per-bot access table (allowed / blocked / never seen), a "who crawled you"
table with page counts, an AI-referral sessions chart, and a fix list.

**Data model (additive only):**
- `content_ai_crawler_hits` — `website_id`, `bot(32)`, `day(date)`, `hits`,
  `pages`, `sample_path(600)`; unique `(website_id, bot, day)`.
- `content_aeo_audits` — `website_id`, `checked_at`, `robots_ai(json)`
  (bot ⇒ allowed|blocked|unknown), `llms_txt_present`, `llms_txt_url`,
  `schema_coverage(json)`, `readiness_score`, `breakdown(json)`.
- No new table for referrals — read `analytics_data` filtered by `source`.

**Services / jobs (each extends or copies something that exists):**
- `App\Support\Aeo\AiAgents` — the one source of truth: bot UA tokens (GPTBot,
  OAI-SearchBot, ChatGPT-User, PerplexityBot, Perplexity-User, ClaudeBot,
  Claude-User, Google-Extended, CCBot, Bytespider, meta-externalagent,
  Applebot-Extended), their referral hostnames, display names, and whether each
  is *training* or *live retrieval* — the distinction clients will ask about.
- `App\Services\Content\Aeo\AeoReadinessService` — fetches robots.txt and
  `/llms.txt`, evaluates each bot with the **existing** `RobotsTxtParser`
  (pass real tokens instead of `['googlebot']`), reads schema coverage from
  `website_pages.seo_signals.schema_types`, returns score + findings.
- New finding category `CrawlFinding::CATEGORY_AEO` with types
  `ai_crawler_blocked`, `llms_txt_missing`, `article_schema_missing`,
  `author_entity_missing`, emitted from `SiteIssueDetector` — this makes them
  appear in the existing Site Health queue for free (4 known UI touch points in
  `CrawlReportService`: category card `:119`, summary `~:985`, fix `~:1040`,
  why `~:1095`).
- `POST /api/v1/posts/report-ai-hits` — a near-copy of the existing
  `PluginInsightsController::report404s():798` (`routes/api.php:68`): same
  website-token resolution, same `max:200` batch validation, idempotent upsert
  instead of a job dispatch.
- **WP plugin (separate repo, own release) — this is a clone of a class that
  already ships.** `includes/class-ebq-404-tracker.php` already buffers hits in
  a non-autoloaded `wp_option`, matches user-agents, sends one batch on a WP
  cron, and **only clears the buffer on a confirmed-OK response** so an API
  outage loses nothing. The AI-bot logger is that class with a different match
  list and endpoint, plus serving `/llms.txt` generated from published posts.
- **PHP kit (ours):** same logging in `resources/snippets/php/serfix/lib.php`,
  plus `llms.txt` written alongside the article index. ⚠️ Bumping
  `SERFIX_KIT_VERSION` means **existing installs must re-download** — the kit
  needs a visible "update available" prompt on the Integrations page.
- `ebq:aeo-audit` weekly command + `AeoAuditWebsiteJob` (copy `CheckTrackedSerp`
  exactly: dispatcher → `ShouldBeUnique` job on `Queues::SYNC`).

**UI:** route `/content/aeo` (`['feature:content','content.access']`),
`App\Livewire\Content\AeoDashboard`, blades under
`resources/views/livewire/content/aeo/`. Score donut copied from
`pages/partials/audit-report.blade.php:19-29`; sessions chart copied from
`keyword-rank-history.blade.php` (inline SVG, no chart lib). Nav entry beside
Site Health with a "New" badge.

**Coverage, honestly:** the robots/llms audit and the GA-referral chart work for
every site with no install. Bot-hit logging only reaches sites running our
plugin or our kit — production has 9 connected destinations today (4 WordPress,
5 webhook), so the page must be built to be useful *without* hit data and to
treat it as the bonus it is, with a clear "install to see who crawled you" state.

**Cost per site per week:** two HTTP fetches, one DB read, one API POST a day
from the client's own server. Effectively zero.

**Failure modes made visible:** an audit that cannot fetch robots.txt records
`unknown`, never `allowed`; a site whose plugin has not posted hits in 7 days
while its integration is CONNECTED raises a line in `ebq:failed-jobs-alert`
(copy `imageHealthLine()`); the page says "no data yet" rather than "0 hits",
because those are different claims.

---

## Phase 2 — "Make every article the answer"

The phase that improves output for every client immediately, with no probes.

**Client-visible:** an AEO score beside the SEO score on every article, with
plain-language fixes; a real author and organization on every published page;
full structured data everywhere we can reach.

**Data model (additive):**
- `content_authors` — `website_id`, `name`, `role`, `bio(1000)`,
  `credentials(300)`, `avatar_url`, `same_as(json)`, `is_default`.
- `content_plans` gains `org_legal_name`, `org_logo_url`, `org_same_as(json)`.
- `content_articles` gains `aeo_score`, `aeo_issues(json)`, `schema_json(json)`
  (persisted so a republish ships exactly what was audited).

**Services:**
- `App\Services\Content\Aeo\AeoScorer` — same shape as `ContentSeoScorer`
  (pure, `VERSION`, `$add()` closure, fix messages usable as revision
  instructions). Rules: direct answer within the first 40–60 words; question-
  shaped H2/H3 share; every section self-contained (no opening pronoun that
  refers to the previous section — this is what breaks chunk retrieval); a
  definition block; at least one statistic with an attributed source; outbound
  citations; FAQ present as real Q/A; comparison table or list; visible author
  and date; scannable paragraph length. The docs' formula
  (`30-simulation.md:231`) is the starting weight set.
- `ContentArticleProducer` — `aeoRules()` appended in `templateInstructions()`
  (`:1605`, beside `onPageSeoRules():1838`); the revision loop already consumes
  scorer fix messages, so AEO issues drive revisions with no new loop.
- `ContentArticleSchema` — extended from FAQPage-only to a full `@graph`:
  Article/BlogPosting, FAQPage (existing parser), HowTo when steps are
  detected, **Person** (the author entity), **Organization** (legal name, logo,
  `sameAs`), BreadcrumbList, and Speakable pointing at the answer block.
- **Delivery to every destination**, not just WordPress: `_ebq_schemas` for WP
  (already wired); the PHP kit renders the shipped graph instead of rebuilding
  its own; `schema_json` added to the generic webhook payload; and for the
  no-head platforms (Shopify, Webflow, Wix, HubSpot, Sanity, Medusa) a shared
  helper appends an inline `<script type="application/ld+json">` to the body
  HTML. ⚠️ Some platforms strip `<script>` from post bodies — this must be
  verified per driver during implementation, with the result recorded in
  `infra/content-autopilot/README.md`, not assumed.
- The dead `author_box` toggle finally does something: renders the author box
  from `content_authors`, and drivers that accept an author name send it.

**UI:** an AEO panel beside the SEO checklist in `ArticleReview` (reuse
`checkLabel()/checkHint():1316/:1356`); author + organization form in Content
Settings; `seoKit()` starts emitting the real graph instead of a stub.

**Cost:** zero extra API calls. The scorer is pure PHP; the prompt additions
ride the write call we already pay for.

**Failure modes:** an article that scores below the AEO floor is revised, not
published silently; a missing author entity is a Site Health finding, not an
invented person.

---

## Phase 3 — "Are you the answer?"

**Client-visible:** the visibility score with history — "named in 6 of 25 buyer
questions, up from 3" — the list of questions where a competitor is named
instead of you, and an **Answer this** button that puts that question on the
content calendar.

**Data model (additive):**
- `content_aeo_questions` — `website_id`, `question(300)`,
  `source(brand|article|gsc|llm)`, `topic_id` nullable, `is_active`,
  `added_by_user_id`. Row count is the quota meter (the
  `content_tracked_keywords` pattern).
- `content_aeo_runs` — `question_id`, `engine`, `ran_on`, `brand_mentioned`,
  `brand_rank`, `competitors(json)`, `cited_urls(json)`, `answer_excerpt`,
  `ok`, `error`; unique `(question_id, engine, ran_on)`.
- `content_aeo_scores` — `website_id`, `date`, `score`, `breakdown(json)`.

**Services:**
- `App\Services\Content\Aeo\BrandRecallProbe` — asks DeepSeek/Mistral the buyer
  question with **no browsing**, strict JSON out, and records which brands were
  named and in what order. This measures what the model *knows*, which is
  exactly what "the AI recommends you" means when there is no retrieval.
  `__unmetered => true` + `ContentLlmSpendMeter::add()`, as every content call
  does.
- `App\Services\Content\Aeo\AiOverviewProbe` — **experimental, off by default,
  admin-enabled per site.** Firecrawl fetch, AI-Overview block parsed for
  citation links, `FirecrawlBudget`-capped. Every failure is a stored row with
  a reason, never a silent gap, and the UI labels it "best effort".
- Question sourcing, all free: question-shaped GSC queries, the
  `people_also_ask` list **already stored on every topic's brief**
  (`content_topics.brief`, written by `AiContentBriefService:168`), the plan's
  offerings, and an LLM pass for buyer questions — the client edits the list.
  (Note: `rank_tracking_snapshots.serp_features` also holds PAA, but only for
  SEO-platform tracked keywords, which content-only clients do not have.)
- Visibility score = bot access + hits (25) · AI referral sessions (25) · brand
  recall (35) · AI-Overview presence (15, **renormalized away when unavailable**
  — the same weight-renormalization `ContentSeoScorer:373` already does, so a
  missing signal never reads as a zero).
- `ebq:aeo-probe` weekly + `AeoProbeWebsiteJob`; digest mail
  `ContentAeoDigestMail` with the per-site-per-week `Cache::add` throttle and a
  `content.aeo.alerts.enabled` kill switch.
- **The loop:** a question where competitors are named and we are not becomes a
  one-click topic through the existing `TopicComposer::create()` — the AEO page
  feeds the calendar, which is the reason to keep paying.

**Cost per site per week:** 25 questions × 1 model ≈ **$0.004**. A hundred sites
is about 40 cents a week. The AI-Overview probe, when enabled, spends Firecrawl
proxy bandwidth against its existing budget.

**Failure modes:** probe rows carry `ok`/`error`; `ebq:failed-jobs-alert` gains
an AEO line when a site's probe failure rate crosses a threshold or a week
passes with no runs — this codebase has lost weeks to quiet failures twice
(Ideogram 401, DeepSeek 402) and this feature will not be the third.

---

## What a paid vendor would add later (design for it, don't build it)

Define `App\Services\Content\Aeo\Contracts\AnswerEngine` in Phase 3 with one
method (`ask(question, locale): AnswerResult{brands, citations, raw}`) and the
brand-recall probe as its first driver. Then adding Perplexity Sonar (real
citation lists), a SERP vendor's AI-Overview endpoint (reliable AIO), or
Gemini grounding later is a new driver and a settings row — no rework of the
schema, scoring or UI.

## Invariants and landmines this must respect

- Additive migrations only — production MariaDB has no backups.
- `__unmetered => true` on every content-product LLM/SERP call, or it eats the
  dashboard meter (`content-llm-usage-isolation`).
- Prebuilt Tailwind: `npm run build` and verify new classes land in the emitted
  CSS, or they render as nothing.
- New routes require `route:cache` on deploy — the Research page and rank
  history both shipped broken on this.
- Client copy never exposes internal states or reason codes, and never presents
  a scraped-AIO gap as "you lost a citation".
- The WP plugin is a separate repo with its own release cycle; the PHP kit
  version bump forces a re-download for existing installs.
- Dual-host rule if any wizard step is added (`ContentCalendar` +
  `Concerns/ContentWizard` must both carry it).

## Verification

1. **Phase 1:** unit tests for `RobotsTxtParser` against real robots.txt files
   that allow/deny each AI bot; a feature test posting crawler hits through the
   HQ endpoint with a website token (and a cross-tenant token, which must 403);
   the audit job against a site known to block GPTBot. Then the live check —
   install the updated plugin on the QA WordPress site, hit it with a GPTBot
   user-agent, confirm the hit surfaces on `/content/aeo` within a day.
2. **Phase 2:** scorer tests per rule (the `ContentSeoScorer` test file is the
   template); a published-article test asserting the `@graph` reaches each
   driver's payload; manual validation of one live published article in
   Google's Rich Results test and Schema.org validator.
3. **Phase 3:** probe tests with a stubbed `LlmClient` (the `TopicComposerTest`
   stub pattern) covering brand named / not named / competitor named / unusable
   JSON; a score test proving a missing engine renormalizes instead of scoring
   zero; a digest-throttle test.
4. Full suite with `config:clear` first (sqlite `:memory:` confirmed), then
   deploy to box D and drive it on one real content site per phase.

## Deliberately out of scope

Claiming ChatGPT or Gemini citations; a public "AI visibility" league table;
writing robots.txt on the client's behalf (we recommend, they apply — the
plugin's write surface is `write_if_empty` for a reason); and any dollar
projection of AI traffic value.
