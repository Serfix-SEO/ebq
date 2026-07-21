# EBQ — Engineering Knowledge Base (entry point)

> **This is the map of the entire application.** If you (Claude or a human) need to
> understand any part of EBQ, **start here** and follow the links. This file is the *spine*:
> it links to every knowledge doc, states the rules that must never break, and defines the
> discipline for keeping all of it true. Depth lives in the linked docs — this file stays a
> map, never a dump.

EBQ is a self-hosted **SEO platform**: it crawls a client's website, pulls their Google
(Search Console + Analytics) data, and turns both into findings, growth reports, keyword &
rank tracking, backlink/competitive intelligence, an AI content suite, and a WordPress-plugin
API. Laravel 11, PHP 8.3, MariaDB + Redis, two-box deploy (see the topology docs).

---

## ⛔ The maintenance protocol — the whole point of this file

This knowledge base is only useful if it stays true. **Whenever you work on EBQ you are
responsible for keeping it current.** Treat documentation as part of the task, not an extra.

**WHEN to update (triggers):**
1. **You changed code / schema / config / architecture** → update the affected subsystem doc
   to match the new reality, *in the same change*. A stale doc is worse than no doc.
2. **You learned something non-obvious** — a gotcha, a runtime fact, *why* it's built this
   way, a production incident, a tuning value → write it in the relevant doc.
3. **You built a new subsystem / feature** → create `infra/<area>/…` docs for it and add a row
   to the System Map below.
4. **You found the docs wrong or outdated** → fix them now and note the correction.

**HOW to update (rules):**
- **Code-grounded.** Verify against the actual code before writing; cite `file:line` /
  class / method. Never document from memory or assumption alone.
- **One fact, one place.** Link, don't copy. Never paste subsystem detail into this file.
- **Date the time-sensitive.** Incidents, "as of", tuning numbers → absolute dates.
- **Edit, don't pile up.** Correct the existing line rather than stacking contradictions.
- **Keep the index honest.** Update the System Map + the "Where docs are thin" list whenever a
  doc/subsystem is added, renamed, removed, or changes coverage.
- **Add a Knowledge Changelog line** (bottom of this file) for any architectural change or new
  doc.
- **Mirror durable, session-spanning facts** into project memory (`MEMORY.md`) too — that
  layer survives even when the repo isn't open.

**If a task is finished but the docs would now be wrong or incomplete, the task is not done.**

---

## How to navigate

- **Starting any task:** read this file, then open the doc(s) for the subsystem you're
  touching. Together they are the full picture.
- **"Read main.md"** = read this + follow the links relevant to the task.
- **Authority order:** repo `infra/` docs (code-grounded) > project memory (`MEMORY.md`,
  operational/session facts) > the code itself (ground truth — when in doubt, read it).

---

## System map — every subsystem

**Status:** ✅ documented · 🟡 partial · ⬜ code-only. As of **2026-06-16 the whole
application is documented** — keep it that way (see the protocol). Each area links its
`README.md`; sub-docs are listed after the arrow.

### Platform & infrastructure ✅
- **Conceptual topology, queues, deploy procedure, rollout postmortem** →
  [deployment-and-queues.md](./deployment-and-queues.md)
- **Live server inventory** (both boxes: hardware, OS, Apache/FPM, MariaDB, Redis, ports,
  integrations, risks) → [server-deployment.md](./server-deployment.md)
- **DB safety rules** (prod, no backups) → repo-root `CLAUDE.md`; memory `never-destructive-db-data`

### Database sharding — full-ULID, multi-node 🟡 (built on a branch, not merged)
[sharding/](./sharding/README.md) — three tiers behind one routing layer: central (identity/billing/
catalogs) + **tenant shards by owner** (`websites.db_node_id`) + **crawl shards by domain**
(`crawl_sites.crawl_node_id`). Whole schema re-keyed to **ULID**; cross-tier FKs dropped (app-enforced
via `ShardCleanup`); admin-managed `db_nodes` fleet (`ebq:db-node` + `/admin/db-fleet`, clones the crawl
fleet) + a tenant/crawl **mover** (`ebq:shard`, validated on MariaDB). On branch
`feature/db-sharding-ulid`; single-node behaviour is unchanged until a node anchor is set. Plan:
repo-root `SHARDING_PLAN.md`.

### Crawler — the heaviest subsystem ✅
[crawler/](./crawler/README.md) → architecture · data-model · pipeline · read-path ·
findings-and-scoring · adjacent-systems · operations · known-issues
— **fairness** (`pages_per_pass`) interleaves sites so no big domain monopolises the queue;
the **`ebq:crawl-supervisor`** watchdog (every 5 min) recovers wedged multi-pass chains;
[autoscaling.md](./crawler/autoscaling.md) — elastic worker fleet on Hetzner (Phase 1 shipped:
`worker_nodes` + `ebq:fleet-worker`; the queue is central so new boxes just pull, no rebalance).

### Data sources — Google & Microsoft ✅
[data-sources/](./data-sources/README.md) → google-oauth · sync-jobs · data-model
— GSC is the **only** search-data source; Microsoft = Outlook mail only (no Bing ingestion).
The GSC/GA degradation rule covers all 4 presence combos.

### Keywords & rank tracking ✅
[keywords/](./keywords/README.md) → keyword-finder (self-hosted Google-Keyword-Planner fleet) ·
keyword-research · rank-tracking

### Backlinks, competitive & SERP ✅
[competitive/](./competitive/README.md) → backlinks · serp
— `serp_cache` is **cross-tenant** (keyed by query+gl); cross-network aggregates fail closed
below a 5-site cohort.

### Reports, action queue & anomaly ✅
[reports/](./reports/README.md) → insights · action-queue · growth-reports · client-report
— `ActionQueueService` merges crawl findings + GSC reports + rank drops + audits into one
ranked queue. (`GenerateAiInsights` is still a stub.) Branded PDF exports (Growth Report +
the crawler's Site Audit) both go through `ReportBranding`/`ReportBrandingResolver`
(plan-gated `report_whitelabel`) + dompdf — see "Site Audit PDF export" in
[crawler/known-issues.md](./crawler/known-issues.md).

### AI suite ✅
[ai/](./ai/README.md) → tools · writer · llm
— LLM is **Mistral only** today (`LlmClient` multi-provider is aspirational). Writer pipeline
is synchronous/in-request.

### Audits & performance ✅
[audits/](./audits/README.md) → page-audit · lighthouse-and-performance ·
live-score-and-language · topical-authority — external Lighthouse service; SSRF-guarded fetches.

### WordPress plugin & HQ API ✅
[wordpress-plugin/](./wordpress-plugin/README.md) → **server side:** hq-api · releases —
auth is a **Sanctum token per Website**; the HQ API reads GSC/`ReportDataService` only,
**no raw crawl tables**. **Client side:** plugin-source · plugin-features — the EBQ SEO
plugin codebase (42 PHP classes + React build) is a **separate git repo** checked out at
`/var/www/ebq/ebq-wordpress-plugin/` (gitignored; **never commit it here**), calling the HQ
API via an `EBQ_Rest_Proxy`; core on-page output works offline.

### Content Autopilot — auto content calendar 🟡 (Phases 0–1 built, staging)
[content-autopilot/](./content-autopilot/README.md) — evidence-driven topic ideation
(GSC striking-distance + business profile + cannibalization guard) → AI writing (reuses
the Writer's `draft()` v25, DeepSeek-preferred) → **deterministic `ContentSeoScorer` +
`HumanizerService` anti-AI-detection lint** → targeted revision loop → versioned
articles. `ebq:content-autopilot` heartbeat (reap/top-up/claim), `content` queue on the
heavy pool, two admin-only spend breakers. Publishing/images/client UI are Phases 2–4.
Plan: repo-root `AUTO_CONTENT_CALENDAR_PLAN.md`.

### Guest (public, lead-gen) tools ✅
[guest-tools/](./guest-tools/README.md) — rank / pagespeed / volume / audit; shared
request→queued-job→email-link→results pattern, reCAPTCHA + rate limits + lead capture.

### Billing, plans & usage ✅
[billing/](./billing/README.md) → plans-and-gating · usage
— billing is **per-USER** (not per-website), **yearly only**; caps + feature flags gate
features; `client_activities` + `UsageMeter` track spend.

### Accounts, onboarding, teams ✅
[accounts/](./accounts/README.md) — auth (login errors as banner), Google SSO + source connect,
website selection, teams via `website_user` + `TeamPermissions` (null = full access).

### Admin panel ✅
[admin/](./admin/README.md) — `is_admin` gating + per-Livewire-action re-check, impersonation,
marketing crawl-report sends, proxies, keyword servers, platform settings. (Crawler panel →
crawler/operations; Plugin/Plan/Billing panels → their own subsystem docs.)

### Frontend / UI ✅
[frontend/](./frontend/README.md) → livewire-patterns · [i18n-and-rtl](./frontend/i18n-and-rtl.md)
— Livewire 3 + Alpine + Tailwind 4 + Vite 7. **No full-page Livewire routes** (Blade
`Route::view` embeds `<livewire:…>`); the **active website is session state**
(`current_website_id`, propagated via `website-changed`). App-wide English/Arabic (RTL) i18n
complete as of 2026-07-07 — admin panel stays English-only.

### Cross-cutting reference ✅ (the horizontal layer — `infra/reference/`)
| Doc | Covers |
|---|---|
| [reference/database.md](./reference/database.md) | All **83 tables** grouped by domain + the 49-model index, FK semantics, migration conventions, hash/encrypt patterns |
| [reference/routing.md](./reference/routing.md) | Consolidated **endpoint map** across web/api/auth/channels + `bootstrap/app.php` |
| [reference/http-and-auth.md](./reference/http-and-auth.md) | Middleware, the two guards (session vs **Sanctum per-Website**), authorization, request lifecycle |
| [reference/jobs-and-scheduler.md](./reference/jobs-and-scheduler.md) | All **25 jobs** + **17 commands** + schedule, with a **destructive-commands safety** section |
| [reference/configuration.md](./reference/configuration.md) | All **16 `config/*.php`** + consolidated `.env` knobs (secrets marked) |
| [reference/mail-and-wiring.md](./reference/mail-and-wiring.md) | The 9 mailables + Postal transport, providers, observers/listeners, container bindings |
| [reference/testing.md](./reference/testing.md) | The test suite + **⛔ safe-test-running** (the sqlite guard / prod-wipe story) |
| [reference/staging.md](./reference/staging.md) | The **staging box** (10.0.0.4, staging.serfix.io): isolated full-stack QA env, deploy via `scripts/deploy-staging.sh`, isolation guarantees |

> Co-located non-EBQ apps share Box A: **Postal** (mail), **Jitsi/Prosody** (meet.ebq.io
> video; booking app in `/var/www/marketing` — memory `meet-video-bookings`). Detail in
> server-deployment.md.

---

## Cross-cutting invariants & safety (never break these)

1. **Production DB, no backups, binary logging off — data loss is permanent.** No
   `migrate:fresh/refresh/rollback`, `db:wipe`, `ebq:demo-data` destructive modes, or raw
   `DROP/TRUNCATE` without explicit per-command confirmation. Tests must resolve to sqlite
   `:memory:` (the `TestCase` guard — do not weaken it). See `CLAUDE.md`. (Engine is
   **MariaDB 10.11** via Laravel's `mysql` driver.)
2. **Two-box deploy in lockstep.** A shared-schema migration hits both boxes instantly; the
   worker box runs bind-mounted code pushed by **rsync** (not git) and must be restarted.
   Changing a queued job's identity (`uniqueId`/constructor) requires both boxes to match or
   locks leak. [deployment-and-queues.md](./deployment-and-queues.md) · live state in
   [server-deployment.md](./server-deployment.md).
2b. **STAGING-FIRST (2026-07-17).** Every change deploys to the staging box first
   (`scripts/deploy-staging.sh` → QA at staging.serfix.io) and goes to production
   **only after explicit operator approval** — no direct prod deploys, no trivial-fix
   exceptions. [reference/staging.md](./reference/staging.md).
3. **FPM opcache `validate_timestamps=0`** → a code change needs a *full* `php8.3-fpm`
   restart, not a reload; long-running `queue:work` needs `queue:restart` / container restart.
4. **Crawler per-user scoping** — shared crawl data is exposed only through `CrawlReportService`
   (cap window + ignore/resolve overlay + read-time GSC impact). Shared findings store
   `impact = 0`. [crawler/read-path.md](./crawler/read-path.md).
5. **PHP must be 8.3** on both boxes (8.5 breaks queued-closure serialization).
6. **Redis is the single store for cache + all queues** (`noeviction` policy — eviction would
   drop jobs). **Don't purge the shared `sync` queue** — it carries unrelated GA/GSC jobs.
7. **Use `/root/.ssh/id_ed25519_worker`** for the worker box — never repurpose other
   services' credentials.
8. **Use the code-review-graph MCP tools first** for exploration (per `CLAUDE.md`).
9. **Client-facing copy: never expose internal state or plumbing.** No "cached" /
   "served from cache" / "cache warming", no credit/cost mechanics, no vendor names
   (Keywords Everywhere, DataForSEO, Moz, Serper…), no "we're rebuilding / outdated /
   migration in progress" in ANY client-visible string (UI, emails, banners, empty
   states, errors). Cached and fresh results must be indistinguishable to clients —
   a cached result just appears fast, with the same wording as a fresh one (e.g. the
   gap teaser says "keywords collected" for both). Neutral, forward-looking copy only
   ("Coming soon", "temporarily unavailable"). Admin-only surfaces (`/admin/*`) are
   internal and MAY show cache/cost/vendor detail. Repeated user correction
   (2026-07-10 "rebuilding" copy, 2026-07-13 "cached" pills, 2026-07-14 "served from
   cache" in the gap teaser) — treat as a hard rule, not a style preference.

---

## Glossary (key entities & terms)

- **crawl_site** — one row per normalized domain; owns the shared crawl. Many `websites` link
  to it via `crawl_site_id`.
- **value_rank / cap window** — dense page rank in the shared value ordering; reads filter
  `value_rank <= the owner's plan cap`.
- **effective_cap** — max page cap among a crawl_site's subscribers; the crawl runs to this.
- **website_finding_states** — per-user open/ignored/resolved overlay on shared findings.
- **client_activities / UsageMeter** — usage log + monthly spend windows (provider, units,
  billed to the website owner). The crawl `crawl_reuse` charge lives here.
- **sync / crawl / interactive / default** — the four Redis queues (`Support/Queues`).
  crawl + sync run on the worker box; interactive + default + schedule on the web box.
- **Postal** — self-hosted SMTP relay all mail goes through (`MAIL_MAILER=postal`).
- **Mistral** — the only live LLM provider (`MISTRAL_API_KEY`).

---

## Project memory layer

Session-spanning operational facts also live in **project memory**
(`~/.claude/projects/-var-www-ebq/memory/`, indexed by `MEMORY.md`) — e.g. GSC/GA
degradation, keyword-finder limits, email-via-Postal, FPM 504 tuning. Where a memory note and
a repo doc overlap, **the repo doc is authoritative**; migrate durable architecture facts from
memory into the right `infra/` doc over time and leave the memory note as a pointer.

---

## Where the docs are still thin (deepen as you touch these)

The whole app is mapped, but some areas are summarized rather than exhaustive — and a few
known gaps were flagged during the sweep:

- **Admin panel** — `admin/README.md` summarizes the panels; individual screens aren't each
  fully detailed. Expand the one you touch.
- **Stubs / partial features** — `GenerateAiInsights` is a placeholder; `research_limits` is
  declared but not enforced (billing); `LlmClient` multi-provider is aspirational.
- **All items below this line were fixed in one pass, 2026-07-06** (each has its own
  changelog entry + subsystem-doc detail; kept here only as an index of what to
  re-check if this area is touched again):
  - Crawler cap-window leak (`LinkStructurePanel` example-pages picker) — fixed via
    `CrawlReportService::topInboundPages()`.
  - Billing `assertCanSpend()` non-atomic race — fixed via a Redis reservation
    (`UsageMeter::reserve()`/`release()`).
  - Guest-tools PageSpeed lead mis-tagging — fixed (`Lead::SOURCE_GUEST_PAGESPEED`).
    The cookie-friction "bypass" flagged alongside it turned out to be intentional
    design (lead-gen, not a paywall) — left alone on purpose, not a bug.
  - Audits — content-hash re-audit gate added (`PageAuditReport.content_hash` +
    `ebq:recheck-audit-content`, hourly).
  - Data-sources null-vs-empty `gsc_site_url` — audited, no live bug found (every
    read-path already handles it correctly); confirmed in `data-sources/data-model.md`.
  - `research.rollout` dangling middleware alias — removed (`bootstrap/app.php`),
    nothing referenced it.
  - `EnsureFeatureAccess` fail-open on an unknown feature key — removed the bypass;
    `TeamPermissions::allows()` already denied safely without it.
  - CI not running on push to `main` — added `main` to `tests.yml`'s push branches.
  - Orphaned `content_briefs` table — dropped (confirmed 0 rows, no references,
    user confirmed before the drop).
  - Prod `APP_DEBUG=true` — flipped to `false`.
  - Every fix above has a regression test (`tests/Feature/*`) — this codebase had
    **zero** test coverage for any of these paths before 2026-07-06.

---

## Knowledge changelog

- **2026-07-21 (public onboarding lost the wizard profile + a 3rd phpunit
  prod-leak)** — A registrant who ALREADY owned the onboarded domain ended up on
  a bare stub plan: `ContentOnboardingConverter::convert` folds into the existing
  site and deletes the provisional one, `content_plans.website_id` is ON DELETE
  CASCADE, and an empty `$profile` (documented SSO round-trip case) then copied
  nothing. The calendar still filled because `ContentTopicPlanner` treats
  `business_description` as optional. Fixed with `carryOverProfile()` + 3
  regression tests — see
  [content-autopilot/product-billing.md](./content-autopilot/product-billing.md).
  Found while testing: **phpunit pinned the content API keys but not
  `CONTENT_IMAGES_DISK`**, so `GenerateContentImagesJob` wrote test images into
  the REAL Hetzner bucket (same leak class as the 2026-07-11 KE and 2026-07-14
  DataForSEO incidents — phpunit only isolates what it overrides). `phpunit.xml`
  now pins the disk to `public` and blanks every `CONTENT_S3_*` var; this also
  fixed the 2 long-"known-failing" `ContentImagesTest` cases, which had been
  asserting against `Storage::fake('public')` while the bytes went to prod.
- **2026-07-20 (content images: 3 stacked silent failures, prod)** — Ideogram
  key 401 + worker box B's ~1 Mbit inbound cap (6 MB downloads timing out) +
  `content_s3` writing into the void because `CONTENT_S3_ENDPOINT`/`_URL` were
  never set and the disk had `throw => false`. Fixes: new key on both boxes;
  **`content` queue moved from box B to box A** (`config/horizon.php`
  `local`/`production` gain `worker-content`, `worker` loses it); S3 endpoint +
  public URL added to both `.env`s; disk flipped to `throw => true`
  (`config/filesystems.php`) so paid-artifact storage can never fail silently.
  Full post-mortem incl. the box-B network evidence and the missing
  `league/flysystem-aws-s3-v3` on box B:
  [content-autopilot/README.md](./content-autopilot/README.md).
- **2026-07-19 (Email verification grace window)** — Verification is no longer
  enforced at signup. `User` still implements `MustVerifyEmail` (mail still sent),
  but the `verified` middleware alias is overridden in `bootstrap/app.php` to
  `EnsureEmailVerifiedAfterGrace`: unverified users pass for
  `config('auth.verification.grace_days')` days (default 3, env
  `EMAIL_VERIFICATION_GRACE_DAYS`) from `created_at`, then are forced to
  `verification.notice`. Registration redirects to `onboarding` within the window.
  Docs: [accounts/README.md](./accounts/README.md). Tests:
  `tests/Feature/EmailVerificationGraceTest.php`.
- **2026-07-17 (Content Autopilot Phases 0–1)** — New subsystem
  [content-autopilot/](./content-autopilot/README.md): auto content calendar
  (getautoseo.com competitor; plan at repo-root `AUTO_CONTENT_CALENDAR_PLAN.md`).
  Six `content_*` tables (versioned articles, encrypted integration creds),
  `ContentTopicPlanner` (GSC-grounded ideation + cannibalization guard),
  `ContentArticleProducer` (brief→`AiWriterService::draft`→score→targeted revise
  loop), pure `ContentSeoScorer` + `HumanizerService` (anti-AI-detection prompt
  contract + deterministic lint, admin-editable banned-phrase list),
  `ebq:content-autopilot` 15-min heartbeat, new `content` queue on the heavy pool
  (redis-long), `IdeogramClient` (v3, expiring URLs) + `Ideogram`/`ContentLlm`
  spend breakers (shared `MonthlySpendMeter` base), Content Autopilot card on
  `/admin/settings`, `IDEOGRAM_API_KEY` blanked in phpunit. Also fixed a stale
  `TrialCleanupTest` expecting pre-redesign onboarding copy.
- **2026-07-17 (staging environment + cost/spend controls)** — Built the isolated
  **staging box** (`10.0.0.4`, cx33, staging.serfix.io via box-A reverse proxy +
  basic auth; own MariaDB/Redis, log mail, sandbox DataForSEO, no fleet/Stripe
  keys) — [reference/staging.md](./reference/staging.md), deploy via
  `scripts/deploy-staging.sh`; Horizon gained a `staging` env. Same day: link
  crawler made perpetually self-feeding (recrawl requeue + organic expansion +
  graph-backfill seed — recrawl was silently dead), DataForSEO report cost cut
  ~30% (`BacklinkSampleAggregator` complete-profile local aggregation + Labs
  competitors row cap 300), **global monthly spend circuit-breaker**
  (`DataForSeoSpendMeter`, admin-only degradation), solo explorer window fixed
  1h→24h, Stripe unknown-price→NULL interval fix + coverage, full test suite
  de-staled (793 green). Docs: [reports/client-report.md](./reports/client-report.md).
- **2026-07-16 (Trust Score / Citation Score — own authority metrics)** — New
  0–100 TF/CF-analogue scores computed deterministically from data already in
  the report payload (zero new provider cost). Pure
  `app/Services/Reports/AuthorityScoreCalculator.php` + curated
  `config/trusted_seed_domains.php`; wired compute-on-write
  (`ClientReportService::assemble()`/`assemblePartial()`) + backfill-on-read
  (`withTraffic()` choke point) with deliberately NO `PAYLOAD_SCHEMA` bump so
  cached snapshots gain scores without paid regeneration. UI: `/backlinks`
  ring cards + band pills, `/competitors` pill, report web/PDF 6-up gauges.
  Never label these "Trust Flow"/"Citation Flow" (Majestic trademarks).
  Same change also shipped: **Common Crawl web-graph SQLite sidecar**
  (`ebq:import-cc-webgraph`, 121M domains, harmonic+PageRank percentiles feed
  formula v2), **Topical Trust enrichment** (`EnrichTopicalTrustJob`, one LLM
  call, topics cached forever in `domain_metrics`), **domain-intelligence
  asset** (`domain_metrics`+`domain_metric_history`, monthly
  `ebq:refresh-domain-metrics` free-feed sweep, churn-proof), and **Tier-1
  passive link graph** (`EdgeRecorder` → `link_domains/link_urls/link_edges`
  from crawls/enrichment; worker box B needs deploy+Horizon restart).
  Details: `infra/reports/client-report.md` § "Trust Score / Citation Score".

- **2026-07-13 (post-signup landing hub + Priority Action Queue false "all caught
  up" — follow-up to the queued-window fix above)** — Two changes:
  1. **New post-signup landing page.** `ConnectGoogle::finishOnboarding()`
     (`app/Livewire/Onboarding/ConnectGoogle.php:207`) now redirects to
     `route('website-overview')` instead of `dashboard`. New
     `WebsiteOverviewController` (`app/Http/Controllers/WebsiteOverviewController.php`)
     + `resources/views/website-overview.blade.php`: a single website-scoped page with
     a top tab bar — **Site Explorer** (auto-generates the DataForSEO/Moz backlink
     report for the site's own domain, no typing needed, via the SAME resolve logic
     as the standalone `/report/view` page — factored out to
     `ReportViewController::resolve()` + a shared partial `reports/_status.blade.php`),
     **Site Health** (embeds the existing `crawl-banner` /
     `dashboard.site-health-stats` / `dashboard.priority-action-queue` Livewire
     components unchanged), **Traffic Statistics** (GA4) and **GSC Performance**,
     each showing a real, checked pill — `processing` / `needs_action` / `ready` —
     computed in `WebsiteOverviewController::tabStatus()` from the SAME signals the
     rest of the app already uses (`isInitialCrawl()`, `hasGa()`/`hasGsc()`,
     `AnalyticsData::exists()`, `ReportDataService::lastSafeReportDate()` — never
     inferred from whether a cache happens to be warm). The old dashboard
     "just_onboarded" welcome modal is removed (dead: the flash flag is now
     consumed by the hub page instead).
  2. **Real regression from the queued-window fix, caught by a failing test.** The
     broadened `isInitialCrawl()` (queued window, up to 6h on ANY brand-new site,
     even one with zero crawl activity) made `PriorityActionQueue`'s outright
     `@unless($hide)` card-hiding **far more aggressive** than before — it now hid
     the ENTIRE queue, including GSC/rank-tracking-derived items (cannibalization,
     `rank_drops`, `quick_wins`, etc.) that don't depend on crawl state at all, for
     up to 6 real hours on a brand-new site. Caught via
     `tests/Feature/Dashboard/ActionQueueTest.php`'s
     `component renders groups linking to issue detail page` (a tracked rank-drop
     keyword on a not-yet-crawled site read as "You're all caught up" — literally
     the class of bug reported). Fix: the queue is **never hidden outright** anymore.
     `ActionQueueService::groupedActions()` gained `$includeCrawlIssues` (default
     true) — `PriorityActionQueue::render()` passes `false` while
     `isInitialCrawl()` is true, so crawl_* groups are excluded (not final yet) but
     everything else still shows. Empty state is now three-way: **"Crawl in
     progress"** (still initial), **"Still finalizing your results"** (crawl
     completed <60s ago — `recentlyFinishedCrawl()`, a settling grace window for any
     residual cache-propagation lag), or genuine **"You're all caught up"**. Cache
     key bumped `v3`→`v4` (includeCrawlIssues is now part of the key — a site
     flipping in/out of its initial-crawl window must not read the other state's
     cached shape); `WarmDashboardCaches` computes the same flag from the same
     `isInitialCrawl()` check. **Landmine hit twice while building this**: the
     first two implementations passed `$crawlInitial` directly where
     `$includeCrawlIssues` was expected (backwards — should be `! $crawlInitial`)
     in both `PriorityActionQueue::render()` and `WarmDashboardCaches::handle()`;
     both silently produced empty-but-not-hidden queues and were only caught by
     re-running the full affected test list, not by `php -l`/`view:cache`. Tests:
     `ActionQueueTest`, `CrawlAndDashboardFixesTest`, `LinkStructureTest`,
     `WarmDashboardCachesTest` (existing tests updated, not new ones — they'd
     encoded the old hide-everything behavior as correct). Docs:
     [reports/action-queue.md](./reports/action-queue.md),
     [reports/client-report.md](./reports/client-report.md).

- **2026-07-13 (live external links false-flagged `broken_external` on transport
  timeouts)** — Client bestproservicesdubai.com reported 5 "broken" outbound links
  that were all live (UAE-gov `*.mohre.gov.ae` + `wam.ae`, all `cURL error 28`
  8s-HEAD timeouts). Root cause: `LinkChecker`'s GET+proxy fallback only ran for
  403/405/429/501 statuses — a transport-level HEAD failure got `status = null` with
  no GET retry — and `SiteIssueDetector::detectBrokenExternalLinks()` treated null
  (couldn't-verify) identically to a real 4xx/5xx. Fix: GET+proxy fallback now also
  covers transport errors (15s last-chance timeout); `LinkChecker` rows carry a
  `guard_blocked` flag (deterministic malformed-URL rejection = reliably broken);
  `broken_external` is raised ONLY on a confirmed `status >= 400` OR `guard_blocked`,
  never on a bare null (logged `crawler.broken_external.unverifiable_skip`). Positive
  evidence required to call a link dead. Worker-box change (rsync + Horizon
  `--force-recreate`, no FPM restart). 5 false positives resolved directly; genuine
  malformed link kept. Test in `CrawlerPipelineTest`. Docs:
  [crawler/known-issues.md](./crawler/known-issues.md),
  [crawler/findings-and-scoring.md](./crawler/findings-and-scoring.md).

- **2026-07-13 (new client saw "all caught up" instead of "crawling in progress")** —
  A brand-new client landing on the dashboard right after signup saw the Priority
  Action Queue's empty state ("You're all caught up") with **no crawl banner**, even
  though their first crawl was queued. Root cause: the "initial crawl in progress"
  signal was derived purely from a **RUNNING `CrawlRun` row**, which
  `CrawlWebsitePagesJob` only creates once it starts on the worker
  (`app/Jobs/CrawlWebsitePagesJob.php:92`). `CrawlSiteBootstrapper::subscribeWebsite`
  dispatches a `SyncSitemaps → CrawlWebsitePagesJob` chain, so between subscribe and
  that job running (queue latency / sitemap sync / worker backlog) `isCrawling()` and
  `hasCompletedCrawl()` are both false → banner blank + queue empty. Fix: broadened
  `Website::isInitialCrawl()` (`app/Models/Website.php:689`) to also return true in the
  **queued window** — no completed crawl AND the `crawl_site` was created <6h ago (age
  bound so a never-started crawl can't spin forever). `CrawlBanner` now renders a
  "We're setting up your site" stand-in for that window and polls 10s (to catch the run
  starting); `SiteHealthStats`'s `$partial` gate aligned to `isInitialCrawl()`. Note:
  `crawl_sites.status` (pending|crawling|ready|blocked) is **decorative** — nothing
  branches on it, so the fix keys off run-state + crawl_site age, not status. Tests:
  `tests/Feature/CrawlAndDashboardFixesTest.php` (4 new). Web-only UI change (FPM
  restart, no worker rsync needed — the worker never calls `isInitialCrawl()`). Docs:
  [reports/action-queue.md](./reports/action-queue.md) § Gotchas.

- **2026-07-11 (blog-post wizard: full input coverage — language/H1/LSI/strategy
  selections; writer prompt v25)** — owner QA found the writer ignored most wizard
  inputs: `AiWriterService::draft()` never consumed `language/country/tone/audience`
  (Arabic projects wrote English), strategy `keyword_suggestions`/`faqs` never
  reached the article, LSI misses were only logged, and the H1 was the raw project
  title. v25: OUTPUT-LANGUAGE hard block (translate SERP-language brief/PAA;
  keyword/LSI/proper nouns verbatim), top-level `"h1"` output (user's Strategy pick
  locked verbatim, else keyword-front ≤65 chars in the output language →
  `generated_h1`; consumers use `h1 → generated_h1 → title`, incl. plugin WP
  `post_title`), secondary-keywords + curated-FAQ prompt blocks (FAQ section now
  triggers on PAA OR curated FAQs), one corrective LSI retry + coverage persisted
  to `generation_meta` (shown on review). Strategy step gained an H1 card
  (`h1`/`h1_suggestions`, direct LLM call, `AI_WRITER_STRATEGY_H1` credits), LSI
  suggestions (`AiRelatedKeywordsService`), and a `keyword_data` volume/
  competition/trend map via `KeywordMetricsService::metricsOrQueue` (cache-first,
  provider-respecting, never blocking, never $). Cache key bumped
  `ai_writer_v24`→`v25` WITH locale+selections in the key. Migration
  `2026_07_11_150000` (main + shard node). Gotcha: a heredoc line starting with
  `USER-` terminated the `<<<USER` prompt heredoc — PHP treats `USER` followed by
  a non-identifier char as the closing marker. Follow-up (same day, owner re-test
  "didn't generate arabic fully"): the STRATEGY tools had the same bug one layer
  down — `AiToolRunner` passed `language` through (`:178`) but every registry
  tool's prompt ignored it (English meta/FAQs/keywords on Arabic projects). Fixed
  at the base: `AbstractAiTool::execute` appends a hard OUTPUT-LANGUAGE system
  rule for any known non-English code — covers all 47 Studio tools + the wizard
  bundle in one seam; tool cache keys already vary by language (input hash).
  THIRD layer same day (owner: "still didn't write in arabic", reading the brief
  step): `WriterProjectService::generateBrief` hardcoded `country/language => null`
  → `AiContentBriefService` briefed the US/English SERP and its prompt had no
  language rule. Fixed: project locale → Serper `gl`/`hl` + output-language rule;
  brief cache `v3`→`v4` with a language segment (`cachedBrief()` signature gained
  `$language`). Pattern to remember: locale inputs flowed through every layer's
  PLUMBING but were dropped at each consumption point — when adding a localized
  surface, grep for where the language string is actually interpolated into a
  prompt, not just passed. Two follow-on landmines: (1) the tool cache
  (`AiToolRunner::cacheKey`) hashed `language` all along, so pre-fix ENGLISH
  outputs sat cached under Arabic keys — key namespace bumped `ai_tool:` →
  `ai_tool:v2:`; note prompt edits do NOT change the input hash, so a prompt fix
  can silently serve pre-fix cached output (bump or forget the exact key when
  verifying). (2) `keyword-suggestions` needed special handling: keywords are
  search DATA — real GSC queries must stay verbatim (never translate a query
  nobody types), so the prompt asks for a MIX (≥half native-language variations
  alongside the strongest cross-script queries). The owner's live Arabic
  projects' briefs + strategies were regenerated server-side after the fix.
  Docs: `infra/ai/writer.md`, `infra/ai/tools.md`.

- **2026-07-11 (blog-post wizard: async generation + progress UI, both surfaces;
  plugin 2.0.12)** — "Generate article" was one blocking 240–360s HTTP request on
  the dashboard (no spinner at all) and a 280s `wp_remote_post` through the plugin
  proxy (shared hosts kill those). Now: POST generate queues
  `App\Jobs\GenerateWriterDraftJob` → 202; both UIs poll `GET …/generate-status`
  (4s) and render a staged progress panel (elapsed-based stages, "you can leave
  this page" — true, the job keeps running; reopening a project resumes the poll).
  New `writer_projects` columns `generation_status/error/started_at` (migration
  run on main + shard node); `failStaleGeneration()` self-heals rows orphaned by a
  lost job; job passes `__user_id` so queued LLM calls stay metered (they'd be
  unbilled otherwise — no Auth user in a worker); plugin API keeps the blocking
  path for pre-2.0.12 installs (`async=1` opt-in). Dashboard: Alpine state machine
  + shared `wizard-steps/partials/generation-progress.blade.php`; plugin:
  `useGeneration.js` hook + `GenerationProgress.jsx` (also fixed
  `NEVER_CACHE_ROUTE_PREFIXES` listing `/hq/writer-projects` while routes register
  as `/writer-projects`). E2E'd live: queue job on prod project, API 202/status
  parity via temp token, headless-Chrome regenerate on the QA install. Docs:
  `infra/ai/writer.md`, `infra/wordpress-plugin/releases.md`. Tests:
  `tests/Feature/WriterProjectAsyncGenerationTest.php` (gotcha: fresh-DB tests
  must set `global_feature_flags` — `FEATURE_DEFAULTS['ai_writer']` is false).

- **2026-07-11 (DeepSeek as second LLM provider, admin-switchable)** — extracted
  `OpenAiCompatibleClient` base from `MistralClient` (error codes byte-identical),
  added `DeepSeekClient` (32k output-token clamp — the V3-era 8k limit is gone on
  V4 and originally truncated the writer, JSON-mode "json"-in-prompt nudge,
  `deepseek-reasoner` denylisted — no function calling/JSON mode), new
  `LlmProviderConfig` (Setting `ai.llm.provider`) + `LlmClientFactory` behind the
  container binding, `AiModelConfig` now per-provider (legacy `ai.llm.model` stays
  Mistral's; `premiumModel()`/`visionModel()` replace the hardcoded
  `mistral-medium-latest`/`pixtral` call sites). Alt-text vision pins Mistral when
  DeepSeek is active (DeepSeek has no vision model). **LLM tokens pool**: deepseek
  shares `plans.api_limits.mistral.monthly_tokens` (consumption summed across both
  providers, reservation key canonicalized) so a provider flip mid-month can't
  reset quotas. Admin settings: provider select + per-provider model selects
  (activating a keyless provider is refused). phpunit now blanks
  `MISTRAL_API_KEY`/`DEEPSEEK_API_KEY` (same landmine class as the KE leak).
  Docs: `infra/ai/llm.md` (rewritten), `infra/ai/README.md`,
  `infra/reference/configuration.md`. Tests: `tests/Unit/Llm/*`,
  `tests/Feature/LlmProviderSwitchTest.php`. **Thinking mode**: live-probing
  found DeepSeek V4 (`deepseek-v4-flash`/`-pro` are the real model ids;
  `deepseek-chat`/`deepseek-reasoner` are aliases) supports
  `thinking:{type:enabled}` per request WITH json_object + function calling —
  wired as per-call option `reasoning => true`, routed to exactly one site
  (AI Writer full draft); all interactive paths stay non-thinking by design
  (latency + reasoning-token cost). Reasoner aliases stay out of the admin
  dropdown.

- **2026-07-11 (plugin keyword detail page + a live test-suite landmine: tests were
  billing the real Keywords Everywhere API)** — HQ Keywords tab (`ebq-hq-keywords`)
  had no per-keyword view. Server: extracted the portal `/keywords/{query}` signal
  gathering out of `Livewire\Keywords\KeywordDetail` into
  `app/Services/KeywordDetailService.php` (component delegates; zero parallel logic)
  and exposed it as `GET /api/v1/hq/keyword-detail` (`PluginHqController::keywordDetail`,
  tenancy via the token website only, 422 on bad `query`, raw CPC but never $
  projections). Plugin 2.0.11 (built, NOT yet published): `KeywordDetailView.jsx` —
  query click on `GscKeywordsTab` → in-tab deep-dive (metric KPIs, GSC charts/pages/
  countries/devices, tracker history via the existing history endpoint, PAA/related,
  `ebq_site`-hinted portal deep-link); proxy route in `NEVER_CACHE_ROUTE_PREFIXES`.
  **Landmine found while testing**: phpunit.xml never blanked
  `KEYWORDS_EVERYWHERE_API_KEY`, and `QUEUE_CONNECTION=sync` runs
  `RankTrackingKeywordObserver`→`FetchKeywordMetricsJob` inline — so every test
  creating a `RankTrackingKeyword` made a **real, credit-billed KE HTTP call** and
  overwrote seeded metrics with live data (a seeded 12000 came back 22200). Key now
  pinned blank in phpunit.xml (same philosophy as the REDIS_DB pin). Tests:
  `tests/Feature/Api/V1/PluginHqKeywordDetailTest.php`,
  `tests/Feature/Keywords/KeywordDetailPageTest.php` (first coverage of the portal
  detail page). Same release, second owner-QA fix: the HQ **Overview** Site-issues
  digest card was still crawl-only ("site issues not all showing") — now merges the
  GSC-derived groups via shared `src/hq/searchIssues.js` (see the parity rule in
  plugin-features.md), same for Site Audit → Overview ("Where to start" + total KPI);
  verified live on the QA install (26 crawl + 55 search data = 81, matches the portal
  action queue). Two more owner-QA finds fixed in 2.0.11: 2.0.10's insight deep-links
  pointed at unregistered `page=ebq-hq-seo_performance` (first section lives on the
  parent slug) → WP "not allowed"; and ALL legacy-slug redirects were dead because
  admin.php's menu access check `wp_die`s before `admin_init` — redirects now also
  hook `admin_page_access_denied`. Owner bug report (screenshot) same round:
  cannibalization listed the same URL twice per row — GSC reports www/non-www +
  scheme/trailing-slash variants as distinct pages; `buildCannibalizationReport`
  now merges variants via `canonicalPageKey()` (stats summed, impressions-weighted
  position, variant-only "splits" dropped, cache v1→v2; fixes portal AND plugin —
  [reports/insights.md](./reports/insights.md)). Follow-up ("why 0% pages?"):
  those were Google **sitelinks** (identical impressions + identical position, 0
  clicks) masquerading as competitors — v3 filter keeps a competing page only if
  it took clicks or holds ≥10% of the query's impressions. Also removed the plugin's
  "$ At stake"/"$ Upside" columns (no-$-projections rule; portal was already
  clean). Everything E2E-QA'd via headless Chrome on pubgnamegenerator.net
  (2.0.11 installed over SSH). Details:
  [wordpress-plugin/releases.md](./wordpress-plugin/releases.md). Docs: [wordpress-plugin/hq-api.md](./wordpress-plugin/hq-api.md) ·
  [wordpress-plugin/plugin-features.md](./wordpress-plugin/plugin-features.md) ·
  [wordpress-plugin/releases.md](./wordpress-plugin/releases.md) ·
  [reference/testing.md](./reference/testing.md).
- **2026-07-11 (test suite: 42 pre-existing failures → 0; two production hardenings
  shipped with it)** — Full-suite run surfaced a 42-failure backlog (none caused by
  current work; verified by baseline diff). Root causes, largest first:
  (1) `UserFactory` defaulted `email_verified_at = null` while the app routes sit
  behind `verified` → ~14 HTTP tests 302'd; factory now verified-by-default
  (stock Laravel), `->unverified()` for the rest. (2) `SetLocale`→`LocaleConfig`
  (07-09 kill switch) queries `settings` on EVERY request → 7 tests without
  RefreshDatabase 500'd; **prod hardening**: `LocaleConfig::multilingualEnabled()`
  now fails safe (English) on a missing table — protects fresh deploys pre-migrate.
  (3) sqlite date-string artifact (already documented in testing.md) **fixed at the
  model**: `SearchConsoleData::setDateAttribute()` normalizes to `Y-m-d`.
  (4) **Prod hardening #2**: `CrawlReportMail` crashed on a Website with no loaded
  owner (`owner->locale`) — nullsafe now. The rest were stale tests updated to
  current behavior: Serper `base_url` config key + usage-logging DB dependency,
  marketing-copy assertions, plugin scheduling requires a ZIP upload,
  free→trial tier rename, `#[Lazy]` component placeholders, GrowthReportMail is
  queued not sent, registration lands on verify-email, DomainRateLimiter's raw-Redis
  fixed window, PriorityActionQueue slide-over→SiteIssues page (×2 files),
  content_terms-based link suggester, seo_signals-gated issue detector, derived
  indexing verdicts. Full landmine list: [reference/testing.md](./reference/testing.md)
  § Notable patterns.
- **2026-07-11 (WP-plugin deep-links opened the wrong website — `ApplyWebsiteHint`
  middleware + plugin 2.0.8/2.0.9)** — Owner QA on a multi-website account: every
  plugin link into the portal that wasn't a signed embed (WP dashboard-widget insight
  cards; editor-sidebar rank-tracking / custom-audit / page-audits / settings links)
  rendered whatever website the Serfix session last had selected. Two mechanisms:
  (1) widget cards now fetch the existing signed embed URL at click time (2.0.8;
  `manage_options`-gated — signed embeds log in as the website OWNER, never hand
  them to editors); (2) new **`ApplyWebsiteHint`** web middleware
  (`bootstrap/app.php`, before `ResolveShardContext`) honors `?ebq_site=<domain>`
  by switching `current_website_id` **only among the user's accessible websites** —
  unsigned by design; all raw plugin links now append it (2.0.9,
  `src/sidebar/utils/portalUrl.js`). Tests: `tests/Feature/ApplyWebsiteHintTest.php`.
  Docs: [reference/http-and-auth.md](./reference/http-and-auth.md) ·
  [wordpress-plugin/releases.md](./wordpress-plugin/releases.md).
- **2026-07-10 (WP plugin v2.0.0 — full rebuild, published, awaiting coming-soon flip)** —
  Platform: first token-authorized JSON surface over the shared-crawl subsystem —
  `Api/V1/PluginCrawlController` (6 read-only `/api/v1/hq/site-audit/*` routes over
  `CrawlReportService`, `hq`-flag gated, GSC-optional) and
  `Api/V1/PluginKeywordFinderController` (async ideas/volume over the keyword fleet,
  cache-first, 10 dispatches/website/day; poll warms the monthly ideas cache with a
  server-recomputed key — never client-supplied). ebq.io Apache vhosts now 308 (not 301)
  `/api/*` + `/wordpress/*` so old installs' POSTs survive the domain migration.
  Plugin (v2.0.0, published as the first-ever `plugin_releases` row): native Site Audit
  + Keyword Finder HQ tabs, `DEFAULT_BASE` → serfix.io with a versioned upgrade routine,
  full orange rebrand (violet/indigo/legacy-blue swept to zero), "AI Studio (Beta)",
  Prospects tab deleted, and a **shipped-1.0.5 bug fixed**: submenu `$hook` names embed
  the sanitized parent menu TITLE (`serfix-hq_page_…`), so the EBQ→Serfix menu rename
  had silently broken bundle enqueue on every HQ section page / AI Studio / Settings
  (all checks now suffix-match `_page_{slug}`). E2E-verified on a throwaway Docker WP
  against production data (falik.com crawl, real fleet keyword lookup, upgrade path,
  update offer). Download stays 404 until `WP_PLUGIN_COMING_SOON=false`. Tests:
  `tests/Feature/Api/V1/Plugin{Crawl,KeywordFinder}ApiTest.php` (seed `PlanSeeder` —
  factory users resolve the trial plan row for the `hq` flag). Docs:
  [wordpress-plugin/hq-api.md](./wordpress-plugin/hq-api.md),
  [plugin-features.md](./wordpress-plugin/plugin-features.md),
  [releases.md](./wordpress-plugin/releases.md); plan at `/var/www/ebq/WP_PLUGIN_V2_PLAN.md`.
- **2026-07-10 (in-app bug reports with snip screenshots)** — "Report a bug" button in the
  portal top bar → Livewire modal (`app/Livewire/BugReportModal.php`): description, prefilled
  page link, optional snipping-tool region capture. Capture =
  `resources/js/bug-report-capture.js` (lazy Vite chunk) + `modern-screenshot`
  (html2canvas rejected: crashes on Tailwind 4 oklch). **Gotcha discovered**: Alpine/Livewire
  attribute names (`@click`, `x-bind:class`, `wire:model`) are invalid XML — XMLSerializer
  emits broken SVG and foreignObject captures come back fully transparent (black JPEG);
  fixed by stripping `[@:]` attributes in `onCloneEachNode`. Storage: `bug_reports` table
  (central, ULID) + private `storage/app/bug-reports/`; admins notified by synchronous mail
  (`BugReportSubmitted`) and manage at `/admin/bug-reports` (list/screenshot/resolve —
  `Admin\BugReportController`). Rate limit 5/user/hour in-component. Tests:
  `tests/Feature/BugReportTest.php`. Details: [admin/README.md](./admin/README.md) § Bug reports.
- **2026-07-09 (multilingual kill switch — Arabic OFF by default)** — All non-English
  languages are now disabled unless an admin enables them: new **Settings → Languages**
  toggle (`admin/settings`, setting `locale.multilingual_enabled`, default false) read via
  `app/Support/LocaleConfig.php`. When off, `SetLocale` forces `config('app.locale')`,
  the first-visit picker and EN/AR switchers hide, `/locale/{locale}` 404s non-default
  locales, and every Mailable clamps its stored locale through `LocaleConfig::resolve()`.
  Stored `users.locale`/cookies survive for re-enabling. Details:
  [frontend/i18n-and-rtl.md](./frontend/i18n-and-rtl.md) § Admin kill switch.
- **2026-07-07 (public site mobile menu — was never built)** — Reported: "menu not
  visible on mobile." Root cause: `components/marketing/page.blade.php`'s header nav was
  `hidden … md:flex` with no mobile fallback at all — no hamburger, no drawer — so
  Features/Pricing/Guide/Contact/WordPress/FAQ plus Sign in/Dashboard/locale toggle were
  simply gone below 768px on all 7 public pages (landing/features/pricing/contact/guide/
  wordpress-plugin/website-revamp, which all share this one component). Added a
  hamburger + slide-down panel, `md:hidden` throughout (matching the desktop nav's
  `md:flex` exactly — a mismatched breakpoint reopens the same gap). Verified visually
  via headless Chrome at a 390×844 viewport. Deployed: `npm run build` +
  `systemctl restart php8.3-fpm` (opcache SHM, full restart required — see
  CLAUDE.local.md). Details: [frontend/README.md](./frontend/README.md) § Mobile nav.
- **2026-07-07 (automatic crawl-completion email)** — Every clean crawl completion now
  auto-emails each subscriber website's owner the same crawl-issues report the admin
  Marketing panel sends manually (`CrawlReportMail`), via new `SendCrawlReportEmailsJob`
  dispatched from `AnalyzeSiteJob`'s success path. Skips 0-open-findings sites; not
  throttled beyond the natural 3–30d adaptive recrawl cadence. Extracted the report-building
  logic (`emailReportPayload`/traffic snapshot) out of `MarketingController` into
  `CrawlReportService` so manual and automatic sends share one implementation. Details:
  [crawler/adjacent-systems.md](./crawler/adjacent-systems.md) § Crawl-completion email.
- **2026-07-07 (keyword node crash-hardening + stuck-request reaper)** — First hour of
  concurrency 2 surfaced a fatal: tab death mid-`waitForEvent('download')` → unhandled
  rejection → whole node process died → in-flight jobs lost with no failure webhook → rows
  stuck `running` again. Node hardened (fatal-guard handlers + pre-attached catch on the
  download promise); app side gained the long-missing reaper:
  `ebq:reap-stuck-keyword-requests` every 10 min fails `queued`/`running` rows >15 min old.
  Details: [keywords/keyword-finder.md](./keywords/keyword-finder.md).
- **2026-07-07 (keyword node concurrency — page-per-job shipped, but box reverted to 1)** —
  Ahead of public launch, `keywordfetcher` was rewritten for N-way concurrency
  (page-per-job on the shared logged-in context + context-launch mutex, `QUEUE_CONCURRENCY`
  env). Mechanism verified (2 overlapping jobs, ~1.7×) — but on this 2-vCPU box (shared
  with Horizon, swiftshader rendering) ~50% of concurrent jobs hit 30–45s interaction
  timeouts, so Node 1 runs `QUEUE_CONCURRENCY=1`. Throughput path: second node w/ own Ads
  account, or more vCPUs. Details/rollback:
  [keywords/keyword-finder.md](./keywords/keyword-finder.md) § Node internals.
- **2026-07-07 (keyword-finder webhooks broken by domain migration — worker box env drift)** —
  Worker box B's `.env` still had `APP_URL=https://ebq.io` after the 07-06 serfix.io
  migration; Horizon-dispatched keyword requests sent an ebq.io `webhook_url`, the node
  fleet's POST got 301'd and re-issued as GET (405), and `KeywordApiRequest` rows stuck
  `running` (no reaper). Fixed box B env (APP_URL/APP_PUBLIC_URL/MAIL_FROM/GOOGLE_REDIRECT),
  restarted `ebq-horizon-1`, recovered stuck rows by re-POSTing the same `request_id`.
  Second finding: the node fleet **ignores** the per-request `webhook_url` — the real target
  is `WEBHOOK_URL` in the node's own `.env`; fixed to serfix.io + service restart, and the
  ebq.io vhosts keep a method-preserving `R=308` rule for `/webhooks/*` ahead of the 301
  catch-all as defense-in-depth. Bonus: Node 1 turned out to run **on box B itself**; full
  node internals (systemd/xvfb/relay-proxy/concurrency design) now documented in
  [keywords/keyword-finder.md](./keywords/keyword-finder.md) § Node internals.
  Rule: domain/env changes must be swept on **both** boxes.
- **2026-07-07 (app-wide English/Arabic i18n + RTL — complete)** — Full translation +
  RTL layout across marketing, auth/onboarding, the entire customer dashboard, guest
  lead-gen tools, transactional emails, and PDF exports (~180 files). Admin panel stays
  English-only (excluded at the `SetLocale` middleware level). New doc:
  [frontend/i18n-and-rtl.md](./frontend/i18n-and-rtl.md). Two structural fixes worth
  knowing about beyond the blade-wrapping itself: (1) queued Mailables don't inherit
  the recipient's locale by default (`app()->getLocale()` on a worker reflects nothing
  useful) — fixed via `Mailable::locale()` + a new `locale` column on the 4 guest_*
  tables, captured at request time; (2) `plans.features` / `BacklinkType::label()` /
  `CrawlReportService::typeLabel()` are dynamically-built strings invisible to the
  `__()` harvest script — their full known value sets are manually seeded into
  `lang/*.json`. Full MT-pipeline gotcha list (placeholder-token corruption at scale,
  recurring mistranslations, stale-Tailwind-build trap) lives in project memory
  (`i18n-mt-pipeline-gotchas.md`), kept current after every batch.

- **2026-07-07 (DB was on the 128MB stock buffer pool — tuned + covering index; 27×
  on the heavy aggregates)** — User asked if Apache/MariaDB had headroom. MariaDB was
  running the **out-of-box 128MB `innodb_buffer_pool_size`** while serving a 2.2GB
  dataset (98.4% hit rate but 67M disk reads/19 days — every big GROUP BY stormed
  disk). New `/etc/mysql/mariadb.conf.d/60-ebq-tuning.cnf`: `innodb_buffer_pool_size=2G`
  (whole dataset resident; box has 7.6GB, co-hosted Postal/Jitsi/FPM accounted),
  `innodb_log_file_size=512M` (nightly upsert bursts), `tmp_table_size`/
  `max_heap_table_size=64M`. **`innodb_flush_log_at_trx_commit` left at 1 on purpose —
  no backups on this server, durability is not tradeable.** That fixed the disk half;
  EXPLAIN showed the remaining seconds were plan-shaped: the aggregate indexes weren't
  covering, so ~650K matched rows each did a PK lookup. Added
  `scd_wid_date_cov (website_id, date, country, clicks, impressions)`
  (migration, `ALGORITHM=INPLACE, LOCK=NONE`, 25s build) — KPI sums / country GROUP BY /
  traffic chart now run index-only. Cold-path results on the largest account:
  country group-by **98s → 3.6s**, KPI sums **26s → 0.97s**, traffic chart
  **17s → 0.92s**. Users still read the 24h warmed cache (~25ms); this makes the warm
  job + all ad-hoc reads cheap. Apache assessed: mpm_event + FPM max_children=40 fine.
  ⚠ Noted, not changed: Jitsi's two JVMs allow `-Xmx3072m` each — if a big conference
  ever spikes them concurrently with the now-2G buffer pool, the box can overcommit;
  revisit if meet.ebq.io usage grows. Octane/Swoole evaluated and rejected — the
  bottlenecks are query work not framework boot, and per-request singletons
  (`ShardContext` shard routing!) make long-lived workers a tenant-bleed hazard.

- **2026-07-06 (latest — statistics windows were lag-blind; anchored to real data
  days)** — User: statistics counts look wrong for namesforfreefire.com. Verified the
  math was exact for its window — the WINDOW was wrong: GSC finalizes ~3 days late,
  and every statistics aggregate anchored to "yesterday", so the "30-day" windows
  silently contained 2-3 EMPTY lag days. On namesforfreefire that hid 17.5K clicks
  (276,359 shown vs 293,853 real for 30 actual data days), biased every
  previous-period comparison (30 full previous days vs ~27 current — the corrected
  clicks delta is +24.7%), and drew a fake end-of-chart cliff (trailing zero days,
  labeled "Last 30 complete days"). **Fix**: windows now anchor to
  `ReportDataService::lastSafeReportDate()` (last day WITH finalized data, fallback
  yesterday) via a shared `statsWindowEnd()` — applied to `KpiCards::payload`,
  `TrafficChart::payload`, and ReportDataService's `buildTopCountriesTrend`,
  `buildContentDecay`, `buildIndexingFailsWithTraffic`, `quickWins` (90d). Previous
  windows are equal-length by construction. **Honest UI**: KPI row shows the real
  window ("Jun 4 – Jul 3 · vs the 30 days before · Search Console data lags ~3
  days"); the traffic chart label is the actual date range, not "complete days".
  Note: the Settings → "Search Console window" (28d) is the PAGE-AUDIT keyword
  window by design, not the statistics window — statistics is fixed 30-data-day.
  Verified live: 30/30 data days in window, payload == ground-truth sums, chart tail
  real. **Extended to the dashboard page (07-07)**: `resolveRange()`'s default end
  (drives cannibalization + striking-distance → InsightCards counts AND the Priority
  Action Queue) and `CrawlReportService::userClicks()` (28d per-user impact ranking)
  now use the same anchor. CountryFilter's 90d distinct-country window deliberately
  left on "now" — 3 lag days out of 90 can't change a country list. Test-suite gotcha fixed along the way: computing expected cache keys AFTER
  `travel(30)->minutes()` crosses UTC midnight near 00:00 and shifts derived dates —
  compute keys before travelling. Docs: [reports/README.md](./reports/README.md).

- **2026-07-06 (DB-shards tab rebuilt as a live panel)** — User: shard page
  confusing (who lives where?), wants per-tenant/per-site row counts, the move form
  should disable the subject's current host node, and the tab's 10s
  `window.location.reload` loop replaced with real polling. Built
  `Livewire\Admin\DbShardPanel` (`wire:poll.5s`) replacing the static tab body in
  `admin/fleet/index.blade.php`: expandable per-node resident lists (tenants + their
  websites; crawl sites) with total row counts per resident (all shard-tier tables
  summed on the hosting node — computed on expand only, cached 1h, per-resident ↻
  refresh button), host-aware move form (subject options labeled "· on {node}",
  current host disabled as a target + server-side re-check in `move()`), and the
  shard-moves progress panel nested inside. Old JS (`OPTS` dropdown + reload
  interval) and the controller's `moveOptions` removed. The provisioning-defaults
  settings form stays OUTSIDE the component on purpose — plain inputs inside a
  polling Livewire component get wiped by morphs. Anchor-NULL tenants/sites display
  as residents of the pinned primary (matches ShardContext routing). 5 tests
  (`tests/Feature/Admin/DbShardPanelTest.php`). Docs:
  [sharding/README.md](./sharding/README.md).

- **2026-07-06 (dashboard cache auto-warming after syncs)** — Closes the
  cold-path gap left by the earlier caching fix (below): the FIRST visitor after a
  sync version-bump paid the full cold aggregate (~2min worst case on the biggest
  account). Now `App\Jobs\WarmDashboardCaches` pre-computes every /dashboard +
  /statistics card payload right after the bump. **Zero-drift design**: each card's
  cached payload was refactored into a `public static payload()` on the component
  itself (identical key + closure); render() and the warmer both call the same
  static, so the warm key can never diverge from the read key. Warmed: action-queue
  (both ReportCache+RankCache versions), country-filter, KPIs, insights (counts+PPC,
  all-countries view), quick-wins (owner's plan limit), seasonality, top-countries,
  traffic-chart (owner's timezone — teammates in other timezones pay their own rare
  cold path), and `CrawlReportService::actionGroups`. Dispatched from
  `SyncSearchConsoleData` (end of run, not per-window), `SyncAnalyticsData`, and
  `AnalyzeSiteJob::flushSubscribers` (per subscriber); `ShouldBeUnique(5min)`
  collapses the back-to-back GSC+GA dispatches into one warm. Runs on the SYNC queue
  (worker box, timeout 900) — cache is shared Redis so the web box reads it warm; a
  per-card try/catch means one failure can't cold the rest; frozen sites skipped.
  **Deploy note:** this required the full web→worker rsync + `--force-recreate`
  (two-box invariant — new job class consumed on the worker), which also shipped the
  day's earlier job-level fixes (the `SyncAnalyticsData` ReportCache bump had been
  web-side only until now). 3 new tests (`WarmDashboardCachesTest`) + dashboard/
  link-structure suites green. Docs: [reports/README.md](./reports/README.md).

- **2026-07-06 (shard-move progress tracking: `shard_moves` + live fleet
  panel)** — User: no way to see progress while the DB fleet moves tenants/crawl
  sites between nodes. Correct — `MoveShardJob` was a black box (its own docblock
  said the UI "polls the anchors to see completion"; the June 18 move timeout was
  visible only in `failed_jobs`). Added: `shard_moves` table + `ShardMove` model;
  `ShardMover` now creates a row per move and updates it through `counting →
  copying → verifying → cutover → purging → completed|failed` — totals pre-counted
  per table so the percentage is real, `rows_copied` bumped per 1000-row chunk,
  `current_table`/`tables_done` for position, final per-table counts + error stored.
  Fleet page Database tab gets a **"Data moves"** panel (`Livewire\Admin\ShardMoves`,
  `wire:poll.3s`): progress bar for running moves, last-10 history, failure reason
  with the "source intact until purge / re-run idempotent" reminder.
  `MoveShardJob::failed()` marks the in-flight row failed on worker-kill/timeout so
  nothing sticks at "copying" forever. CLI `ebq:shard` moves get tracking free (the
  mover owns it, not the job). 4 new tests (+ existing sharding scaffold suite still
  green); sqlite `:memory:` test gotcha: a cloned named connection is a separate
  empty DB — share the PDO (`setPdo`) to make source/dest the same database.
  Restarted `ebq-queue-fleet` (runs the mover) + FPM. Docs:
  [sharding/README.md](./sharding/README.md) §Live progress.

- **2026-07-06 (worker-failure visibility: real-time alerting + admin Ops
  dashboard; found & fixed a second stuck site)** — Closes the residual gap from the
  incident below (jobs died silently for 3 days). Three layers, all tested:
  (1) **Real-time capture** — `Queue::failing()` in `AppServiceProvider` (every box,
  incl. ephemeral fleet) buffers each permanent failure into a shared-Redis capped list
  (`App\Support\FailedJobAlertBuffer`, LPUSH+LTRIM 200, never throws). Chose the event
  over polling `failed_jobs` because it's immediate and — critically — writes only to
  Redis: the failing box may be exactly the one whose DB/mail is broken (that WAS the
  incident), while Redis reachability is a given (no Redis → no job to fail).
  (2) **Delivery from the web box** — `ebq:failed-jobs-alert` (scheduled every 15 min)
  drains the buffer + flags crawl_sites with subscribers stuck `pending` >24h (the
  blind spot the crawl supervisor can't see: a job that dies BEFORE creating a
  CrawlRun), mails a `FailedJobsDigestMail` digest to `is_admin` users through the
  local Postal. Empty buffer + no stuck sites = no mail, so no spam; `--dry-run` peeks
  without consuming. Tests: `tests/Feature/FailedJobsAlertTest.php`.
  (3) **Admin Ops dashboard** — `/admin/ops` (`Admin\OpsController`, "Ops" in the
  admin nav): failed jobs last-7-days grouped by job+exception with **Retry all /
  Forget** actions (`queue:retry`/`queue:forget` via Artisan, UUID-validated),
  stuck-pending sites with a **Start crawl** button (frozen sites labeled, not
  kickable), live queue depths (pending/delayed/reserved per queue) + undelivered
  alert-buffer count. Reads `failed_jobs` (persistent) not the Redis buffer (that
  belongs to the mail digest). Tests: `tests/Feature/AdminOpsDashboardTest.php`.
  **Validation while building**: the digest's dry-run immediately caught a REAL second
  victim of the incident below — `pubgnamegenerator.net`, subscribed but never crawled
  since 2026-06-17. Kicked its first crawl: completed, 12 pages, site `ready`. Also
  hardened the test suite: `phpunit.xml` now pins `REDIS_DB=13`/`REDIS_CACHE_DB=14`/
  `REDIS_PREFIX=serfix-testing-` so tests can never touch the production Redis keyspace
  (same philosophy as the sqlite DB guard — previously tests using the Redis facade hit
  prod db 0!). Docs: [reference/jobs-and-scheduler.md](./reference/jobs-and-scheduler.md) ·
  [admin/README.md](./admin/README.md).

- **2026-07-06 (Site Health dead for namesforfreefire: TWO worker-box outages
  found, both fixed)** — User: site health section not loading. Site had **zero crawl
  runs ever** — crawl jobs were dying on the worker box for two stacked reasons:
  (1) **`db_nodes.ebq-db-primary` was registered with `private_ip=127.0.0.1`.** Sharding
  (merged `b504048`) anchors `crawl_sites.crawl_node_id` to that row, and `ShardManager`
  builds each node connection from `private_ip` — `127.0.0.1` resolves to MariaDB on the
  web box but to *nothing* on the worker box (10.0.0.3), so every anchored crawl job
  failed there with `PDOException: Connection refused` (visible in `failed_jobs`, e.g.
  the 02:00 weekly crawl this morning; failures date to ~2026-07-03, the anchor era).
  Fixed: `private_ip` → `10.0.0.2` after verifying the app credentials connect from
  BOTH boxes; `ShardManager::flush()` + FPM restart + worker recreate. **Rule: a
  `db_nodes.private_ip` must be reachable from every box that runs jobs — never
  localhost.**
  (2) **The EBQ→Serfix rename silently split the Redis keyspace.** The default redis
  prefix derives from `APP_NAME` (`config/database.php:178`), so when the web box's
  `.env` changed to `APP_NAME=Serfix` this afternoon, the web box started
  dispatching/reading under `serfix-database-*` while the worker box (still
  `APP_NAME=EBQ`) consumed `ebq-database-*` — every job queued from the web after the
  rename sat invisible to the worker, and the web box's own long-running Horizon
  (booted pre-rename) equally couldn't see newly-dispatched interactive/default jobs.
  Fixed: `APP_NAME=Serfix` on the worker, and **`REDIS_PREFIX=serfix-database-` pinned
  explicitly in `.env` on both boxes AND `.env.worker`** (the ephemeral-fleet template —
  it still said EBQ, so every autoscaled box would have come up split-brained too), so a
  future rename can never split the queues again. Worker container recreated via
  `docker compose --project-directory /var/www/ebq -f /var/www/ebq/docker-compose.worker.yml
  up -d --force-recreate` (the compose file lives in /var/www/ebq on the worker, not
  /root); web `supervisorctl restart ebq:*`. Verified end-to-end: dispatched the
  first-ever crawl for namesforfreefire.com — run `running`, 400+ pages fetched and
  climbing, all queues draining, `summary()` returns live has_crawl/run_status.
  Checked before switching prefixes: no stranded jobs under the old `ebq-database-*`
  queue keys (only 2 pending jobs total, both already under `serfix-*`). **Recurrence
  guards added**: `DbFleetService::assertReachableFromAllBoxes()` rejects loopback/
  link-local/localhost node addresses at registration
  (`tests/Feature/DbFleetNodeAddressTest.php`), and the pinned `REDIS_PREFIX` makes the
  keyspace immune to APP_NAME changes. Residual risk that is NOT closed: worker-side job
  failures are still silent — they land in `failed_jobs` with no alerting, which is why
  this ran unnoticed for 3 days. Docs:
  [deployment-and-queues.md](./deployment-and-queues.md) ·
  [sharding/](./sharding/README.md).

- **2026-07-06 (/dashboard + /statistics slow: caching finally wired to the
  version system, plus a 20s eager-load bug in `summary()`)** — User: both pages take
  too long, should be "cached until fresh data is fetched." Three distinct problems
  found, all fixed with live before/after timings on the biggest GSC account
  (namesforfreefire.com, 1.33M `search_console_data` rows):
  (1) **Every dashboard/statistics Livewire card hardcoded a 600s TTL**, so the heavy
  GSC/GA aggregates re-ran every 10 minutes — and five of the eight cards
  (`CountryFilter`, `InsightCards` ×2 keys, `QuickWinsCard`, `SeasonalityCard`,
  `TopCountriesCard`) had **no `ReportCache::version` in their keys at all**, meaning a
  completed sync did NOT refresh them until TTL expiry either — worst of both worlds
  (recompute every 10min AND stale up to 10min after a sync). All eight now use the
  intended design from `ReportCache`'s own docblock: 24h sanity TTL + version-keyed
  keys. Cold costs these avoid re-paying: country group-by **98s**, KPI aggregates
  **26s**, traffic chart **17s** on the big site.
  (2) **`SyncAnalyticsData` never bumped `ReportCache`** (only the GSC sync did), so GA
  numbers in KPI/traffic cards stayed stale after a GA sync — now bumps after upsert.
  And `PriorityActionQueue`'s key now includes the **RankCache** version too: it shows
  rank-drop rows, and since the 2026-06-28 RankCache split, hourly rank syncs no longer
  bumped ReportCache — its own code comment still claimed they did (stale, corrected).
  (3) **The genuinely-every-load killer: `CrawlReportService::context()` eagerly built
  the 28d per-user GSC click map** (full GROUP BY page, chunked) for every consumer —
  including `summary()`, which is deliberately uncached (live crawl banner) and runs on
  every /dashboard load, yet never reads clicks. On the 1.33M-row site that was
  **20s per dashboard load, every load**. Click map is now lazy
  (`userClicks()`, memoized, built only when `impactFor()` needs it): `summary()`
  **20,065ms → 99ms** (verified live; also verified a crawled site still returns real
  health/pages/findings values). Tests: `tests/Feature/Dashboard/DashboardCacheTest.php`
  (version-bump invalidation, TTL survival past 600s, GA-sync bump — note dashboard
  cards are `#[Lazy]`, tests need `Livewire::withoutLazyLoading()` or render() never
  runs). Also corrected stale claims in [crawler/read-path.md](./crawler/read-path.md)
  (said `TrackKeywordRankJob` flushes ReportCache — it's been RankCache since 06-28)
  and marked the cap-leak entry fixed in known-issues.md (missed in the earlier sweep's
  doc pass). Docs: [reports/README.md](./reports/README.md) ·
  [crawler/read-path.md](./crawler/read-path.md).

- **2026-07-06 (cleared every open item in "Where docs are still thin")** —
  User asked to fix everything flagged there. Ten items, each with a regression test
  (none of this had any test coverage before today) and doc updates in the same change:
  crawler cap-window leak (`CrawlReportService::topInboundPages()`,
  `tests/Feature/LinkStructureExamplesCapTest.php`); dangling `research.rollout`
  middleware alias (removed, nothing used it); `EnsureFeatureAccess` fail-open on an
  unknown feature key (removed the bypass — `TeamPermissions::allows()` already denies
  safely without it, `tests/Feature/EnsureFeatureAccessTest.php`); CI not running on
  push to `main` (`tests.yml`); billing `assertCanSpend()` non-atomic race
  (`UsageMeter::reserve()`/`release()`, Redis-backed reservation released by
  `ClientActivityLogger::log()`, `tests/Feature/UsageMeterReservationTest.php`);
  guest-tools PageSpeed lead mis-tagging (`Lead::SOURCE_GUEST_PAGESPEED`); audits
  content-hash re-audit gate (`PageAuditReport.content_hash` +
  `ebq:recheck-audit-content` hourly, `tests/Feature/RecheckAuditContentTest.php`);
  orphaned `content_briefs` table (dropped — confirmed 0 rows + zero references,
  **user confirmed explicitly before the drop**, matching the repo's destructive-DB
  safety rule); prod `APP_DEBUG=true` → `false`. Two items turned out to be non-issues
  on closer inspection rather than bugs: guest-tools cookie-friction "bypass" is
  intentional lead-gen design (left alone); data-sources null-vs-empty `gsc_site_url`
  — audited every read-path, all already handle it correctly (confirmed in
  `data-sources/data-model.md`, not a live bug). Also caught and corrected one
  self-inflicted error mid-sweep: the billing bug-report (previous changelog entry)
  wrongly included `BillingController::success()` as broken — re-reading it showed an
  `orWhere` covering both price columns that a grep-based first pass missed; the
  billing docs were corrected to drop that false claim. Full detail on each item is in
  its own subsystem doc (linked from the "Where docs are still thin" section, which
  now serves as an index of what to re-check rather than an open list).

- **2026-07-06 (fixed the monthly-billing plan-resolution bug, same day it
  was found)** — Fixed the bug from the entry directly below. Added
  `Plan::findByStripePrice()` (checks `stripe_price_id_monthly` OR `_yearly`, one
  implementation instead of three copies) and `Plan::intervalForStripePrice()`
  (`app/Models/Plan.php`). Wired into `StripeWebhookController::
  syncPlanSlugFromStripeCustomer()` (`:85`) and `User::resolveSubscribedPlan()`
  (`:350`) — both previously matched yearly-only. `BillingController::success()`
  (`:129`) turned out to already be correct on closer read (had an `orWhere` the
  initial grep-based bug report missed) — left untouched. `BillingController::swap()`
  (`:245`) now detects the subscriber's actual interval via
  `intervalForStripePrice()` and swaps within that interval instead of always
  yearly. Verified the fix directly against real Stripe price IDs (monthly → correct
  slug + `'monthly'`, yearly → correct slug + `'annual'`) — no regression on the
  yearly path. No automated test coverage exists for this billing path at all;
  flagged as a gap, not filled. `php -l` clean on all 4 touched files, FPM restarted.
  Docs: [billing/README.md](./billing/README.md#gotchas--known-issues) ·
  [billing/plans-and-gating.md](./billing/plans-and-gating.md).

- **2026-07-06 (new Stripe account cutover; found the live monthly-billing bug
  while verifying docs)** — User created a fresh Stripe business/account; rotated
  `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET` in `.env` to the new account,
  updated the webhook destination to `https://serfix.io/stripe/webhook`. Confirmed all
  5 active plans' `stripe_price_id_monthly`/`_yearly` already pointed at valid, active
  prices in the new account (verified live against `/v1/prices/<id>`, amounts match DB
  exactly) — no re-seeding needed. Live-tested checkout end-to-end through the real
  `BillingController`/Cashier/`Plan` code path (disposable test user + Stripe customer,
  both cleaned up after: expired the Checkout Session, deleted the test customer,
  deleted the local user row) — minted a real `checkout.stripe.com` session at the
  correct $19/mo Solo price. **While doing this, auditing the docs for accuracy
  surfaced a real, currently-live bug, not just a stale doc**: `checkout()` genuinely
  supports `interval=monthly` (verified above), but two of the three places that
  resolve an existing subscription back to a `Plan` — the webhook's
  `syncPlanSlugFromStripeCustomer()` and `User::resolveSubscribedPlan()` — matched
  only `stripe_price_id_yearly` (`BillingController::success()` turned out to already
  handle both, on a closer read than the initial grep-based scan), so a real customer
  who pays via the pricing page's Monthly toggle got charged and then treated as
  unsubscribed everywhere (`current_plan_slug` → null → Trial-tier limits, frozen
  extra websites, plugin reports `tier: trial`). `BillingController::swap()` had a
  related issue: always swapped to the yearly price regardless of the subscriber's
  actual interval. **Fixed same-day** — see the entry directly above — flagged here
  rather than fixed inline at the time since it's live billing
  logic; full detail + all 4 file:line locations in
  [billing/README.md](./billing/README.md#gotchas--known-issues).
- **2026-07-06 (even later — ebq.io → serfix.io domain cutover, Google approved)** — Google
  approved `serfix.io` as an authorized OAuth redirect URI, so the domain migration (see the
  rebrand entry below) was completed same-day. `.env` `APP_URL` and `GOOGLE_REDIRECT_URI` now
  point at `serfix.io` (`.env.example` placeholders updated too). Apache `ebq.io.conf` (port
  80) and `ebq.io-le-ssl.conf` (port 443) now **permanently redirect everything to
  `serfix.io`** (`RewriteRule ^ https://serfix.io%{REQUEST_URI}`, path+query preserved)
  instead of serving the app — `serfix.io.conf`/`serfix.io-le-ssl.conf` are now the ones with
  `DocumentRoot /var/www/ebq/public`. No app-code changes needed: `SANCTUM_STATEFUL_DOMAINS`
  derives from `APP_URL` (`Sanctum::currentApplicationUrlWithPort()`), and the app was already
  domain-agnostic (no `URL::forceRootUrl`, `SESSION_DOMAIN=null`). **This supersedes the "APP_URL
  stays ebq.io" note in the rebrand entry directly below** — that was correct at the time,
  now resolved. Verified live: `http://ebq.io/*` and `https://ebq.io/*` both 301 to
  `https://serfix.io/*` with path preserved; `https://serfix.io/` serves the app (200).
  Details: memory `serfix-io-domain-migration` (rewritten to reflect completion, not appended).

- **2026-07-06 (later — brand guidelines implemented: logo fix + full orange sweep)** —
  Follow-up to the rebrand below, once `serfix-branding/SERFIX Brand Guidelines.pdf` (Palm
  Advertising, vol. 01) arrived. Two problems: (1) `public/serfix-logo.png`/`-dark.png` had
  the wordmark centered in a ~22%-height band on a 1000×1000 canvas, and every usage forced
  it into a **square** `h-N w-N` box — squeezed to a sliver. Cropped both tight (~3.3:1,
  matches the real wordmark shape) and added a separate square `serfix-icon.png` (letterboxed
  on white) for favicon use; nav/header/sidebar `<img>` tags switched from square to
  height-fixed/`w-auto` boxes. (2) User asked for a full brand-guideline rollout, not just
  the logo — chose the "full sweep incl. logged-in product UI" scope over three options.
  Registered the guideline's exact color ramp in `resources/css/app.css` `@theme`
  (`orange-600`=`#F26419`, `orange-700`=`#C44E0E`, full 50–950 scale + `ink`/`surface-warm`/
  `surface-cool`/semantic colors — see [frontend/README.md](./frontend/README.md) §Brand
  tokens), removing dead unused indigo `--color-primary` vars. Then mechanically renamed
  **every `indigo-NNN` Tailwind class → `orange-NNN`** app-wide (1300+ occurrences, 103 view
  files — `indigo-600` was literally `#4f46e5`, Tailwind's exact hex, confirming indigo was
  always the brand accent), plus hardcoded indigo hex in non-Tailwind contexts the class sweep
  can't reach: all 9 mailables' inline CSS, both dompdf PDF templates
  (`pdf/site-audit.blade.php`, `emails/growth-report-pdf.blade.php`), inline SVG chart
  strokes (`admin/usage`, `keyword-detail`), and `ReportBranding` Livewire/`DemoDataSeeder`
  PHP defaults (`#4f46e5` → `#F26419`, matching the already-updated `ReportBranding::
  ebqDefault()` model default — these three were missed in the original 06-07 rebrand pass).
  Caught one real regression from the mechanical rename: several marketing CTAs
  (`landing.blade.php`, all 4 guest-tool pages, `tools/*`) used a two-tone
  `from-indigo-600 to-violet-600` gradient button — renaming only the indigo half left a
  jarring orange→violet gradient live on the homepage. Flattened all 18 occurrences to solid
  `bg-orange-600`/`hover:bg-orange-700` per the guideline's explicit CTA spec (flat accent,
  never a gradient). Verified via Chrome headless screenshots against the live Apache vhost
  (localhost) — landing, pricing, login, features all render correctly; `npm run build` +
  `php8.3-fpm` restart applied. Left untouched (separate design question, not asked): ~100
  remaining standalone `violet-*` badges/tags (AI Studio badge, admin plan-tier chips) that
  aren't part of the old indigo-brand gradient pattern — those may be an intentional
  secondary "AI" accent rather than a rebrand miss.

- **2026-07-06 (brand — EBQ → Serfix rebrand, visible surfaces only)** — Product brand
  renamed EBQ → Serfix per `serfix-branding/SERFIX Brand Guidelines.pdf` (Inter 800
  wordmark, ink `#111111` + orange `#F26419`, dark variant for product UI only). Changed:
  logo assets (`public/serfix-logo.png` light / `public/serfix-logo-dark.png` dark,
  replacing `public/ebq-logo.png`, wired through `partials/favicon-links.blade.php`);
  `.env` `APP_NAME=Serfix`, `APP_PUBLIC_URL=https://serfix.io` (`APP_URL` stays
  `ebq.io` — see [serfix-io-domain-migration](../CLAUDE.md) note: Google/Microsoft OAuth
  redirect URIs stay pinned to `ebq.io` until Google approves the new domain); all
  marketing/admin/email blade views; `ReportBranding::ebqDefault()` company_name +
  accent_color (now `#F26419`, was indigo `#4f46e5`) — this is what unbranded PDF/email
  reports show; mail subjects (`PageAuditReportMail`, `TrafficDropAlert`); WordPress
  plugin (`ebq-wordpress-plugin/`) **visible strings only** — Plugin Name header, admin
  menu labels (EBQ HQ → Serfix HQ, etc.), readme.txt, `.wordpress-org/` placeholder
  icon/banner (recompiled `src/` → `build/` via `npm run build`). **Left unchanged by
  design**: WP plugin technical identity (slug `ebq-seo`, main file `ebq-seo.php`, text
  domain, `EBQ_*` PHP classes/constants, `ebq-sitemap.xml` rewrite path) — renaming any
  of this breaks auto-update matching for every existing install; support/legal email
  addresses (`support@`, `billing@`, `legal@`, `privacy@ebq.io`) — `serfix.io` has no MX
  record yet, so those addresses would bounce; `marketing.ebq.io` booking links (separate
  app, unaffected); code comments/log strings/console command descriptions (not
  user-visible). Follow-up: plugin `languages/*.po` translations now stale against the
  renamed source strings — regenerate via `npm run make-pot` before next plugin release.
  Docs: [wordpress-plugin/README.md](./wordpress-plugin/README.md).

- **2026-06-28 (perf — `search_console_data` index fixes + `RankCache` split)** —
  Statistics page and PageDetail were triggering 60s+ `GROUP BY` scans on large sites
  (1.17M-row `search_console_data` for one account). Three fixes: (1) **`whereDate` → `where`**
  in `PageDetail.php` (3 occurrences) — `->whereDate('date','>=',…)` generates `date(col)>=?`
  which bypasses the index; `date` is already a DATE type so a plain `->where` is correct and
  index-safe. (2) **Two new indexes** (`scd_wid_page_date (website_id,page,date)` for PageDetail
  keyword queries; `scd_wid_country (website_id,country)` for CountryFilter/top-countries
  aggregations) via migration `2026_06_28_000100_add_page_country_indexes_to_search_console_data`.
  (3) **`RankCache` split** — `ebq:track-rankings` (hourly) was calling `ReportCache::flushWebsite()`,
  orphaning 24h GSC caches (cannibalization, top countries, quick wins) every hour and forcing
  repeated 590K-row scans. Created `app/Services/RankCache.php` (same version-integer mechanic
  as `ReportCache`); `TrackKeywordRankJob` now calls `RankCache::flushWebsite()`;
  `PluginHqController::overview` cache key includes both versions so rank KPIs still refresh.
  GSC-only dashboard caches now stay warm for the full 24h TTL, busted only by nightly
  `SyncSearchConsoleData`. Also added 90-day date window to `CountryFilter` to reduce scan scope
  for sites with long history. Docs: [reports/README.md](./reports/README.md) ·
  [keywords/rank-tracking.md](./keywords/rank-tracking.md) ·
  [reference/database.md](./reference/database.md).

- **2026-06-26 (billing — 5-tier pricing rework: Trial/Solo/Pro/Agency/Enterprise)** —
  Replaced the 4-tier model (Free/Pro/Startup/Agency) with 5 tiers. Old rows renamed
  `legacy_*` + deactivated (`2026_06_26_000100_rename_legacy_plan_slugs` migration,
  transactional, same pattern as 2026-05-17); existing subscribers unchanged (resolve
  via Stripe price ID). New `max_seats`/`extra_seat_price_usd` columns added
  (`2026_06_26_000200_add_seat_fields_to_plans_table`). `Plan::FEATURE_KEYS` grew 9→10
  (added `scheduled_reports` — platform-only, seeded, not yet enforced). `User` tier
  constants renamed: `TIER_FREE`→`TIER_TRIAL` (TIER_FREE kept as alias), removed
  `TIER_STARTUP`, added `TIER_SOLO`/`TIER_ENTERPRISE`; `TIER_ORDER` updated to 5 tiers.
  `resolveSubscribedPlan()` fallback now hits `trial` row (was `free`). `PlanSeeder`
  fully rewritten for 5 tiers. `PlanController::validatePlanInput()` extended for 4 new
  `api_limits` namespaces (`keyword_research`, `ai_studio`, `long_form`,
  `quick_win_finder`). `QuickWinsCard` reads `quick_win_finder.results_shown` from plan
  (was hardcoded `5`). `WebsiteTeam::inviteMember()` enforces `max_seats` on new invites
  only (existing members grandfathered). Admin `/admin/plans/<id>/edit` shows all new
  fields. **WP plugin coordination required**: plugin must be updated to the new tier
  vocabulary `{trial,solo,pro,agency,enterprise}` in lockstep.
  Details: [billing/plans-and-gating.md](./billing/plans-and-gating.md) ·
  [accounts/README.md](./accounts/README.md).

- **2026-06-23 (keyword finder — admin live-queue panel + monthly shared ideas cache)** —
  User: see which keyword's queued and by which user across the self-hosted keyword API
  fleet; and cache keyword-ideas results for the current calendar month (not rolling days)
  so a repeat search — by anyone — is instant. Added a "Live queue" panel to
  `/admin/keyword-servers` (every queued/running `KeywordApiRequest`, all servers, with
  user + keyword/URL — `user()`/`website()` relations were missing on the model entirely).
  Added `KeywordIdeasMonthlyCache` (`Y-m`-keyed, expires `endOfMonth()`) wired into
  `KeywordIdeaFinder::run()`/`poll()`; `KeywordFinderPool::dispatchIdeas()` split to expose
  `buildIdeasPayload()` so the cache key matches the real dispatch payload exactly. Scoped
  to the ideas/discovery flow only — the Volume Finder's per-keyword metrics already has
  its own separate rolling cache (`KeywordMetricsService`), untouched here.
  Details: [keywords/keyword-finder.md](./keywords/keyword-finder.md).
- **2026-06-23 (crawler — post-crawl aggregates cached, fixing slow audit-results load)** —
  User: crawl audit results loaded too slow, cache until the next audit. `actionGroups()`
  (full `chunk(2000)` scan for per-user impact), `typeBreakdown()`, `categoryFindings()`,
  `auditExport()` now go through a new `CrawlReportService::remember()` — `Cache::remember()`
  keyed by the existing `ReportCache::version($websiteId)`, which `AnalyzeSiteJob` already
  bumps at the end of every run. `summary()` deliberately excluded — it carries the *live*
  run status the crawl-progress banner polls; caching it would freeze that banner mid-crawl.
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — exportable Site Audit PDF, reusing the existing whitelabel system)**
  — User: build a production-ready exportable audit report (Semrush's "Site Audit: Issues"
  PDF as the reference) and surface a whitelabel option if the plan system already has one.
  Found it already does — `ReportBranding`/`ReportBrandingResolver`/`report_whitelabel` plan
  flag, previously only used for the Growth Report email PDF (`ReportPdfRenderer` +
  `growth-report-pdf.blade.php`). Built the parallel crawl-audit path: `CrawlReportService
  ::auditExport()` (sitewide rollup across all categories, bucketed Errors/Warnings/Notices
  by severity tier, plus `auditAbout()` — "About this issue" copy for all ~37 types, paired
  with the existing `fixGuidance()`) → `CrawlAuditPdfRenderer` (dompdf, mirrors
  `ReportPdfRenderer`) → `pdf/site-audit.blade.php`. Went beyond Semrush's static export
  (which shows bare counts, no URLs) with: real sample affected URLs per issue (capped 10),
  a health-score-with-letter-grade summary, a "new this week" badge per type
  (`first_seen_at`-based), and a "Start here" top-5 priority shortlist ranked by
  severity×volume so a non-technical reader isn't left to figure out where to begin.
  GSC-sourced types (`isGscSourced()`) get the same amber caveat treatment as the dashboard.
  New route `GET /site-audit/download` (`SiteAuditExportController`, `feature:link_structure`
  + `throttle:10,1`, immediate download not queued) with an Export PDF button on the
  dashboard's Priority Action Queue widget; `whitelabel=0` lets a whitelabel-eligible user
  pull the plain EBQ copy on demand. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — "Fix" buttons rebuilt into a real Page Health feature)** — User
  flagged: every finding's Fix button landed on the same generic link-structure page with
  no relevant info, regardless of issue type. Added `CrawlReportService::pageFindings()` +
  `fixGuidance()` (concrete per-type "what to do" text for all ~35 types) and rebuilt
  `LinkStructurePanel`'s destination into a "Page Health" section showing every open
  finding for that URL with guidance + type-specific detail (duplicate siblings, hreflang
  table, mixed-content list, etc.), highlighting whichever one sent the user there via a
  new `?issue=` param. `broken_external`/`external_redirect` Fix links now route into our
  app instead of opening the live site in a new tab. Hit the `WebsitePage::id` ULID-as-int
  landmine again mid-build (see [[ulid-formatting-landmines]]) — fixed before shipping.
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — crawler findings must stand on crawl data alone, not GSC)** —
  User: the crawler must be GSC-independent for its own findings; GSC can only inform
  severity, and GSC-sourced findings need their own clearly-caveated section since Search
  Console history can be stale. Found `noindex_important`/`canonical_mismatch` were gated
  on GSC clicks for EXISTENCE (not just severity) — re-did both with the crawl-only
  "structurally real" proxy (sitemap/inbound-links/homepage) already used for
  `robots_blocked_important`. `indexed_not_in_sitemap` has no crawl-only equivalent
  ("Google has this indexed" is inherently GSC-only) — left as-is but newly tagged via
  `CrawlReportService::isGscSourced()`, surfaced as a separate amber-highlighted section in
  both the grouped issue-type view and the Page Health panel. Generalizes
  [[crawl-only-over-gsc-gating]] from "new checks" to "audit existing ones too."
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — no hreflang detection at all; added two checks)** — Second
  Semrush export, this time namesforfreefire.com: 4 i18n pages flagged
  `No self-referencing hreflang` + `Conflicting hreflang and rel=canonical`. Same
  gap-class as the duplicate-title miss earlier today — `HtmlAuditor::localeSignals()`
  already parsed `<link rel=alternate hreflang>` tags but nothing downstream read them.
  Wired hreflangs through `PageAnalyzer` → `seo_signals` JSON → two new
  `SiteIssueDetector` checks (`missing_self_hreflang`, `hreflang_canonical_conflict`),
  both under `CrawlFinding::CATEGORY_INDEXABILITY`. Placed ahead of the `! $indexable`
  early-return since a conflicting page is non-indexable by definition. Only the two
  issue types seen in the export were built — Semrush's broader hreflang catalog
  (reciprocal return-links, x-default, duplicate-language entries) is unaddressed.
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler dashboard — issue list grouped by type, Semrush-style)** — User
  reported the issue-detail page (`/issues/{key}`) mixed every issue type in a category
  into one flat list with no separation. Added `CrawlReportService::typeBreakdown()` +
  reworked `SiteIssues.php`/its blade to default to a grouped-by-type card list (count +
  worst severity per type), drilling into the existing flat row list only once a type is
  picked or a search term is typed. Top-level Priority Action Queue (category-level)
  unchanged. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — added robots.txt audit, first one ever; deliberately GSC-optional)**
  — Catalog sweep gap: nothing checked whether Disallow rules accidentally block a real
  page. Added `RobotsTxtParser` (pure, no I/O) + `SiteIssueDetector::detectRobotsBlocked()`
  — one fetch per crawl run via the existing `CrawlFetcher`. First version gated on GSC
  clicks like `noindex_important`; user flagged that many subscribers never connect GSC, so
  re-did it to use sitemap-listed/internally-linked as the crawl-only "this page is real"
  signal instead, with GSC clicks only bumping severity when present. **Standing principle
  going forward: prefer crawl-only signals over GSC-gating wherever possible** — GSC
  presence can't be assumed. New `robots_blocked_important` finding under
  `CATEGORY_CRAWLABILITY`. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — added mixed-content detection)** — Catalog sweep gap: nothing
  scanned for plain-http resources on https pages. Added `HtmlAuditor::mixedContentUrls()`
  (img/script/iframe/source/video/audio/stylesheet, literal `http://` only) → `seo_signals`
  → new `mixed_content` finding, first real use of `CrawlFinding::CATEGORY_SECURITY`.
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — sitemap quality, reverse direction; catalog sweep complete)** —
  Only `indexed_not_in_sitemap` existed (page missing from sitemap). Added the reverse:
  `sitemap_broken_url`/`sitemap_redirect_url`/`sitemap_noindex_url` for URLs that ARE in the
  sitemap despite being 4xx/5xx, redirecting, or non-indexable — click-independent, all
  under `CATEGORY_SITEMAP`. This closes out the full Semrush-catalog gap sweep started
  earlier today (hreflang → UI grouping → TTFB/redirect-chain/schema/twitter → robots.txt →
  duplicate content → mixed content → sitemap quality). Details:
  [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — full Semrush-catalog gap sweep; 4 "captured but discarded" fixes)**
  — User asked to cover all gaps Semrush's audit catches, not just export-driven ones. Found
  `ttfb_ms`/`redirect_chain` (computed in `CrawlFetcher` since this feature was built, never
  read), JSON-LD block validity (`HtmlAuditor::schema()` already flags malformed blocks,
  `seoSignals()` discarded it), and `twitter_tag_count` (captured, never checked) — all wired
  through to 4 new `SiteIssueDetector` checks. First real use of the long-dormant
  `CrawlFinding::CATEGORY_PERFORMANCE` constant. Remaining catalog gaps need new crawler
  instrumentation (robots.txt audit, cross-page exact-duplicate-content, mixed content,
  sitemap-quality checks) — tracked as separate follow-up work, not yet built.
  Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — no duplicate-title detection existed; added one)** — User caught
  via a Semrush export that we missed 24 duplicate-title issues on soulfamburger.com (8
  titles × 3 i18n variants). Confirmed by grep: zero duplicate-title logic anywhere in the
  codebase, only missing/too-long/too-short checks. Added
  `SiteIssueDetector::detectDuplicateTitles()`. Hit (and fixed) a partial-hydration bug along
  the way — the group-by query's `select()` omitted `crawl_site_id`, causing a typed-null
  error visible only in the **worker box's** log, not the web box's — re-confirms the
  two-box deploy discipline from earlier today. Verified: 24/24 findings now match Semrush
  exactly. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — one host redirect cascaded into a redirecting_url finding on
  every page)** — User flagged two soulfamburger.com URLs both "redirecting"; turned out
  all 28/28 of the site's pages were flagged (apex→www). Root cause:
  `PageCrawlProcessor::process()` resolved relative internal links using the pre-redirect
  `$page->url` instead of the post-redirect effective URL, so every link discovered on the
  (actually www-hosted) page got re-anchored back to the apex host — propagating the same
  redirect to every subsequently discovered page. Fixed by using `$res['redirect_target']`
  as the analysis base URL when `$res['redirected']`. Did **not** mass-resolve the 28
  existing findings — unlike the other two crawler fixes today, each is individually a real,
  true redirect; only the multiplication bug was fixed, going forward. Also noted:
  `CrawlSite::homepageUrl()` always seeds the apex (www-stripped) host, so any
  www-canonical site will always get one legitimate homepage-level redirect finding — not a
  bug. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — wa.me click-to-chat links flagged as external_redirect)** —
  User asked to verify a soulfamburger.com finding (`wa.me/.../Soul Beef Meal`). Checked
  `detail.final_url`: wa.me 302s to `api.whatsapp.com/send/?...` — wa.me's own documented
  behavior, not fixable by the site owner. `SiteIssueDetector.php` had no allowlist for
  known 1-hop redirector services. Fixed: `KNOWN_REDIRECTOR_HOSTS` (wa.me, api.whatsapp.com,
  t.me, m.me, bit.ly) + `isKnownRedirector()` gate before raising `external_redirect`
  (the separate real-4xx/5xx `broken_external` check is untouched). Resolved 18 matching
  open findings across 3 sites. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (crawler — image assets ran through on-page SEO checks)** — User-reported
  false positives on childdaycaretracy.com's crawl report (`missing_title` on a `.jpeg`).
  Root cause: image sitemap entries / `<a href="...jpg">` targets became ordinary
  `website_pages` rows with no content-type gate, so `PageCrawlProcessor::process()` ran
  `PageAnalyzer::analyze()` on raw JPEG bytes — `website_pages` has no `content_type`
  column even though `CrawlFetcher` already captures the header. Fixed by checking
  `$res['content_type']` before `analyze()` and marking non-HTML responses
  `is_indexable=false` (which `SiteIssueDetector` already skips). Swept all sites by URL
  extension: only 22 pages (all on this one site) were affected; their 33 open false
  findings were resolved directly. Separately investigated and ruled out: a wa.me/
  soulfamburger.com finding the user saw "in the dashboard" does not exist anywhere in
  this site's `crawl_findings` (confirmed via direct DB query AND calling
  `CrawlReportService::categoryFindings()` exactly as the controller does) — it belongs to
  soulfamburger.com's own crawl_site. Likely a client-side stale-render artifact (same
  bleed class as the known `LinkStructurePanel` cap leak), not a data bug — user to confirm
  with a hard refresh. Details: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (proxy pool — admin "Retest all" false-positive deletes)** — Single "Test"
  button passed a proxy; "Retest all" deleted every proxy in the pool. Root cause: the
  4 active proxies share one provider account/credential (rotating-IP "backconnect"
  gateway — same auth token + port, IP differs), capped server-side to a small number of
  concurrent connections. The old `concurrency: 5` Alpine sweep in
  `proxy-manager.blade.php` opened 5 simultaneous CONNECT tunnels on the same account; the
  provider 403'd the excess, and `deleteOnFail` nuked otherwise-healthy proxies. Fixed by
  dropping retest concurrency to 1 (sequential), matching single-Test semantics. Details +
  generalization (group-by-credential if pool grows multi-account) in
  [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-23 (GSC sync — high-volume account stale since 2026-04-16, real root cause)** —
  `namesforfreefire.com`'s ~38k-row GSC account (the single biggest in `search_console_data`)
  never synced; `SyncSearchConsoleData` failed nightly with no logged exception. First pass
  raised `timeout` 600→3600 (+`redis-long`, `backoff=120`, per-window watermark/logging — still
  good changes) but live re-testing showed the job still hung on window 1 for over an hour with
  zero CPU progress. Real causes, confirmed live: (1) **`Google\Client`'s default HTTP client has
  no read timeout** — a stalled response can block `curl_exec()` forever, and the job's own
  pcntl-based `$timeout` doesn't reliably interrupt a blocking libcurl read, so raising it alone
  never fixed anything; fixed with an explicit Guzzle `connect_timeout=10`/`timeout=120` in
  `GoogleClientFactory::make` (benefits every Google API caller, not just this job). (2) **no
  overlap guard** — a run outliving `redis-long`'s `retry_after` (3900s) got a duplicate
  dispatched on top of it; confirmed two live Redis reservations for the same website fighting
  over the same upserts. Fixed with `WithoutOverlapping('sync-search-console:'.$websiteId)`
  (same pattern as `AnalyzeSiteJob`). Docs: [data-sources/sync-jobs.md](./data-sources/sync-jobs.md)
  §Gotchas, [data-sources/google-oauth.md](./data-sources/google-oauth.md).
- **2026-06-20 (proxy pool — final shape: import OFF by default, prune always ON)** —
  Settled after several iterations same day (see the two entries directly below for the
  history). Current state, fully detailed in
  [crawler/known-issues.md](./crawler/known-issues.md): `ebq:proxy-list-refresh`
  (import-only, new candidates from free lists, scheduled but gated OFF by
  `CRAWLER_PROXY_AUTO_IMPORT`, manual override via artisan or the admin "Import now"
  button → `RunProxyListRefreshJob`) is now fully separate from `ebq:proxy-pool-prune`
  (health-check-only, deletes any tracked proxy that fails a fresh test, always
  scheduled every 15min regardless of the import flag). Both share
  `ProxyPool::testBatch()` (cert-verified concurrent HTTPS test). Admin "Retest all" and
  real-usage `markFailure()` are two more, independent deletion paths — four total,
  intentionally not unified, see known-issues.md for which is which.
- **2026-06-20 (admin proxy screen — "Retest all" with delete-on-fail, superseded detail
  below by the pruner)** — Added an Alpine-driven "Retest all" sweep to `/admin/proxies`
  (concurrency 5, live per-row spinner + progress bar). Deliberately distinct from the
  synthetic auto-refresh job: this is a manual admin sweep, so
  `ProxyManager::test($id, deleteOnFail: true)` removes a proxy on the spot the moment it
  fails — the single-row "Test" button keeps the old non-destructive behavior. Confirm
  dialog before starting (irreversible per row).
- **2026-06-20 (proxy pool auto-refresh from a free public list — superseded, see entry
  above)** — Added `ebq:proxy-list-refresh` (scheduled `everyThirtyMinutes`,
  `routes/console.php`), which pulls `iplocate/free-proxy-list`'s `all-proxies.txt`,
  live-tests a random sample (real HTTPS GET, cert verification ON) before trusting any
  of it, and writes only the passing ones into `proxies` (feeds the same pool used by the
  broken-link checker fix above and the crawler's anti-block retries). Verified before
  building: manually curl-tested a sample first — ~45% of HTTP candidates worked, SOCKS5
  mostly dead, and one SOCKS5 node
  was actively MITM-ing HTTPS (self-signed cert swap) — confirms untested import of a free
  list is not safe; cert-verify-on testing is the load-bearing safety check, not optional.
  Docs: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-20 (page-audit broken-link false positive — 429 + proxy retry)** — User-reported
  false "broken link" (`apps.apple.com` rate-limiting the audit's HEAD check with 429). Root
  cause: `PageAuditService::checkLinks()`/`getFallback()` only fell back to GET on
  403/405/501 — 429 went straight to "broken" with zero retry. Fixed in both the single-page
  audit checker (`PageAuditService.php:1150,1239`) and its near-duplicate in the crawler
  pipeline (`Crawler/LinkChecker.php`): added 429 to the fallback-trigger list, and if the
  plain GET retry *still* looks dead, one more GET attempt through the crawler's `ProxyPool`
  (`crawler.proxy.*`, already live in prod with 4 active proxies) before trusting the result.
  Docs: `infra/audits/page-audit.md` §Gotchas + pipeline step 5.
- **2026-06-20 (AI Writer 504s — outer timeout layers shorter than the inner one)** — Blog-post
  generation intermittently 504'd. Root cause: the writer is fully synchronous, `set_time_limit(360)`
  + Mistral calls up to 300s (chained Serper+LLM up to ~5min, see `ai/writer.md`), but the two layers
  *outside* PHP were shorter — Apache's proxy_fcgi backend wait used the global `Timeout 60`
  (`ebq-hardening.conf`) and FPM's `request_terminate_timeout` was **120**. Whichever hit first killed
  the request mid-LLM-call → 504, regardless of the generous PHP-level limit. Fixed by raising FPM
  `request_terminate_timeout` 120→**400** and adding vhost-level `ProxyTimeout 400` to
  `ebq.io-le-ssl.conf` (mod_proxy_fcgi has no per-`<Location>` timeout, so this is vhost-wide for the
  PHP backend only — the client-facing `Timeout 60` is untouched). See `server-deployment.md` and
  `ai/writer.md` §Gotchas.
- **2026-06-18 (finalize timeout for extreme sites — code-based, no env edit)** — A ~168k-page/~1.5M-edge
  site (xplate) finalized past the 1200s timeout even after the graph + memory fixes (it's just slow:
  graph → value_rank → `detect` → suggester → scores, all chunked/bounded so no OOM, but minutes of work).
  Raised `AnalyzeSiteJob` timeout to **3600s** and moved it onto a dedicated **`redis-long`** queue
  connection (`config/queue.php`, retry_after **3900** as a *code default*, not the `REDIS_QUEUE_RETRY_AFTER`
  env) so the ceiling travels with the deploy — no per-box `.env` change. `$heavyPool` repinned to
  `redis-long` (timeout 3600, maxTime 4200, memory 1536). Permanence is the theme: all crawler fixes live in
  code, so `bootstrap()` + the snapshot build bake them onto every (incl. autoscaled) box automatically —
  see [crawler/autoscaling.md](./crawler/autoscaling.md) §"How fixes reach new boxes & snapshots".
- **2026-06-18 (worker memory ceiling — Horizon 128M regression)** — Horizon workers inherit PHP's
  CLI-default `memory_limit=128M`; the pre-Horizon raw workers ran `-d memory_limit=2048M` (lost in the
  migration), so `HtmlAuditor` (large pages) and the link-graph finalize OOM'd at 128M. Fix: the heavy
  jobs `ini_set` their own ceiling — `CrawlPageBatchJob` (`crawler.batch_memory_limit`, 512M) +
  `AnalyzeSiteJob` (`crawler.analyze_memory_limit`, 1024M) — so it travels with the code to **every box
  incl. autoscaled ephemeral** (via `bootstrap()`'s full-app rsync), no snapshot/php.ini dependency.
  Docs: server-deployment.md, crawler/autoscaling.md.
- **2026-06-18 (autoscaler — snapshot-existence preflight)** — Before provisioning a crawl box
  the autoscaler now verifies the configured worker **snapshot still exists in Hetzner**
  (`HetznerClient::imageExists`, tri-state; `FleetAutoscale::snapshotExists` gate +
  `WorkerFleetService::provision` defense-in-depth) — complementing the existing git-HEAD-drift
  gate. A deleted snapshot otherwise made `createServer` 422 every tick and the autoscaler
  **looped provision→reap** a dead node (observed after a snapshot was deleted during unrelated
  Hetzner cleanup). Confirmed-missing → rebuild (if `auto_snapshot`) or skip with an actionable
  error. 2 new tests. Doc: [crawler/autoscaling.md](./crawler/autoscaling.md).
- **2026-06-18 (crawl finalize — large-site 1205 lock-wait + finalize loop)** — Fixed two
  compounding `AnalyzeSiteJob` failure modes that stranded large-site finalizations (39k &
  168k pages): (1) `SiteGraphAnalyzer` did **whole-site UPDATEs** of `inbound_link_count` /
  `click_depth` that tripped `innodb_lock_wait_timeout` (1205) while contending with live
  crawl writes → now computes in PHP and writes in **bounded id-keyset chunks**
  (`resetColumnChunked`/`writeGroupedChunked`); (2) the supervisor re-dispatched finalize on
  slow-but-alive runs → **overlapping finalizes** fighting for locks → added
  `WithoutOverlapping` + `tries=2`/`backoff` to `AnalyzeSiteJob`. New test
  `tests/Feature/SiteGraphAnalyzerTest.php`. Corrected stale Horizon worker-pool table in
  `server-deployment.md`. Detail: [crawler/known-issues.md](./crawler/known-issues.md).
- **2026-06-17 (full-ULID + multi-node sharding — on branch `feature/db-sharding-ulid`)** — Re-keyed the
  whole schema to **ULID** (`char(26)`; framework/Sanctum/pivot surrogate ids stay bigint) and built
  **two-dimensional sharding**: tenant-by-owner + crawl-by-domain, behind one routing layer
  (`DbNode`/`db_nodes`, `ShardManager`, `ShardContext`, tier model traits, `ResolveShardContext`
  middleware + `WebsiteApiAuth` + job wiring). Cross-tier FKs dropped (MySQL-only migration; integrity
  app-enforced via `ShardCleanup` + `ShardTables`). Admin-managed DB-node fleet (`DbFleetService` reusing
  `HetznerClient`, `ebq:db-node`, `/admin/db-fleet`) + tenant/crawl **mover** (`ShardMover`, `ebq:shard`).
  Validated: full suite 0 new failures vs baseline (sqlite), schema + FK-drop + an end-to-end tenant move
  on a throwaway docker MariaDB. NOT merged/deployed: prod re-derive cutover + Hetzner node provisioning +
  Phase 0 backups are operator-gated. New doc [sharding/](./sharding/README.md); full plan
  `SHARDING_PLAN.md`.

- **2026-06-17 (fleet autoscaling — live-tested)** — Completed the Hetzner setup (token, network
  `12332718`, ssh key, firewall, worker **snapshot**, `.env.worker`) and ran a full live
  `provision → bootstrap → drain → destroy` cycle successfully (Redis `CLIENT LIST` confirmed the
  new box's workers polling the crawl queue). Fixes from the test: server type `cx23` (not AMD
  `cpx*`), wait-for-SSH on bootstrap, ephemeral boxes forced crawl-only, and **the web box `ufw`
  must allow the private subnet `10.0.0.0/24` to Redis 6379 + MariaDB 3306** (added) — otherwise
  ephemeral workers crash-loop. Autoscaler remains **off** pending an operator `enable`. See
  [crawler/autoscaling.md](./crawler/autoscaling.md).
- **2026-06-16 (fleet autoscaling P1–P4)** — Built elastic crawl-worker scaling on Hetzner
  ([crawler/autoscaling.md](./crawler/autoscaling.md)): `worker_nodes` fleet model +
  `HetznerClient`/`WorkerFleetService`, the `ebq:fleet-worker` manual command, the
  `ebq:fleet-autoscale` control loop (queue-depth driven, hysteresis) + `ebq:check-worker-nodes`
  health loop, a `/admin/fleet` panel (live status, cost, editable settings), the
  **`crawl-finalize` queue split** (long `AnalyzeSiteJob` on the pinned box only, so scale-down
  can't kill a finalize), and a **distributed per-domain rate limiter** (`DomainRateLimiter`).
  Autoscaler ships **disabled** — gated on the operator's Hetzner setup (token/snapshot/network).
  9 tests pass. Key property: the queue is central Redis, so new boxes just pull — no rebalance.
- **2026-06-16 (dashboard + crawl fixes)** — Fixed & deployed: IDOR gate on the Competitive
  components (Livewire actions skip route middleware), `summary()` stale-health (use last
  *completed* run), `KpiCards`/`TrafficChart` cache-version, and the `CrawlBanner` poll
  (10s/30s) + display. **Crawl fairness** (`crawler.pages_per_pass`) so a big site can't
  starve the shared queue, and a **`ebq:crawl-supervisor`** watchdog (every 5 min,
  `stall_minutes` 10) that recovers wedged multi-pass chains. Admin `/admin/crawler` now shows
  the client per crawl + a legend, and progress as crawled / total-discovered. Banner + admin
  progress are inventory-based (not the per-pass counter). 8 tests pass. Docs updated across
  `crawler/{pipeline,known-issues,operations,read-path}`, `reference/{jobs-and-scheduler,
  configuration}`, and `deployment-and-queues` (⛔ never `rsync --delete` to the worker — it
  wiped the worker-only compose/Dockerfile this session; recovered from image history).
- **2026-06-16 (wp plugin source)** — Documented the **client-side WordPress plugin** (the
  EBQ SEO plugin, a separate git repo at `/var/www/ebq/ebq-wordpress-plugin/`) in
  `wordpress-plugin/plugin-source.md` + `plugin-features.md`. Added `/ebq-wordpress-plugin`
  to the main repo's `.gitignore` (it's a 581M nested repo — must never be committed here;
  its old folder name `ebq-seo-wp` was already ignored but the rename left it exposed).
- **2026-06-16 (cross-cutting sweep)** — Added the **horizontal reference layer**
  (`infra/reference/`): database (83 tables / 49 models), routing (endpoint map),
  http-and-auth (middleware/guards/authz), jobs-and-scheduler (25 jobs + 17 commands +
  destructive-command safety), configuration (16 config files + env), mail-and-wiring, and
  testing (the sqlite-guard / safe-test-running). Plus **`infra/frontend/`** (Livewire/Alpine/
  Tailwind/Vite UI architecture). **55 docs total.** Surfaced latent bugs (dangling
  `research.rollout` alias, fail-open `EnsureFeatureAccess`, CI-on-`master`-not-`main`, orphaned
  `content_briefs`) — logged under "Where the docs are still thin".
- **2026-06-16 (later)** — **Full-application documentation sweep.** Documented every
  subsystem under `infra/<area>/` (data-sources, keywords, competitive, reports, ai, audits,
  wordpress-plugin, guest-tools, billing, accounts, admin) and added
  **[server-deployment.md](./server-deployment.md)** — a live, read-only inventory of both
  production boxes (Apache+FPM web box `host.ebq.io`/`10.0.0.2`; Docker worker box
  `10.0.0.3`; **MariaDB** not MySQL; Mistral LLM; co-located Postal/Jitsi). 46 docs total.
  Flagged prod risks: `APP_ENV=local` + `APP_DEBUG=true`, stale `MAIL_HOST`, un-versioned
  worker compose file.
- **2026-07-15** — **Site Explorer funnel now attaches the domain + empty-domain partial
  reports.** New `WebsiteAttachService` (extracted WebsitesList recipe) runs at signup /
  zero-website signin, so the funnel domain gets a real Website + crawl + GSC import (pay-first
  branch hands the domain to onboarding via `session('onboarding.domain')` instead of orphaning
  it). A null DataForSEO summary no longer dead-ends: `ReportEnrichmentService` +
  `EnrichEmptyReportJob`/`FinalizeReportEnrichmentJob` build a `status='partial'` report
  (OPR + Moz + self-hosted keyword fleet + one-call LLM junk-keyword check + capped SerpCache
  competitor tally), 10-day `partial_ttl_days`, source-badged sections, kill switch
  `REPORT_ENRICHMENT_ENABLED`. Also surfaced paid-but-hidden DataForSEO data (top_pages section,
  summary `profile_details`, competitor `organic_keywords`; `etv` deliberately excluded) and an
  async "Estimated keywords" section on full reports. Same-day follow-ups: SERP
  competitor discovery runs on EVERY enrichment (not just the junk-keyword fallback);
  GSC-connected owners see their REAL Search Console queries instead of estimates
  (render-time merge, never in the shared snapshot); keyword sections render above
  competitors for new sites / below for established; Keyword Gap deep-research links
  (own-site context only); admin "Remove report cache" on /admin/site-explorer-usage.
  Docs: [reports/client-report.md](./reports/client-report.md).
- **2026-07-13** — Shipped the customer-facing **backlink/authority report** + homepage
  **"Analyze website" funnel**. New providers `DataForSeoBacklinkClient` (backlink profile) +
  `MozLinksClient` (DA/PA/Spam, free tier). Shared per-domain cache `website_report_snapshots`
  with `ReportFreshnessGate` tiers (90d default / 30d paid-owned) + `GenerateWebsiteReport` job +
  `ebq:refresh-paid-reports` cron. One `ClientReportService` payload → public `/r/{token}` share
  page + `ClientReportPdfRenderer` PDF (fixed light "paper", arc-`<path>` SVG gauges — dompdf-safe).
  Funnel: anonymous Analyze calls NO provider API (blurred teaser + signup modal); `users.phone`
  added, required at signup. Docs: [reports/client-report.md](./reports/client-report.md).
- **2026-06-16** — Created this knowledge entry point (`infra/main.md`) + the maintenance
  protocol. Renamed `arch/` → `infra/`. Crawler subsystem fully documented under
  `infra/crawler/` (8 docs) following the shared single-crawl-store re-architecture; added
  `infra/deployment-and-queues.md`. Shared-crawl + tooling shipped to `main` (commit `3a041b5`).
