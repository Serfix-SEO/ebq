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
[reports/](./reports/README.md) → insights · action-queue · growth-reports
— `ActionQueueService` merges crawl findings + GSC reports + rank drops + audits into one
ranked queue. (`GenerateAiInsights` is still a stub.)

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
[frontend/](./frontend/README.md) → livewire-patterns — Livewire 3 + Alpine + Tailwind 4 +
Vite 7. **No full-page Livewire routes** (Blade `Route::view` embeds `<livewire:…>`); the
**active website is session state** (`current_website_id`, propagated via `website-changed`).

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
- **Known correctness caveats** captured in subsystem docs (read before changing them):
  crawler `known-issues.md` (cap-window leak), billing `usage.md` (non-atomic `assertCanSpend`),
  guest-tools (cookie friction bypass; PageSpeed leads mis-tagged), audits (no content-hash
  re-audit gate), data-sources (null-vs-empty `gsc_site_url`).
- **Latent bugs surfaced by the reference sweep (worth fixing):**
  - `bootstrap/app.php` registers middleware alias **`research.rollout` → a class that doesn't
    exist** (`EnsureResearchRolloutAccess`) — harmless until a route uses it, then 500. (routing)
  - **`EnsureFeatureAccess` fails open** on unknown feature keys — a typo in a `feature:` arg
    silently bypasses gating. (http-and-auth)
  - **CI (`tests.yml`) triggers on `master`/`*.x`, but the default branch is `main`** — pushes
    to `main` get no push-event CI (only PR/nightly). (testing)
  - Orphaned **`content_briefs`** table (no longer referenced); `prod APP_DEBUG=true`. (database / server-deployment)

---

## Knowledge changelog

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
- **2026-06-16** — Created this knowledge entry point (`infra/main.md`) + the maintenance
  protocol. Renamed `arch/` → `infra/`. Crawler subsystem fully documented under
  `infra/crawler/` (8 docs) following the shared single-crawl-store re-architecture; added
  `infra/deployment-and-queues.md`. Shared-crawl + tooling shipped to `main` (commit `3a041b5`).
