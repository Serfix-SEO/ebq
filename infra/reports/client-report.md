# Client backlink/authority report (Mangools-style) + Analyze funnel

Customer-facing backlink & authority report, delivered as an interactive public
web page and a branded PDF, fed by two paid providers. Added 2026-07-13.

## What it is

A per-domain report — Domain Authority / Page Authority / Spam Score (Moz),
Authority score / referring domains-IPs-subnets / dofollow & active-link ratios /
backlink profile over time / anchor-type buckets / top referring domains /
competitors (DataForSEO) — plus a conditional GSC/GA traffic strip. Rendered
identically on the web share page and the PDF from one canonical payload.

**Not in scope (documented, deferred):** Majestic Trust/Citation Flow, Facebook
shares, Mangools' popularity rank, and the LLM-mentions / AI-visibility section
(DataForSEO AI Optimization — Phase 3, not built).

## Providers (credentials in .env on BOTH boxes, never committed)

| Provider | Client | Gives | Cost |
|---|---|---|---|
| DataForSEO Backlinks API | `App\Services\DataForSeoBacklinkClient` | summary/history/referring_domains/anchors/domain_pages/backlinks/competitors — **all Backlinks list endpoints capped at 1000 rows/request (2026-07-13, was 100 for domain_pages/competitors)** — DataForSEO's hard per-request max (verified live). The $0.024 base fee is paid regardless of row count, so pulling 1000 vs 100 costs only ~$0.03 more per report while letting `ClientReportService` explicitly sort for the true top rows instead of trusting API order within a shallow window. + **Labs `competitors_domain`** for organic competitors (shared-keyword based — the backlinks `competitors` endpoint is noisy for small sites, kept only as fallback). Competitor rows filter self + mega-platforms, sorted by shared keywords, sliced to top 10. **Labs exception (2026-07-17): Labs rows cost $0.0001 (10× Backlinks rows), so `labsCompetitors()` has its own tighter cap `labs_competitors_limit` (default 300, env `DATAFORSEO_LABS_COMPETITORS_LIMIT`) — past ~row 300 it's weak-position 15–40-shared-keyword stragglers.** | $0.024/req + $0.06/1000 rows → ~$0.35/report, flat by site size |
| **Monthly spend circuit-breaker (2026-07-17)** | `App\Services\Reports\DataForSeoSpendMeter` | Redis month-keyed counter of REAL billed spend (fed by `GenerateWebsiteReport::logGenerationCost` + the failure catch + the anchor drill-down closure). Cap via `DATAFORSEO_MONTHLY_CAP_USD` (null/0 = disabled). Over cap — single choke point at the top of `GenerateWebsiteReport::handle()` covers all 5 dispatch sites: existing-payload snapshots are served stale (TTL refresh / schema self-heal skipped), unattached-domain lookups degrade to the free-signal young-site partial flow (status `enriching` + `EnrichEmptyReportJob`), attached own-site FIRST reports still generate (core promise, bounded by signups). Drill-downs stay allowed but metered. **Admin-only concept**: ops digest warns once/day at 80% and 100% (cache-flagged), `/admin/ops` shows a spend banner; client surfaces never mention budgets (client-facing copy rules). Sandbox exempt. Tests: `tests/Feature/DataForSeoSpendCapTest.php`. | worst-case monthly bill ≈ cap + own-site signup drift |
| **Complete-profile shortcut (2026-07-17)** | `App\Services\Reports\BacklinkSampleAggregator` | When `summary.backlinks <= row_limit` (1000), the `mode=as_is` backlinks sample IS the complete live-link profile — so `referring_domains`/`anchors`/`domain_pages` (paid server-side GROUP BYs over those same rows) are aggregated **locally** in `GenerateWebsiteReport` instead of called. Output mirrors endpoint item shapes + sort orders exactly, so `ClientReportService` is untouched; the derived top-pages rows even FILL per-page referring_domains (the old empty-endpoint fallback left them null). Big profiles (>1000) keep the real endpoints — links beyond the sample can't be aggregated. ~53% of targets qualified at ship time. | saves 3 of 6 Backlinks calls (~$0.11) per small-site report — ~20% of total spend, zero data loss |
| Moz Links API `url_metrics` | `App\Services\MozLinksClient` | DA + PA + Spam (1 row = 1 URL) | free tier 50 rows/mo — call ONCE per report on the OWN domain only |
| Open PageRank (by Keywords Everywhere) | `App\Services\OpenPageRankClient` | **Popularity rank** (global position) + 0-10 score + monthly history; enriches the site, competitors, referring domains & backlink sources | POST `/v1/domains/bulk` (Bearer, ≤100 domains/call), free 30k/mo. `OPENPAGERANK_API_KEY`. Subdomain sources looked up at registrable-domain level (`::registrable()`). |

Config: `config/services.php` → `dataforseo` (`DATAFORSEO_LOGIN`/`_PASSWORD`),
`moz` (`MOZ_API_TOKEN` base64, or `MOZ_ACCESS_ID`/`MOZ_SECRET_KEY`), `report`
(`REPORT_DEFAULT_TTL_DAYS`=90, `REPORT_PAID_TTL_DAYS`=30).

## Shared per-domain cache (network effect)

`website_report_snapshots` (`App\Models\WebsiteReportSnapshot`) — one row per
`normalized_domain` (via `CrawlSite::normalizeDomain`), holding the full report
`payload` JSON + denormalized DA/PA/Spam/rank/referring_domains/backlinks_total.
Cross-tenant shared cache (plain `HasUlids`, central connection, like
`CompetitorBacklink`). Any user querying a domain reads the same row.

**Freshness — `App\Services\ReportFreshnessGate`:** a snapshot is fresh while
younger than its TTL — **90 days default**, **30 days when the domain is a
`Website` owned by an `isPro()` account** (`ttlDaysFor()` / `isPaidOwned()`),
and **10 days (`partial_ttl_days`) whenever the snapshot is NOT a full report**
(status `partial` / `no_data` / `enriching`) so a young site that gains real
backlink data auto-upgrades to a full report on the next view. Fresh snapshots
are served with NO provider call.

**Generation — `App\Jobs\GenerateWebsiteReport`** (`tries=1`, `uniqueFor=1800`,
queue `default`): freshness-gated; fetches DataForSEO (bounded) + Moz (own
domain), assembles via `ClientReportService::assemble()`, upserts the snapshot.
Never caches a broken report. **A null DataForSEO summary no longer dead-ends
(2026-07-15):** eligible domains (non-sandbox + `REPORT_ENRICHMENT_ENABLED`) go
`status='enriching'` and hand off to the empty-domain enrichment pipeline below;
`no_data` is now written only for ineligible cases. A completed FULL generation
also dispatches `EnrichEmptyReportJob(keywordsOnly: true)` so every report gains
an "Estimated keywords" section from the self-hosted keyword fleet. Paid-owned
domains are refreshed monthly by `ebq:refresh-paid-reports` (`RefreshPaidReports`,
scheduled `dailyAt('04:15')`, mirrors the TrackRankings due-filter pattern).

## Rendering

`App\Services\Reports\ClientReportService` builds ONE payload; `assemble()` is
pure transformation (cached in the snapshot), `withTraffic()` merges the private
GSC/GA strip only for an authed owner whose site `hasGsc()` (never stored in the
shared snapshot). Anchor-type buckets (branded/naked/generic/exact) are our own
classification over anchor strings.

Views under `resources/views/reports/` — **web and PDF render from the SAME
`$payload` but use different presentation templates** (like the app's email vs
PDF split), because dompdf can't handle Tailwind:
- **Web** — `web-body.blade.php` (Tailwind, matches the marketing site) +
  `partials/web-table.blade.php`. `view.blade.php` wraps it in `<x-marketing.page>`
  (full site header/nav/footer/CSS/Inter). `public.blade.php` = white-label
  standalone loading the site CSS via `@vite`.
- **PDF** — `_body.blade.php` — **fixed light "paper" theme (hex colors, table
  layout, no CSS vars/flex)** so dompdf is happy; used only by
  `pdf/client-report.blade.php`.
- Charts (shared by both): `charts/ring.blade.php` (arc-`<path>` gauge — dompdf-safe,
  NOT stroke-dasharray), `charts/profile.blade.php` (active/lost `<rect>` bars).
- `view.blade.php` states: teaser (blurred web-body + signup modal), pending
  (spinner + JS `location.reload`), unavailable, ready.
- **Blade gotcha:** a directive glued to a word (`report@isset(...)`) is NOT
  recognized as an opening directive but its `@endisset` compiles → orphan
  `endif` parse error. Use an inline `{{ ... ? ... : ... }}` instead.

PDF: `App\Services\Reports\ClientReportPdfRenderer` (mirrors `ReportPdfRenderer`,
A4 portrait, `isRemoteEnabled`). **dompdf-SVG spike passed** — arc-path rings +
rect bars render (verified: valid `%PDF-`).

## Backlinks list = ALL links, top-1000 by rank (2026-07-16)

`DataForSeoBacklinkClient::backlinksSample()` fetches `mode=as_is` (was
`one_per_domain`): every live link, capped at the 1000-row request limit,
rank-sorted. Same single request/cost. Rationale: grouped mode hid links
whose anchors appeared in the anchors aggregate (e.g. 富88 on
enviromiddleeast.com) — read as a data bug. The one-per-domain view still
exists as a **client-side toggle** ("All links / One per domain") in the
backlinks tables — rows arrive rank-sorted, so first-row-per-domain ≡ the old
grouped sample (dup rows marked lazily from `data-domain` by
`reports/partials/table-tools.blade.php`, which also owns filter + sort +
anchor→backlinks search). Zero-match filters show an honest note with the
top-1000 cap; nothing is silently hidden. Old snapshots keep grouped data
until their natural regeneration.

## Per-plan backlink-row economics (2026-07-16)

Three plan knobs in `api_limits.report.*` (admin → Plans, validated in
`Admin\PlanController`), all RENDER/consumption-side — the shared snapshot
always stores the full 1,000-row fetch (fetch-side caps would poison the
cross-tenant cache for <$0.05/report savings):
- `monthly_backlink_rows` — Ahrefs-style monthly row quota (trial 1,000 /
  solo 100k / pro 500k / agency 1.2M / enterprise unlimited). Metered by
  `app/Services/Reports/BacklinkRowQuota.php` via UsageMeter provider
  `backlink_rows` (subscription-anchored windows, ClientActivity rows,
  reserve→log(release) pair). Charged ONCE per (user, domain, window) —
  repeat views free (cache-deduped). Exhausted → 25-row teaser + "monthly
  limit reached" banner; partial → "Showing X of Y (N of M monthly rows
  used)" + upgrade link. Wired in `ReportViewController::resolve()`
  (`payload['_backlink_view']`); public shares uncapped.
- `max_backlink_rows` — per-view render cap (default/seeded 1,000).
- `allow_link_drilldown` — the paid per-anchor index fetch (trial 0 = 403 +
  upgrade hint; others 1; enterprise null = allowed).

## Tier-1.5 targeted link crawler (2026-07-16, dormant)

Actively crawls tracked domains (domain_metrics) to discover their OUTBOUND
links, feeding the permanent link graph — the "hunt on purpose" upgrade over
Tier-1's passive harvest. **OFF by default** (`LINK_CRAWL_ENABLED`, config
`crawler.link_crawl.*`); a new outbound web crawler activates deliberately.

Pieces (all reuse the site-audit toolchain — CrawlFetcher, ProxyPool,
DomainRateLimiter, RobotsTxtParser, EdgeRecorder; runs on the dedicated
`link-crawl` queue / box B + ephemeral fleet):
- `link_crawl_frontier` table + `App\Models\LinkCrawlFrontier` — flat host/URL
  work-list (distinct from `website_pages`, which is crawl_site_id-scoped).
- `ebq:seed-link-crawl` (scheduled 01:20) — queues homepages of important
  domains (tier=active, then times_seen, then cc harmonic rank).
- **Concurrent frontier (no pass barrier).** `FrontierClaimer` atomically
  claims N due `pending` rows under a lease (`lease_id` + `leased_until`;
  portable select-then-guarded-UPDATE so two workers never grab the same URL —
  the loser just wins fewer). `LinkCrawlBatchJob` claims its own rows at run
  time, and when it gets work it dispatches ONE successor before crawling
  (self-replacing 1:1) → the fleet stays saturated and a slow/WAF domain only
  slows its own batch. Per URL: robots.txt (cached 6h) → DomainRateLimiter
  politeness → BlockDetector-aware proxy-first fetch (Cloudflare/WAF detection,
  block cooldown, WAF slow-mode, one proxy retry) → HtmlAuditor::links() →
  `EdgeRecorder::record(url, external, SOURCE_OWN_CRAWL)`; depth-0 homepages
  seed ≤`max_pages_per_host` internal pages. Terminal states clear the lease.
- **Dedicated `link-crawl` queue** (Queues::LINK_CRAWL) added to `$crawlPool`
  after `crawl` — same I/O-bound workers, Horizon auto-balance shares capacity
  so link crawl neither starves nor is starved by site audits.
- `ebq:link-crawl-dispatch` (every minute) — tops the queue up to
  `target_in_flight` (default 40) batch jobs; the seed from cold + refill after
  the pool winds down. `ebq:reap-link-crawl-leases` (every 3 min) — returns
  crashed workers' expired leases to `pending` (crash recovery).
- `App\Services\LinkGraph\LinkCrawlBudget` — Redis daily page cap
  (`daily_budget`, default 150k, fleet-wide).
- **Keeping the frontier fed (else it drains → backlog 0)** — the claimer only
  ever takes `pending`, so two mechanisms return work to `pending`:
  1. **Recrawl requeue.** A crawled row goes `done` with `next_at = +recrawl_days`.
     `FrontierClaimer::requeueRecrawls(limit)` (called each tick by the
     dispatcher, capped `recrawl_requeue_limit`, default 1000) flips due `done`
     rows (`next_at <= now`) back to `pending`. WITHOUT this a domain is `done`
     forever and the frontier empties (fixed 2026-07-17 — `next_at` on done rows
     was previously decorative; the seed's `$recrawlBefore` was dead code).
     `failed` rows are terminal (never resurrected); `blocked` retry on their own.
  2. **Organic expansion.** `LinkCrawlBatchJob::expandFrontier()` queues up to
     `expand_per_page` (default 3) NEWLY-seen external registrable domains per
     crawled page as depth-0 homepages — so the crawler grows the graph through
     its clients' link neighbourhoods instead of only crawling the seeded set.
     Gated by `expand_enabled` + a hard `max_frontier` ceiling (default 300k,
     checked via a 30s-cached frontier count) so it can't grow unbounded. This is
     the primary "stay continuously busy" lever; the daily budget still caps cost.

**To activate:** `LINK_CRAWL_ENABLED=true` on BOTH boxes + Horizon restart
(box B runs the crawl queue). ACTIVATED 2026-07-16.

**Admin dashboard** `/admin/link-graph` (`Admin\LinkGraphController`, nav "Link
Graph"): live crawler status (running/idle/paused via cache heartbeat), domains
crawled today, daily-budget bar, frontier breakdown, **new-backlinks-per-day
bar chart with day-range (7/14/30/90) + source filters**, edges-by-source
split, most-linked-to domains, recent-discovery feed. Pause/resume without an
env edit via `App\Support\LinkCrawlToggle` (runtime cache override that all
job guards honor; env flag stays the master kill switch) + a reseed button.
Tests: `tests/Feature/LinkCrawlTest.php`.

## Link-risk / toxicity layer + disavow export (2026-07-16)

`app/Services/Reports/BacklinkToxicityScorer.php` — deterministic risk
interpretation over the backlink profile, run on EVERY read inside
`ClientReportService::scored()` (pure payload math, old snapshots gain it
instantly). Signals: (1) link-selling / hacked-site / gambling / pharma
anchor regexes (telegram handles, "hacked", "seo backlinks", CJK gambling
terms…), (2) link networks — ≥3 numbered-stem sibling domains
(link-legion-23.xyz…) with no authority, (3) disposable-TLD + zero-authority
combos (supporting signal → 'medium'). Output: per-row `tox`/`tox_why` on
backlinks / top_referring_domains / anchors rows + `payload['link_risk']`
(level high|medium|null, toxic_domains ≤500 for the disavow file,
toxic_anchor_examples, exact_pct with over_optimized ≥40% flag).

UI: red/amber "link risk" panel (reports/partials/link-risk.blade.php) on
/backlinks + report web view with counts, top sold-link anchors, penalty-risk
warning, and a **Download disavow file** button; ⚠ Toxic / Risky chips on
flagged rows in all backlink/referring/anchor tables. Disavow export:
`GET /report/disavow?url=` (`ReportDisavowController`, auth+throttle,
read-only) → Google-format `domain:` lines with review-first header.
Tests: `tests/Feature/BacklinkToxicityTest.php`.

## TrustSignal / CiteSignal / TopicSignal (2026-07-16)

**Proprietary metric names (user decision 2026-07-16):** UI says **TrustSignal
(TS)**, **CiteSignal (CS)**, **TopicSignal (TT)** — payload keys stay
`scores.trust/citation/topical` and row keys `ts`/`cs`. Table column headers
may use the short forms Trust/Citation. Old internal doc references to "Trust
Score/Citation Score" mean TS/CS.

**TopicSignal (scores.topical):** `TT = round(TS · (0.4 + 0.6·relevant_pct/100))`,
null until a TrustSignal AND a topical sample exist. Deterministic — topical
inputs live in the payload (topics classified once, cached forever). Computed
in `AuthorityScoreCalculator::withTopicalScore()` on EVERY augment call (not
gated by scores.version, because the topical section fills batch-by-batch
after the scores are stamped).

**Topical classification covers ALL referring domains** (user chose full
coverage): `EnrichTopicalTrustJob` self-chains in batches
(`services.report.topical_trust.batch`=25, `total_cap`=1000, MAX_ROUNDS 60
backstop) — each run classifies the next uncached batch, merges into
`payload.topical_trust.rows`, recomputes topics/relevant_pct over everything
so far, stamps `sample`/`total`, and re-dispatches itself until done. The UI
shows "N of M referring domains analyzed" with a spinner and polls
`report.status` (`topical_done`/`topical_total`), reloading once complete.
Pending stub (`topical_trust.pending`, stamped by GenerateWebsiteReport) is
cleared on every job bail-out so the spinner can never get stuck; the read
side also expires stubs older than 30 min. NO full-page reload loops anywhere
— all pending UIs (report build progress stepper, backlinks pending, topical
cards) poll the tiny authed JSON endpoint `GET /report/status` and reload
exactly once when ready.

Our own Majestic-TF/CF-analogue 0–100 metrics, computed **deterministically from
data already in the payload** — zero new provider cost. `App\Services\Reports\
AuthorityScoreCalculator` (pure, no I/O; seeds injectable for tests):

- **Citation Score** (popularity): weighted blend, weights renormalize when a
  component is missing — OPR `popularity.score×10` (w .50) + `gauges.
  authority_score` (DataForSEO rank/10, w .30) + `min(100, 100·log10(1+
  referring_domains)/6)` (w .20).
- **Trust Score** (quality): weighted mean (null if <2 components) of
  inverse spam (.25), strong-referrer share — rows with `rank≥300 or opr≥4.0`,
  `min(100, 250·share)`, needs ≥3 rows (.25), dofollow `min(100, pct·1.25)`
  (.15), IP/subnet diversity `min(1, avg(ips/rd,subnets/rd)/0.8)·100` (.15),
  gov/edu/mil TLD share ×1000 (.10), curated seed matches ×20
  (`config/trusted_seed_domains.php`, registrable-domain matching, deduped)
  (.10). **Ceiling: `TS ≤ CS+10`** (55 when CS null) — keeps pairs plausible.
- Per-row scores on `top_referring_domains`/`backlinks`/`competitors` (v3):
  `cs` = blend of OPR (.40) + CC PageRank percentile (.35) + DataForSEO rank
  (.25), renormalized; `ts` = the row domain's CC harmonic-centrality
  percentile, with a floor of 85 for curated seed domains, null (renders "—")
  when neither is known. Row percentiles are batch-stashed as
  `cc_citation`/`cc_trust` by `ClientReportService::stashRowCcRanks()` (one
  chunked sidecar lookup per payload). Dashboard referring-domains table +
  report web/PDF tables show separate Trust and Citation columns.

**Wiring — compute-on-write + backfill-on-read, deliberately NO
`PAYLOAD_SCHEMA` bump** (a bump triggers paid DataForSEO regeneration via
`ReportViewController::resolve()`; scores need no new provider data):
`augment()` runs at the end of `assemble()`/`assemblePartial()` AND first thing
in `withTraffic()` — the single read choke point (report view, `/backlinks`,
`/competitors`, public share, PDF export) — so every pre-score cached snapshot
gains scores in-memory on first render, no migration, no write-back. Idempotent
via `payload['scores']['version']`; **formula changes MUST bump
`AuthorityScoreCalculator::VERSION`**.

UI: `/backlinks` gets two ring cards ("Link quality"/"Link popularity",
i18n'd in `lang/en.json`+`ar.json`) and band-colored pills (≥60 emerald /
30–59 amber / <30 rose — all classes verified in the compiled Tailwind
bundle) replacing the old `opr/10` Authority cells (which remain as
fallback when `cs` is null); `/competitors` same pill; report web+PDF gauge
rows are 6-up with Trust/Citation first (report bodies stay hardcoded
English by convention).

**Trademark rule: never label these "Trust Flow"/"Citation Flow" or mention
Majestic in client-facing copy.** Tests:
`tests/Unit/AuthorityScoreCalculatorTest.php` (exact-value fixtures),
`BacklinksTest::test_scores_are_backfilled_onto_pre_score_snapshots_without_regeneration`,
`ClientReportTest::test_assemble_emits_trust_and_citation_scores`.

### Common Crawl web-graph sidecar (formula v2 input)

`app/Services/Reports/CcDomainRanks.php` reads a **read-only SQLite sidecar**
at `storage/app/cc-domain-ranks.sqlite` (~121M domains: harmonic-centrality
rank = trust signal, PageRank rank = citation signal; percentile =
`100·(1−log10(rank)/log10(N))`, N from the sidecar `meta` table; registrable-
domain fallback). Built by **`ebq:import-cc-webgraph`** (quarterly, manual —
pass the new `--release=` name from the CC blog; heavy lifting is
`zcat|awk|sort|sqlite3` at C speed — the **sort stage is load-bearing**: the
table is WITHOUT ROWID keyed by domain, and unsorted input degrades to random
b-tree inserts that run ~10× slower at 121M rows (observed live 2026-07-16;
sorted full import ≈ 20 min). Temp-file + atomic rename so readers never see
a half-built DB; `--top`/`--total` for trimmed imports;
`--snapshot-history` also updates `domain_metrics` cc ranks + appends history
rows). Deliberately SQLite NOT MariaDB — 121M rows would blow the 2G InnoDB
buffer pool. `ClientReportService::scored()` stashes the target's percentiles
into `payload['cc']` before augment; absent sidecar/domain → calculator
weights renormalize (v1-equivalent behavior). Quarterly data refreshes do NOT
bump the formula VERSION; formula changes do.

### Topical Trust enrichment (payload `topical_trust`)

`app/Jobs/EnrichTopicalTrustJob.php`, dispatched from `GenerateWebsiteReport`
after the ready write, config-gated `services.report.topical_trust.enabled`
(`REPORT_TOPICAL_TRUST_ENABLED`, default on; `max_domains` 15). Flow: top
referring domains → homepage title/meta via `CrawlFetcher` (free,
SSRF-guarded) → **one** `completeJson` call (fixed 15-topic taxonomy +
`relevant` bool vs the target site) → guarded payload patch (same pattern as
ReportEnrichmentService; failure = section absent). Topics are **cached
permanently in `domain_metrics.topic`** — each domain is ever classified once
platform-wide, so LLM cost decays toward zero. Deliberately does NOT feed the
Trust Score number (scores stay deterministic). Renders as topic chips +
relevance bar on `/backlinks` + report web body (`topical_trust` fully
guarded). Tests: `tests/Feature/EnrichTopicalTrustJobTest.php`.

### Domain-intelligence asset (`domain_metrics` + history)

`app/Services/DomainIntel/DomainMetricsRecorder.php` harvests every non-
sandbox report generation (main + top-100 referring + top-25 competitor
domains) into `domain_metrics` — COALESCE upserts (nulls never erase),
`times_seen` increments, `first_seen_at` write-once, tier only promotes
(active = owned website). **Rows are never deleted on client churn** — free
feeds keep their history growing. `domain_metric_history` is append-only
`(domain, source, captured_on)` — sources `opr | cc_harmonic | cc_pagerank`.
Monthly `ebq:refresh-domain-metrics` (scheduled `monthlyOn(3, 02:40)`) sweeps
stale domains through the free OPR bulk API (quota governor: staleness-first,
`times_seen`-ordered, default cap 28k of the 30k/mo free quota) + re-reads the
local CC sidecar. Tests: `tests/Feature/DomainMetricsRecorderTest.php`.

### Tier-1 link graph (passive edge harvesting)

`app/Services/LinkGraph/EdgeRecorder.php` deposits external outlinks of pages
we ALREADY fetch into append-only tables `link_domains` (registrable-domain
dictionary) / `link_urls` (from-page, sha1-path-hash unique) / `link_edges`
(unique `from+to+source`, sources `own_crawl | cc_wat | enrichment`,
dofollow sticky-true, `first_seen`/`last_seen`, never deleted). Call sites:
`PageCrawlProcessor` (post-analysis, every crawled page) +
`ReportEnrichmentService::fetchPageText()` homepage fetch. `HtmlAuditor::
links()` now carries a `nofollow` bool per link. Recorder NEVER throws
(internally caught) — a failure can't break the parent crawl. Growth ~2–4
GB/yr; schema deliberately ports to ClickHouse unchanged (BIGINT id
dictionaries, source tags, append-only). **Worker box B runs the crawler —
deploy + Horizon restart required there for edge collection to start.**
Tests: `tests/Feature/EdgeRecorderTest.php`.

## Surfaces & routes

- **Public share** — `GET /r/{token}` → `PublicReportController` (`throttle:60,1`).
  Resolves a `report_shares` token (`App\Models\ReportShare`, 40-char `Str::random`)
  → website → snapshot. **Bad/revoked/expired tokens 404, never 403** (no
  enumeration). Mint/revoke via `POST|DELETE /report/share` (`ReportShareController`).
- **PDF export** — `GET /report/download` → `ClientReportExportController`
  (session website + `canViewWebsiteId`, `?whitelabel=0`, `throttle:10,1`), mirrors
  `SiteAuditExportController`.
- **Authed view** — `GET /report/view` → `ReportViewController` (`auth`, NOT
  `verified` — first value lands before email verification).

## Homepage "Analyze website" funnel

`resources/views/partials/analyze-hero.blade.php` is now the SOLE homepage hero in
`landing.blade.php` (the old guest page-audit form was removed from the landing page —
it still lives on its own `/free-audit` page linked from the "Free tools" pills). URL
box + Analyze → `POST /analyze` (`WebsiteAnalyzeController`), which returns a
`results_url` the JS navigates to.

**Key rule — no anonymous provider spend:** a signed-OUT Analyze dispatches NO job and
calls NO DataForSEO/Moz API. `/analyze` validates the URL (per-IP throttle — **anonymous
requests only, see below** — + `SafeHttpGuard` + reCAPTCHA), stashes `session('analyze_domain')`, and returns the
`report.view` URL. That page (`ReportViewController`, public) renders a **blurred MOCK
teaser** (`ClientReportService::sampleTeaserPayload()` — illustrative numbers, never
persisted) behind an inline signup modal (name/email/**phone**/password → `POST
/register`). Only a signed-IN Analyze dispatches `GenerateWebsiteReport`.

**Post-signup the funnel domain is ATTACHED, not just shown (2026-07-15).**
`RegisteredUserController::store()` pulls `analyze_domain` ONCE before any branch,
runs `App\Services\WebsiteAttachService::attach()` (the extracted
`WebsitesList::addWebsite()` recipe: `Website::updateOrCreate` + 365-day
`ebq:import-historical` + `CrawlSiteBootstrapper::subscribeWebsite` + session pin —
so crawl/Site Health/GSC-import start immediately and the onboarding gate is
satisfied), then redirects to `report.view?url=<analyze_domain>` (still auth-only,
NOT verified-gated — first value before email verification). The **pay-first
branch no longer orphans the domain**: it stashes it as `session('onboarding.domain')`,
which `ConnectGoogle::mount()` now prefills unconditionally (was Google-connected-only).
Signin attaches too, but ONLY for accounts with zero websites (existing users
looking up a competitor must not silently spend a plan slot). `WebsitesList::addWebsite()`
now delegates to the same service and thereby gained the previously-missing
`canAddWebsite()` plan gate (blocked → billing, mirroring onboarding).
Tests: `tests/Feature/RegisterFunnelAttachTest.php`.

## Site Explorer (dashboard entry) + per-plan lookup limits

The report tool is surfaced in the authed app as **"Site Explorer"** — nav item in
`components/layouts/app.blade.php`, route `Route::view('/site-explorer', 'site-explorer')`,
page `resources/views/site-explorer.blade.php` (URL box → `POST /analyze` → `report.view`).

**Per-plan lookup throttle** (editable in the admin Plan editor): plans have
`site_explorer_limit` (max lookups, null = unlimited) + `site_explorer_window_hours`
(rolling window). Enforced in `WebsiteAnalyzeController` via `RateLimiter` keyed by user
(admins exempt). Defaults: trial **2/24h**, solo **20/1h**, pro **70/24h**, agency
**150/24h**, enterprise unlimited. Editable at `/admin/plans/{plan}/edit`
(`PlanController::validatePlanInput` + `admin/plans/edit.blade.php`). Plan helpers:
`Plan::siteExplorerLimit()` / `siteExplorerWindowHours()`. **Set on prod via targeted
column update, NOT a full PlanSeeder re-run** (that would overwrite admin plan edits).

**Per-IP throttle is ANONYMOUS-only (2026-07-14 fix).** `WebsiteAnalyzeController` also
has a coarse per-IP guard (5/min, 30/day, keys `analyze:m:{ip}` / `analyze:d:{ip}`) meant
to stop pre-signup scraping of the free teaser, where no other governance exists yet (no
account, no plan). It used to run unconditionally for EVERY request, authenticated or
not — which meant an authenticated user could get blocked by the generic "You've reached
the limit of 30 analyses per day" message (hiding the real, more specific per-plan
message) purely because their IP had made 30 requests that day, e.g. from a shared/office
IP, or even for a domain they already own. Fixed: both the check and the `RateLimiter::hit()`
now run only `if (! Auth::check())` — a signed-in request is governed exclusively by the
per-plan limiter above (which is domain-aware: exempt for the user's own websites, deduped
per-domain per window — see below). Anonymous requests are unaffected.

**Only a genuinely NEW distinct lookup consumes the quota** (2026-07-14). Two
exemptions, both in `WebsiteAnalyzeController::store()`:
- A domain that's one of the user's own attached `Website` rows never counts
  (`ownsWebsiteForDomain()` — `canViewWebsiteId` check, so team members are
  covered too), regardless of how many times they open it.
- Re-analyzing a domain they've already been charged for **within the current
  window** doesn't count again — tracked via a parallel cache marker
  `site-explorer-seen:{userId}:{domain}` with the SAME TTL as the plan's
  window (`RateLimiter::hit()` only fires, and the marker is only set, on a
  cache-miss for that marker). A DIFFERENT user analyzing the same domain is
  unaffected — the marker is per-user. Both the limit CHECK and the block
  response are skipped entirely for exempt lookups, so a user already at
  their limit can still re-open an already-counted domain or their own site.
  Test: `tests/Feature/SiteExplorerLimitDedupTest.php`.

**"Recently analyzed" history** on the Site Explorer page itself
(`<livewire:site-explorer-history>`, `App\Livewire\SiteExplorerHistory`) — reads the
user's own `site_explorer.query` `ClientActivity` rows (same log the admin usage
page reads), deduped to one entry per domain (most recent lookup wins), newest
first, capped at 15. Flags entries matching one of the user's own websites with a
"Your site" badge.

## Post-signup landing hub — `/overview`

`ConnectGoogle::finishOnboarding()` redirects a brand-new user to
`route('website-overview')` (was `dashboard` until 2026-07-13), landing them on
their own site's Site Explorer report with a top tab bar: **Site Explorer · Site
Health · Statistics**. Controller: `App\Http\Controllers\WebsiteOverviewController`;
view: `website-overview.blade.php`.

- **Site Explorer tab** — resolves the report for `$website->normalized_domain`
  via `ReportViewController::resolve()` (factored out of `show()` so the standalone
  `/report/view` page and this tab share identical pending/ready/no-data logic —
  `reports/_status.blade.php` is the shared partial both render). No URL typing
  needed; auto-generates on first view like any other authed Analyze.
- **Site Health tab** — embeds the existing `crawl-banner` /
  `dashboard.site-health-stats` / `dashboard.priority-action-queue` Livewire
  components verbatim (same polling, same cache, same everything).
- **Statistics tab** — a faithful replica of `/statistics` (same components, same
  layout/order: sync-and-report-panel, `kpi-cards`, country-filter, `insight-cards`,
  the 3-col `traffic-chart`/`top-countries`/`seasonality`/`quick-wins` grid), merged
  2026-07-13 from what were originally two separate tabs (Traffic Statistics / GSC
  Performance). `kpi-cards`/`traffic-chart` mix GA+GSC by design and already degrade
  gracefully with partial data, so they're never gated. Only the confirmed
  single-source blocks — `insight-cards` + `seasonality-card` (pure GSC) — swap in
  `<x-connect-source-prompt>` / `<x-overview.processing-panel>` when GSC isn't
  ready; `traffic-chart` gates on GA the same way.
- Every tab pill is a **real, checked signal** — `Website::isCrawling() ||
  isInitialCrawl()` (both — `isInitialCrawl()` alone goes false as soon as a site
  has EVER completed a crawl, so a RECRAWL of an established site would misread as
  "Ready" while the crawler is actively running), `hasGa()`/`hasGsc()`,
  `AnalyticsData::where('website_id', ...)->exists()`,
  `ReportDataService::lastSafeReportDate()`, and a real `WebsiteReportSnapshot`
  lookup for the Explorer tab (was hardcoded to always show "Processing" until
  2026-07-13, even on a cache hit) — the same checks the rest of the app already
  uses for the identical question, never inferred from cache warmth.

The old dashboard "just_onboarded" welcome modal (`resources/views/dashboard.blade.php`)
is removed — dead code once the redirect target changed (the flash flag never
reached `/dashboard` as the first post-onboarding request anymore).

**The tab bar only shows when a website is genuinely identified — never guessed.**
`<x-website-tabs :website :active>` (`resources/views/components/website-tabs.blade.php`)
is embedded on `dashboard.blade.php` (active=`health`), `statistics.blade.php`
(active=`statistics`), and `reports/view.blade.php` (active=`explorer`, **only**
when `$website` is non-null). It is deliberately NOT on the bare
`site-explorer.blade.php` tool page — that's a generic "analyze ANY domain" form
with no site context until submitted, and showing nav tied to a stale
session-pinned website there was the original bug (2026-07-14): analyzing an
account's own attached domain from Site Explorer showed no Site Health/Statistics
nav, while the same domain via the dashboard worked fine — because the tab bar was
reading whatever website happened to be session-pinned, not the domain actually
being analyzed. Fixed in `ReportViewController::resolve()`: the domain's `Website`
match is now returned in EVERY branch (was only in the ready-payload branch before),
and `session(['current_website_id' => $website->id])` is pinned whenever the
analyzed domain IS one of the user's own websites — so the nav (and the Site
Health/Statistics tabs it links to) always reflects the site actually being viewed,
and never appears for an arbitrary/competitor domain lookup. Status logic lives in
`App\Services\WebsiteTabStatus` (`forWebsite()` + `currentWebsite()`), extracted out
of the controller specifically so the Blade component and the global
`current_website()` helper (`app/Support/helpers.php`) can both call it — `dashboard`/
`statistics` have no controller to inject a website into (`Route::view()`), so they
resolve it themselves via `current_website()` in a `@php` block.

**Adding a website = same landing as onboarding (2026-07-14).**
`WebsitesList::addWebsite()` (the dashboard "add website" form) now pins the new site
as `current_website_id` and redirects to `/overview?tab=explorer` when the row was
newly created — the exact same landing `ConnectGoogle::finishOnboarding()` gives a
fresh signup. Nothing new is dispatched there: the overview Explorer tab already kicks
the initial Site Explorer generation on first view (freshness-gated / shared-snapshot
aware), the crawl (`CrawlSiteBootstrapper::subscribeWebsite`) + 365-day GSC/GA import
(`ebq:import-historical`) were already queued by addWebsite() itself, and the Site
Health / Statistics tab pills show processing / needs-action per source. Re-adding an
existing domain keeps the old no-redirect behavior. Test:
`tests/Feature/AddWebsiteFlowTest.php`.

## Empty-domain enrichment — partial reports for young sites (2026-07-15)

When DataForSEO's backlink index has nothing for a domain (brand-new site), the
report no longer dead-ends on "No backlink data". State machine on
`website_report_snapshots.status` (+ additive `enrichment_state` JSON column):

```
summary!==null -> 'ready'                        (full report, unchanged)
summary===null:
  eligible   -> 'enriching' --EnrichEmptyReportJob--> await stages --Finalize--> 'partial'
                 (partial TTL 10d lapses -> regenerate -> 'ready' or re-enrich)
  ineligible -> 'no_data'   (terminal; only sandbox / kill-switch now)
```

Invariant: **payload non-empty iff status ∈ {ready, partial}** — every
`empty($snapshot->payload)` consumer stays correct unmodified.

**Pipeline** — `App\Services\Reports\ReportEnrichmentService` (all provider calls
container-injected, jobs thin):
1. `bootstrap()` (via `EnrichEmptyReportJob`, `tries=1`, unique): Open PageRank
   popularity (free) + Moz DA/PA (free tier, own domain) + ≤3 pages fetched via
   `CrawlFetcher`/`HtmlAuditor` (SSRF-guarded) + **self-hosted keyword fleet**
   `KeywordFinderPool::dispatchIdeas(url, scope=site)` (monthly-cache-checked;
   NEVER the paid KeywordsEverywhere API).
2. `FinalizeReportEnrichmentJob` polls (30s self-redispatch, ≤40 attempts /
   2×12min budget — always terminates). Own keywords completed → ONE
   `LlmClient::completeJson` call (active provider, `report_enrichment` source)
   classifies **genuine vs boilerplate** ("signup/login" — Google Ads noise on
   content-less new sites) AND generates 5-10 realistic search queries from the
   page text.
3. Competitors are discovered for EVERY enrichment (2026-07-15, not just the
   fallback path): ≤8 (`serp_query_cap`) `SerpCache::organic()` calls on the
   LLM's queries tally competitor domains (giants filtered via
   `App\Support\GiantDomains`, extracted from CompetitorDiscoveryService;
   scoring reuses `CompetitorDiscoveryService::score()`), OPR-ranked; the tally
   fills `competitors` (competitorRows-compatible shape — KeywordGapAnalysis reads
   it). Genuine keywords → payload `keywords` (cap 25). Boilerplate/empty → the
   best competitor's site keywords additionally become `keyword_opportunities`.
4. `finalize()` = atomic conditional UPDATE `WHERE status='enriching'` →
   'partial' (KeywordGapService::maybeAggregate claim pattern; two-box safe).
   EVERY failure path finalizes with whatever exists (minimum: popularity +
   gauges) — never a stuck row; short TTL is the extra self-heal.

**Payload additions** (`ClientReportService::assemblePartial()` — `assemble()`'s
shape + `keywords`, `keyword_opportunities`, `meta:{partial, opportunity_source,
sources}`). `meta.sources` drives the **data-source badges**: keywords =
"Estimated" (planner) or "From Google Search Console" (real), keyword_opportunities =
"From a similar site: X", partial competitors = "Found via related search results" —
every non-direct data point is tagged in web + PDF renderers, plus a "looks like a
new website — we gathered the closest available data" banner.

**Payload schema versioning + self-heal (2026-07-15):** `ClientReportService::PAYLOAD_SCHEMA`
(=2) is stamped into every payload's `meta.schema`. `ReportViewController::resolve()` serves a
present payload WITHOUT a freshness check (only empty payloads regen), so a snapshot generated
before a schema bump would keep serving old sections until its TTL lapsed. Fix: when resolve()
serves a payload whose `meta.schema` is behind `PAYLOAD_SCHEMA`, it dispatches ONE background
`GenerateWebsiteReport(force: true)` (ShouldBeUnique dedups; the regen rewrites the current
schema, so it bills at most once per domain) and keeps rendering the current payload — new
sections appear on the next view. `isFresh()` stays TTL-only (billing/cron/dedup semantics
unchanged); schema staleness is a SEPARATE view-path trigger. Bump `PAYLOAD_SCHEMA` whenever
assemble()/assemblePartial() emit a new section. `ClientReportService::isPayloadCurrent()`.

**GSC merge is cached — do NOT run it uncached (2026-07-15).** `withTraffic()`'s two
Search Console aggregates (traffic strip + top-25 queries) are heavy GROUP BYs over
`search_console_data`; on a large site the 30-day window is 500k+ rows and each pass
takes ~10-17s (measured 46s combined on namesforfreefire.com, 572k rows/window),
which was blocking the report page on every view. Now bundled in one
`Cache::remember('client-report-gsc:{websiteId}:{ReportCache::version}', 24h)` via
`gscBundle()` — version-keyed so the nightly GSC sync's `ReportCache::flushWebsite()`
busts it (same invalidation the dashboard/Insights aggregates use). Cold view ~29s,
warm ~8ms. TRADEOFF: the first view after a sync (version bump) still pays the cold
cost once; pre-warm large sites if that matters. `gscTopQueries()` is the GSC keyword
override (see below); it MUST stay inside the cached bundle.

**GSC-first keywords (2026-07-15):** `withTraffic()` also swaps the keyword section —
a GSC-connected owner sees their REAL top Search Console queries (last 30d, by
clicks; `gscTopQueries()`, columns Clicks/Impressions/Avg position) instead of the
planner estimates. Merged at render time only, like the traffic strip — GSC data is
private and never enters the cross-tenant snapshot; non-owner viewers still see the
estimated set. **Section placement:** keyword tables render ABOVE competitors for
partial (new-site) reports — they're the headline value there — and BELOW competitors
for full reports (`reports/partials/report-keywords.blade.php`, included at one of two
slots in web-body; same ordering in the PDF `_body`). Own-site authed views (feature
`keywords`) get "Run a Keyword Gap analysis" deep-research links under the keyword +
competitors sections (`gapUrl`, passed from `_status` — never on public shares /
competitor lookups).

**Config** (`config/services.php` → `report.partial_ttl_days` + `report.enrichment.*`):
`REPORT_ENRICHMENT_ENABLED` (kill switch → exact old terminal-no_data behavior),
`REPORT_ENRICHMENT_ATTACHED_ONLY` (default false — ALL authed lookups enrich),
`max_pages` 3, `serp_query_cap` 8, `llm_max_tokens` 1200, `ideas_timeout_minutes`
12, `poll_seconds` 30. Costs: DataForSEO **zero** in enrichment; Serper metered
(`serp_api`, 7-day shared SerpCache); LLM metered to the owner website's user
when the domain is attached; keyword fleet ~free; NOTE Moz free tier (50
rows/mo) is now also consumed by empty-domain enrichments — monitor.

**Views:** `_status.blade.php` gets an `enriching` pending variant;
`web-body.blade.php` + PDF `_body.blade.php` add the partial banner, keyword
sections, source badges, a "Top pages by backlinks" section and a "Link profile
details" section (see below); `backlinks.blade.php` shows a neutral
"no backlinks discovered yet" card for partial payloads;
`WebsiteTabStatus::explorerStatus()` maps enriching → processing.

Tests: `tests/Feature/ReportEnrichmentTest.php`.

## Competitor Discovery page + flexible Keyword Gap target (2026-07-15)

**Competitor Discovery** (`/competitor-discovery`, Orbit nav, `feature:keywords`) — find
competitors for ANY url, SERP-minimally. `App\Livewire\Competitive\CompetitorFinder` +
`App\Jobs\DiscoverCompetitorsJob` reuse the report-enrichment pipeline via new PUBLIC methods
on `ReportEnrichmentService`: `keywordIdeasFor()` / `keywordRowsFor()` (fleet, monthly-cache-first),
`keywordsGenuine()` (cheap keyword-only LLM junk-check — no page fetch), and
`discoverCompetitorsFor()` which is the SERP-saver: **genuine keywords ARE the SERP queries (no
crawl, no query-gen); only scrap keywords (login/signup/…) trigger a 3-page crawl + LLM query
generation**, then one capped `SerpCache` tally (cap `report.enrichment.serp_query_cap`, default 8).
Async: page dispatches keyword ideas → polls the fleet request → dispatches the discovery job →
polls a shared 7-day result cache (`DiscoverCompetitorsJob::cacheKey`). Directory noise still
passes `GiantDomains` (clutch.co / semrush directories not filtered — known).

**Gap reports are revisit-able (2026-07-15):** analyses were always persisted
(`keyword_gap_analyses` + rows, `expires_at` = gap cache days) but the page forgot them.
Now `KeywordGapAnalysis::mount()` (and every target-URL change) restores the target's most
recent COMPLETED analysis instantly — zero keyword-fleet/SERP spend on revisit. The summary
bar gained: a saved-report history picker (last 8 per target; foreign-URL runs readable ONLY
by their creating user — `analysesForTarget()`/`loadAnalysis()` gates), "generated X ago" +
a "May be outdated" pill past expires_at, and an explicit **Refresh** (`refreshAnalysis()`,
deliberately bypasses `latestFresh` for a fresh billed run). Restored reports open with the
verification banner dismissed (no re-shout); a new verify pass re-arms it. Old analyses stay
readable forever (read of stored rows) — expiry only governs the auto-reuse cache.

## Per-plan usage caps for research spend (2026-07-15)

The dormant UsageMeter caps are now ARMED: every active plan's `api_limits` defines
`serper.monthly_calls` (100/1k/4k/12k trial→agency), `mistral.monthly_tokens` (research-LLM
pool, 50k/250k/1M/4M — distinct from ai_studio tokens), and the previously-unenforced
`keyword_research.monthly_searches` (50/250/1k/4k) is now REAL: `UsageMeter` gained the
`keyword_finder` provider and `KeywordFinderPool::dispatch()` gates + logs every metered
dispatch (`keyword_finder.dispatch` activity rows, reserve→release symmetric with serper).
Enterprise = null = unlimited. Seeded via PlanSeeder (updateOrCreate); admin plan editor
already validates all three keys.

**Metering policy:** only user-INITIATED lookups spend quota — idea/volume finder, gap runs
(1 + N competitors dispatches), Competitor Discovery page, gap inline find (billed user id now
threaded through `DiscoverCompetitorsJob` → `discoverCompetitorsFor`/`tallyCompetitors`/
`keywordsGenuine`, fixing the unattributed-in-jobs bypass). Platform-initiated spend stays
UNmetered by design: report enrichment (funnel/new-site, `meter:false` when no billed user)
and `KeywordMetricsService`'s automatic volume cache-warming. Cache hits never spend (metering
sits inside the live clients). Quota errors surface as the pool's friendly failed-request
message; UI shows "N live checks / keyword searches left this month" next to the gap Verify
button and on the Competitor Discovery form (owner's quota for team members). Tests:
`tests/Feature/PlanUsageCapsTest.php`.

**Gap verification is cache-first (2026-07-15):** `KeywordGapService::verify()` walks EVERY
unverified candidate (volume-first) instead of `LIMIT 25`: keywords whose SERP is already
fresh in the cross-tenant `serp_cache` verify for FREE (new `SerpCache::cached()` peek — never
calls Serper; cap `gap_verify_cached_max`, default 150/pass, bounded by job runtime not spend),
while true cache misses spend the live budget (`gap_verify_max`, 25). `startVerification` sizes
`verify_total` as cached+live (`SerpCache::freshCount()`, one hash whereIn). A quota hit now
zeroes the live budget but KEEPS verifying the free cached rows. Result: Weak/Strength fill out
far beyond 25 rows per click at identical Serper cost. Also: the Shared tab stays visible after
verification while unverified shared rows remain (was hidden → rows stranded).

**Keyword Gap flexible target** — `KeywordGapAnalysis` gained a target URL input (default the
current site). Owned domain → loads its snapshot competitors (unchanged). Foreign URL →
`targetIsForeign`, competitors cleared for manual add + a "Find competitors" link to the discovery
page pre-filled. `KeywordGapService::start()` now delegates to `startForTarget(?Website, $ourUrl, …)`;
a null website runs the keyword-fleet-only Missing/Shared path (no GSC → no Weak/Strength). Migration
`…make_website_id_nullable_on_keyword_gap` makes `keyword_gap_analyses.website_id` nullable (additive).
Downstream `aggregate()`/`verify()` were already `?->`-null-safe. Tests: `KeywordGapCompetitorPickerTest`,
`ReportEnrichmentTest` (discover_competitors_*).

## /keywords fallback to enrichment suggestions (2026-07-15)

`KeywordsTable` (`app/Livewire/Keywords/KeywordsTable.php`, the `/keywords` page) sources
keywords ONLY from `search_console_data`, so a site with no usable GSC data was a dead end
("No keyword data yet"). It now falls back to the SAME keyword suggestions the report uses.

Trigger (unfiltered first page only, so sorting/searching a real list never fires it): GSC
rows empty, OR `App\Support\KeywordJunkHeuristic::mostlyJunk($topQueries, $domain)` — a
cheap, no-LLM detector for auth/nav/brand boilerplate (login, signup, create account, the
brand token…; the report's LLM junk-check is the deeper paid version).

`App\Services\Keywords\WebsiteKeywordSuggestions::for($website)` returns the site's shared
`WebsiteReportSnapshot` `keywords` + `keyword_opportunities` when present (`ready`), else kicks
the pipeline and reports `processing`: no snapshot / empty → `GenerateWebsiteReport` (freshness-
gated; its summary-null path runs the new-site keyword+competitor enrichment); a `ready` report
lacking a keyword section → `EnrichEmptyReportJob(keywordsOnly)` (free fleet backfill, no
re-bill). `unavailable` when DataForSEO isn't configured. The view renders a sky "Keyword ideas
for your site" panel (Estimated / From-a-similar-site badges + Keyword Gap link) and
`wire:poll.10s` while processing. Jobs are `ShouldBeUnique`, so poll re-dispatches are no-ops.
Tests: `tests/Feature/KeywordSuggestionsFallbackTest.php`.

**Explore-all → full Keyword Research (2026-07-15):** the /keywords fallback shows a
12-row preview per section, then an "Explore all keyword ideas" link that hands off to
the existing `KeywordIdeaFinder` (the full paging / sort / 7-filter / term-group / AI-cluster
surface) in WEBSITE mode, auto-run on the site's (or the opportunity competitor's) URL —
no duplicate table built. Wiring: `KeywordResearch` hub gained a `#[Url] $url` that builds a
website handoff (`presetForActiveTab()` now carries url/mode/scope); `KeywordIdeaFinder::mount()`
gained a website-preset branch that seeds mode/url/scope and runs. Link:
`route('keyword-research.index', ['tab'=>'ideas','url'=>'https://'.$domain])`. No site-ownership
check (finder generates for any URL). Tests in `KeywordSuggestionsFallbackTest`.

**Ready reports also get SERP competitor discovery (2026-07-15):** when DataForSEO returns
a full report but NO competitors (`competitors=[]` — common for small domains, e.g.
daomarketing.com), `ReportEnrichmentService::bootstrapReadyKeywords` (dispatched as
`EnrichEmptyReportJob(keywordsOnly)`) now runs the SAME SERP discovery the new-site path uses:
pages → LLM queries → SERP tally → competitors (merged, `sources.competitors='search_results'`)
→ best competitor's keywords as `keyword_opportunities`. `meta.competitors_enriched=true` marks a
single attempt so repeat /keywords views don't re-spend SERP/LLM. It ALSO backfills
`top_pages` for free from the stored backlink sample (`ClientReportService::deriveTopPagesFromBacklinks`)
when DataForSEO's `domain_pages` was empty — assemble() applies the same fallback for new reports.
The async site-keyword + competitor-keyword fetches are merged by a two-slot `ready_merge`
poller stage (`advanceReadyMerge` + `patchReadyPayload`, which never clears state mid-flight).

**Ideas → real GSC auto-switch (2026-07-15):** `KeywordsTable` re-evaluates the fallback
on every render — once a GSC sync lands real (non-junk) keywords, `needsFallback` turns false,
the ideas panel disappears and the real GSC table shows. To make that live (not only on manual
reload), the fallback keeps a `wire:poll` while shown: `.10s` during enrichment (processing),
`.60s` once ideas are ready (re-checks for a sync). The poll stops the moment real GSC keywords
drive the page (suggestions null → no wire:poll). Test: "keywords page switches from ideas to
real gsc when synced".

**Keyword row cap (2026-07-15):** all keyword sections (site keywords, competitor
opportunities, GSC top queries) show up to `services.report.enrichment.keyword_rows`
(env `REPORT_ENRICHMENT_KEYWORD_ROWS`, default 100) — the self-hosted fleet returns
hundreds (856 seen), previously truncated to 25. Tables scroll. Raising the cap
backfills existing reports for FREE: `bootstrapReadyKeywords` re-runs when the stored
count is below the cap, re-slicing from the monthly-cached raw fleet result (no
keyword-server call, no DataForSEO rebill).

## Previously-hidden paid DataForSEO data now rendered (2026-07-15)

Audit of fetched-vs-rendered found data we paid for but never showed:
- **`top_pages`** (paid `domain_pages` endpoint) was assembled into every payload
  but rendered NOWHERE — now a "Top pages by backlinks" section (web + PDF).
- **Summary fields** dropped by `assemble()` — now a `profile_details` payload key
  ("Link profile details" section): `first_seen`, `crawled_pages`,
  `broken_backlinks`, `broken_pages`, top-10 `referring_links_tld` / `_countries`
  / `_platform_types` / `_types` distributions. Old cached payloads simply lack
  the key (section hidden) and fill in on TTL regeneration — no backfill.
- **Labs competitor `metrics.organic.count`** → `organic_keywords` column on the
  report + `/competitors` pulse tables. ⛔ `etv` (dollar traffic value) is
  deliberately NOT surfaced — hard rule: no $ projections in the UI.
- **Keywords on full reports**: merged asynchronously post-generation
  (`ReportEnrichmentService::bootstrapReadyKeywords`, stage `ready_keywords`,
  conditional UPDATE guarded on status/fetched_at so a concurrent regeneration
  wins) — tagged "Estimated".

## Dashboard "Competitors" page — `/competitors` (Pulse nav group)

Added 2026-07-14, same pattern as Backlinks below: `CompetitorsController` +
`resources/views/competitors.blade.php` — the organic-competitor slice
(`payload['competitors']`, DataForSEO Labs shared-keyword domains, ~1000 rows since the
display-cap raise) for the current website. Headline cards (competing domains / most
shared keywords / top competitor), scrollable table (#, domain, shared keywords, avg
position, OPR authority) with a client-side Alpine domain filter. Each domain links to
its own `report.view` — with a visible note that competitor lookups consume the plan's
Site Explorer limit. NOTE: the legacy `/backlinks` route collision below is why BOTH
these pages use controller routes registered BEFORE the `Route::view` block — check
`php artisan route:list` for URI collisions when adding more Pulse pages.

## Dashboard "Backlinks" page — `/backlinks` (Pulse nav group)

Added 2026-07-14: `BacklinksController` + `resources/views/backlinks.blade.php` — the
backlink slice of the Site Explorer snapshot for the CURRENT website
(`WebsiteTabStatus::currentWebsite()`), rendered in dashboard style (dark-mode aware)
instead of the report's fixed light "paper" theme. Reuses
`ReportViewController::resolve()` verbatim, so pending/no-data/dispatch behaviour and
the freshness gate are identical to the report page (opening it never double-bills; a
never-analyzed own domain auto-generates, same as the /overview Explorer tab). Sections:
headline stat cards (backlinks / referring domains / IPs / dofollow %), referring-domain
trend sparkline (+new/−lost this month), anchor-type split bars, and the three scrollable
tables (top referring domains, backlinks, anchor texts). Links out to the full
`report.view` page. Sidebar: Pulse group, `feature => null` (no plan gate — the per-plan
Site Explorer lookup limit governs generation, and viewing an existing snapshot is free).

## Admin usage page — who queried what

Every authed Analyze (`WebsiteAnalyzeController::store()`) writes one `client_activities` row
(`type='site_explorer.query'`, `user_id`=the querying client, `meta`={domain, cache_hit,
sandbox}) via `ClientActivityLogger`, computed from `ReportFreshnessGate::isFresh()` *before*
dispatching the job (the job itself no-ops silently on a cache hit, so this is the only place
that observes fresh-vs-cached at query time). Admin page at `/admin/site-explorer-usage`
(`SiteExplorerUsageController`, `admin.site-explorer-usage.index`) shows: summary cards
(queries/unique domains/unique clients/cache hits/real generations + REAL cost), a
per-client rollup, and a paginated raw query log (time, client, domain, fresh/cached badge,
link to the live report).

**Cost is real, not estimated (2026-07-14).** DataForSEO's own API response carries the
actual billed amount per call (`tasks[0].cost`) — `DataForSeoBacklinkClient` accumulates it
across every call in `totalCost()` (reset per instance; a queued job gets a fresh instance).
`GenerateWebsiteReport::handle()` reads it once after all DataForSEO calls complete and:
1. Stores it on `WebsiteReportSnapshot.dataforseo_cost_usd` (that domain's LATEST generation
   cost — gets overwritten on the next regeneration, so it's a point-in-time value, not a
   ledger).
2. Logs a `client_activities` row `type='site_explorer.generation'` (`meta`={domain,
   cost_usd}, no `user_id` — a generation isn't attributable to one user, since job dedup
   (`ShouldBeUnique`) can collapse several near-simultaneous users' fresh lookups of the same
   domain into ONE real generation) — THIS is the accurate historical ledger the admin page's
   "Real generations" / "Real cost" summary cards sum over the selected date range. Skipped
   entirely for sandbox generations (never billed). Also logged on the `no_data` terminal path
   (the summary call alone still costs money even when DataForSEO has nothing for the domain).
   Per-client and per-query-row costs are looked up from the domain's CURRENT snapshot value
   (`SiteExplorerUsageController::realCostByDomain()`) — real captured numbers, but an
   under-count if a domain regenerated more than once within the period (only the latest
   generation's cost is stored per domain, no per-event cost history). The old flat
   `services.report.generation_cost_usd` estimate is gone from this page.

This is a separate page from `/admin/usage` (KE/Serper/LLM) because Site Explorer isn't
metered per-unit — cost is flat-per-generation, not per-row/per-token, so it doesn't fit the
`UsageMeter`/rates pipeline.

**Remove report cache (2026-07-15):** the page has a "Remove report cache" form + a
per-row "Clear cache" button (`SiteExplorerUsageController::clearCache`, POST
`admin.site-explorer-usage.clear-cache`). Deletes the domain's production AND `sbx:`
snapshot rows plus the shared monthly keyword-ideas cache entry (the exact key
`ReportEnrichmentService` computes), so the next lookup runs a fresh generation /
full enrichment — built for re-testing new-site behavior. Deliberately does NOT
touch `client_activities` (usage/cost ledger stays historically accurate); logs a
`site_explorer.cache_cleared` activity with the admin's user_id. Next lookup is a
fresh BILLED generation — the button warns before submitting.

## Public tools — signup/login gate (all 4 tools)

Every public marketing tool (SEO Audit, PageSpeed, Rank Checker, Keyword Volume) now
requires an account to see any result — same gate as Site Explorer. Anonymous submit runs
**nothing** (no API): each controller's `store()` short-circuits at the top with
`if (auth()->guest()) return json(['require'=>'signup'])` (`GuestAuditController`,
`GuestPageSpeedController`, `GuestRankCheckController`, `GuestKeywordVolumeController` — 4
independent copies of one pattern). Run-path reCAPTCHA is guarded with `&& auth()->guest()`
so authed auto-runs don't need a captcha.

**The old "progressive friction" funnel is fully removed (2026-07-13):** the 4 guest
controllers no longer count runs via cookie, never email results, and never return
`require:'email'`/`require:'signup'` for authed users — a signed-in user always gets the
on-screen result (per-IP rate limits remain). The email modals + "Check your inbox" cards
were deleted from the 4 tool views + landing (view JS is null-guarded, so no breakage).

**Teaser opens on its own page showing the REAL report blurred:** anon submit returns
`results_url = /tools/preview/{tool}?<inputs>` (202); the tool JS's existing `results_url`
branch navigates there. `ToolPreviewController` (`app/Http/Controllers/ToolPreviewController.php`)
renders each tool's **real result view** (`guest-audit.show` etc.) with a **fabricated
unsaved model** (`status='completed'` + a sample `result` array — see the per-tool sample
shapes in the controller), overlaid with `partials/report-teaser-modal.blade.php` (a
`backdrop-blur-lg` full-viewport overlay + `partials/auth-modal.blade.php`). Each show view
got a one-line `@if ($teaser ?? false) @include('partials.report-teaser-modal', …) @endif`.

Shared UI: `partials/auth-modal.blade.php` (signup/signin toggle + phone dropdown + `_form` +
safe-local `redirect` hidden fields, honored by `Register`/`AuthenticatedSession`).
`partials/tool-gate.blade.php` is still `@include`d on each tool page for **post-auth
auto-run** (`?autorun=1` → refill form + re-submit → now authed → the tool runs for real →
its result page).

**Sample-shape gotchas (ToolPreviewController):** audit `content.keyword_density` must be an
array of `{term,count,density}` (not a float); pagespeed `opportunities`/`diagnostics` each
need a `rating` key; audit keeps `keywords.available=false`, `benchmark=null`,
`core_web_vitals=null` so the shared audit partial never hits DB/route/`->website`.
`auth-modal` guards `$errors` with `isset()` (it renders where `$errors` may be unshared).

## Signup phone (added)

`users.phone` (nullable column, additive migration); required in registration
(`RegisteredUserController::store` rule `phone` + `register.blade.php` input +
the analyze-hero modal) and `User::$fillable`. Email-verification flow unchanged.

## Gotchas

- **`domain_pages` nests metrics under `page_summary`, not top-level.** Fixed 2026-07-13 —
  `ClientReportService::topPages()` was reading `$r['referring_domains']`/`$r['backlinks']`
  off the top level, which DataForSEO never populates there (only `page`, `status_code`,
  crawl metadata live at top level); the real counts are under `$r['page_summary']`. Prior
  behavior: "Top content" showed real URLs with permanently-null counts, unsorted. Now reads
  `page_summary` with a top-level fallback, and explicitly sorts by `referring_domains` desc
  before slicing to 15 — same fix pattern applied to `competitorRows()` (sorts by
  `shared_keywords` desc before slicing to 10) since neither should trust API row order.
- **Moz free tier = 50 rows/mo** — call only on the own domain (1 row/report); use
  DataForSEO `rank` for competitor/referring-domain scores. Past 50 rows Moz throttles
  (no charge) → upgrade to $20 Starter.
- **Report is a fixed light "paper"** in both web and PDF by design — do not switch
  it to theme CSS vars, or the PDF (dompdf) breaks.
- **Gauges use arc `<path>`, not `stroke-dasharray`** — dompdf renders paths, not dasharrays.
- **`analyze_domain` in session** is the funnel handoff; don't clear it before the
  post-signup redirect.

## Key files

- Clients: `app/Services/{DataForSeoBacklinkClient,MozLinksClient}.php`
- Cache/gate/job: `app/Models/WebsiteReportSnapshot.php`, `app/Services/ReportFreshnessGate.php`,
  `app/Jobs/GenerateWebsiteReport.php`, `app/Console/Commands/RefreshPaidReports.php`
- Enrichment: `app/Services/Reports/ReportEnrichmentService.php`,
  `app/Jobs/{EnrichEmptyReportJob,FinalizeReportEnrichmentJob}.php`, `app/Support/GiantDomains.php`
- Funnel attach: `app/Services/WebsiteAttachService.php` (used by Register/Login controllers + WebsitesList)
- Assembly + render: `app/Services/Reports/{ClientReportService,ClientReportPdfRenderer}.php`,
  `resources/views/reports/**`, `resources/views/pdf/client-report.blade.php`
- Surfaces: `app/Http/Controllers/{WebsiteAnalyzeController,ReportViewController,PublicReportController,ClientReportExportController,ReportShareController}.php`,
  `resources/views/partials/analyze-hero.blade.php`
- Model: `app/Models/ReportShare.php`
- Tests: `tests/Feature/ClientReportTest.php`
