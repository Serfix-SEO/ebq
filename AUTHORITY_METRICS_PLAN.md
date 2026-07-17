# AUTHORITY METRICS PLAN — tier roadmap (living doc)

> **How to revisit:** this file (project root, committed with the repo). The
> engineering state of everything SHIPPED lives in
> `infra/reports/client-report.md`; this doc is the ROADMAP — what's built,
> what's deferred, and the trigger for each next tier.

## Status as of 2026-07-16 (all shipped same day)

- [x] **Foundation (Phases 1 / 1.5 / 2)** — TrustSignal / CiteSignal / TopicSignal,
      CC web-graph sidecar (121M domains imported), full-coverage topical
      classification, methodology page /trust-score
- [x] **Tier 1 — passive link graph** — EdgeRecorder on both boxes + provider
      backlink rows persisted permanently (`link_edges`, source-tagged)
- [x] **Beyond plan:** toxicity layer + disavow export, per-anchor index
      drill-down (plan-gated), monthly backlink-row quotas
      (trial 1k / solo 100k / pro 500k / agency 1.2M), sortable/filterable
      report tables, live-updating topical UI
- [x] **Tier 1.5 — targeted crawler** (SHIPPED 2026-07-16, dormant behind
      `LINK_CRAWL_ENABLED`). Frontier table + seed command + self-chaining
      crawl jobs + supervisor + Redis daily budget; reuses the site-audit
      crawler toolchain. Activate: env flag on both boxes + Horizon restart.
      Details: infra/reports/client-report.md § Tier-1.5.
- [ ] **Tier 2 — broad crawler ~1M pages/day.** Needs ONE Hetzner auction box
      (~64GB/2×1TB NVMe, €35–45/mo) for ClickHouse. Long-run value: fresh
      new/lost-backlink alerts at scale = the retention (churn) weapon.
- [ ] **Tier 3 hybrid — quarterly CC WAT page-level extraction** for tracked
      domains (~€10–20/quarter temp instances). Full web-scale parity stays
      rejected. Schema rules that keep this path open are in the plan below.
      Long-run value: margin (kills per-lookup provider cost), unlimited free
      site-explorer as acquisition funnel, provider independence, data moat.

## Revisit triggers (decided 2026-07-17 — do NOT build T2/T3 before one fires)

Both tiers are parallel datasets, NOT DataForSEO replacements (CC-based index
≈ 25% link completeness) — they add fixed burn before revenue. Startup posture:
variable cost that scales with revenue. Revisit when ANY of:

1. **DataForSEO bill > €250/mo for 2 consecutive months** (check the spend
   meter / admin usage page) → evaluate Tier 3 hybrid first.
2. **Unlimited free site-explorer wanted as an acquisition funnel** (per-lookup
   provider cost becomes the growth bottleneck) → T2/T3 become growth
   investments, not cost saves.
3. **~$5k MRR** or sustained link-crawl frontier ceiling (300k) + product
   demand for fresh-link alerts → Tier 2's ClickHouse box first (cheapest step).

Guards already in place instead: monthly spend circuit-breaker
(DATAFORSEO_MONTHLY_CAP_USD, 2026-07-17), complete-profile local aggregation
(~30% report-cost cut), per-plan explorer lookup windows.
- Quarterly ritual: `ebq:import-cc-webgraph --release=<new> --snapshot-history`
  when Common Crawl announces a release. Partner PDF:
  `/root/serfix-authority-metrics-roadmap.pdf`.

---

# Original approved plan (2026-07-16)

# Trust Score + Citation Score (Majestic TF/CF analogues)

## Deliverable 0 — Partner presentation PDF (BUILD FIRST, before any code)

Multi-page A4 PDF with near-to-real visual mockups of how each tier shows up in our product, for presenting to partner. Build as styled HTML (SERFIX brand: orange #F26419, white cards, ring gauges, score pills — mimic existing report look) rendered to PDF via headless Chromium (puppeteer-core recipe from memory) or reportlab fallback. Output to scratchpad, hand user the path.

Pages:
1. **Overview** — roadmap graphic: Foundation (TS/CS scores) → Tier 1 (passive link graph) → Tier 1.5/2 (own crawler) → Tier 3 (kept-open vision); one-line value per stage.
2. **Foundation (Phases 1/1.5/2)** — mock report section: TS + CS ring gauges beside DA/PA/Spam, referring-domains table with score pills (realistic sample domains: wikipedia.org 92, forbes.com 88, niche blog 41, spammy directory 8), topical-relevance chips. Caption: what client learns.
3. **Tier 1** — mock "New links discovered" feed + link gained/lost timeline + competitor-overlap insight card, all from passively collected edges. Insight captions.
4. **Tier 1.5/2** — mock fresh-link alert email/panel, per-page backlink drill-down, link-prospecting list ("links to 3 competitors, not you").
5. **Tier 3 (vision)** — any-domain instant lookup (site-explorer parity) mock + honest "kept open, not scheduled" note + CC WAT hybrid shortcut.
6. **Costing** — table per tier: dev effort, API cost, infra cost. Infra on **Hetzner auction** prices (verify live at hetzner.com/sb before finalizing; typical: 64GB/2×512GB NVMe ≈ €30–38/mo, 128GB/2×1TB NVMe ≈ €45–60/mo): Foundation €0 new; Tier 1 €0 new; Tier 1.5 €0–15/mo (existing fleet, maybe +1 cloud worker); Tier 2 one auction box (ClickHouse+crawl aux, 64GB NVMe class) ≈ €35–45/mo; Tier 3 hybrid CC-WAT batch = temp cloud instances per quarter (~€10–20/quarter), full parity marked rejected.
No Majestic trademark words in mock UI; scores labeled Trust Score / Citation Score.

## Approved scope note
User decision 2026-07-16: proceed with Foundation (Phases 1, 1.5, 2) + **Tier 1** edge collection. Tier 1.5/2 deferred until Tier 1 proves value; Tier 3 not scheduled (schema keeps door open). Partner PDF is Deliverable 0 before implementation.

## Context

We want Majestic-style Trust Flow / Citation Flow metrics without Majestic's web-scale link index. Analysis of options:

| Approach | Speed | Cost | Accuracy vs Majestic | Verdict |
|---|---|---|---|---|
| **Deterministic composite from data we already fetch** (DataForSEO summary/referring-domain ranks + Open PageRank) | Instant (assembly-time math) | $0 new | CS: high (OPR *is* PageRank on Common Crawl, same family as CF). TS: directional — approximates the trust *signal* (spam, one-hop authority, TLD, diversity), not seed-propagation numbers | **Phase 1 — do this** |
| **Scrape referring pages + LLM topic match** (user's idea A) | ~seconds per domain, bounded | small LLM tokens | Adds the *Topical* TF dimension Majestic has and pure math can't | **Phase 2 — top ~15 ref domains only** |
| **Keyword-server discovery + match** (user's idea B) | Minutes/request, node concurrency=1 | starves paid keyword features | poor fit per-backlink | **Rejected** |
| Buy Majestic/DataForSEO per-link rank at scale | fast | paid per call | exact | Rejected — cost, and we just removed paid KE endpoints |

How near we get: Citation Score ≈ genuinely close (PageRank-family, same inputs). Trust Score ≈ same *ordering/tiers* as TF (trusted sites high, spam low) but not Majestic's numbers — no crawl graph to run TrustRank on. Topical layer gives Topical-TF-like relevance labels.

**Naming/trademark:** UI says "Trust Score" / "Citation Score" — never "Trust Flow"/"Citation Flow"/"Majestic".

## Phase 1 — deterministic scores (zero new API cost)

### 1. New pure service `app/Services/Reports/AuthorityScoreCalculator.php`
Stateless, no I/O. API: `scores(array $payload): array`, `rowScore(?float $opr, ?int $rank): ?int`, `augment(array $payload): array` (idempotent via `scores.version`), `const VERSION = 1`.

**Citation Score (0–100)** — weights renormalize when component missing; null if all missing:
- `opr_pts = opr_score × 10` (from payload gauges/OPR)
- `rank_pts = dataforseo_rank / 10`
- `rd_pts = min(100, 100·log10(1 + referring_domains) / 6)`
- `CS = round(0.50·opr_pts + 0.30·rank_pts + 0.20·rd_pts)`
- Per-row (referring domains / backlinks rows already carry `rank` + `opr_score`): `cs = round(0.6·opr·10 + 0.4·rank/10)`

**Trust Score (0–100)** — full reports only; weighted mean, renormalized; null if <2 components present:

| Component | Formula | Weight |
|---|---|---|
| Inverse spam | `100 − gauges.spam_score` | 0.25 |
| Ref-domain authority share (one-hop TrustRank-lite) | share of top ref domains with `rank ≥ 300 or opr ≥ 4.0`; `min(100, share·250)` | 0.25 |
| Dofollow ratio | `min(100, dofollow_pct·1.25)` | 0.15 |
| IP/subnet diversity (link-farm signal) | `min(1, avg(ips/rd, subnets/rd)/0.8)·100` | 0.15 |
| Trusted TLD share | gov/edu/mil share from `profile_details.tlds`; `min(100, share·1000)` | 0.10 |
| Seed-list matches | curated ~120-domain list in new `config/trusted_seed_domains.php` (wikipedia, major news, gov/edu portals) vs top referring domains; `min(100, matches·20)` | 0.10 |

Plausibility ceiling (mirrors Majestic where TF rarely ≫ CF): `TS = min(TS_raw, CS + 10)`; clamp 0–100.

### 2. Wiring — compute-on-write + backfill-on-read, NO payload schema bump
Schema bump would trigger paid DataForSEO regeneration in `ReportViewController::resolve()` (~line 140); TS/CS need zero new provider data:
- **Write:** call `augment()` at end of `ClientReportService::assemble()` (app/Services/Reports/ClientReportService.php:60) and `assemblePartial()` (partial snapshots → CS from OPR only, TS null).
- **Read backfill:** call `augment()` in `ClientReportService::withTraffic()` — single choke point used by `ReportViewController`, `BacklinksController`, `PublicReportController`, `ClientReportExportController`. Existing cached snapshots gain scores instantly, no migration, no write-back.
- Payload additions: `payload['scores'] = {citation, trust, version}` + `cs` on each referring-domain/backlink row. All view reads `?? null` guarded.

### 3. UI
- [backlinks.blade.php](resources/views/backlinks.blade.php): two score cards (Trust Score "Link quality", Citation Score "Link popularity", `NN/100`) above headline-stats grid (~line 64), brand orange accent; swap per-row "Authority" column to `cs` in both tables (~lines 156–216).
- [web-body.blade.php](resources/views/reports/web-body.blade.php) (~lines 51–63) + PDF twin `reports/_body.blade.php`: append Trust/Citation rings to gauge `@foreach` (grid 4→6 cols); `charts/ring.blade.php` already renders null as "—".

### 3b. Presentation standard (all surfaces)
- **Reusable components**: `<x-score-gauge>` (0–100 ring, band colors: 0–29 weak / 30–59 moderate / 60–100 strong) + score-pill partial for table rows. Single color-scale helper. Web + PDF share partials (existing pattern).
- **Surfaces**: client report web/PDF/public (gauges beside DA/PA/Spam + row pills + profile verdict line), /backlinks (cards + pills), /competitors (TS/CS columns), websites-list chip (cached card payload pattern), homepage funnel report (free lead magnet), later WP plugin.
- **Methodology page** (public marketing route, e.g. `/trust-score`): plain-language formula explanation, band legend, score version changelog. Tooltips on every score link to it.
- **Rules**: deterministic scores only (no LLM in TS number); missing data renders "—" never 0; `scores.version` bumps announced on methodology page; trend arrows only when ≥2 history snapshots; client-facing copy neutral (no "estimated/internal"); no indigo.

### 4. i18n
`lang/en.json` + `lang/ar.json` keys for the backlinks-page strings (follow existing "Topical Authority" key pattern). Report web/PDF bodies stay hardcoded-English per existing convention.

### 5. Tests (DB safety: `php artisan config:clear` + verify sqlite `:memory:` before ANY test run)
- `tests/Unit/AuthorityScoreCalculatorTest.php` — pure fixtures, no DB: exact expected values, ceiling, renormalization on missing components, null cases, per-row scores, idempotent augment, subdomain seed matching.
- Feature: extend `BacklinksTest` — seed snapshot payload WITHOUT `scores`, assert cards render (proves backfill-on-read); extend `ClientReportTest` — `assemble()` emits `scores`.

### 6. Docs
`infra/reports/client-report.md`: formula table, backfill-on-read rationale (no schema bump), trademark naming note. Changelog line in `infra/main.md`.

## Phase 1.5 — Common Crawl web-graph local rank table (zero-latency, zero-quota)

Common Crawl publishes quarterly **domain-level web graph ranks** (free download, ~121M domains): PageRank rank + **harmonic centrality** rank per domain — the closest public analogue to CF/TF (harmonic centrality is distance-based and hard to game; spam farms rank poorly). Latest: `cc-main-2026-apr-may-jun` at data.commoncrawl.org/projects/hyperlinkgraph/.

1. **Artisan command `ebq:import-cc-webgraph`** — downloads the domain-ranks file, writes a **read-only SQLite sidecar** at `storage/app/cc-domain-ranks.sqlite` (domain PK reversed-notation as shipped, harmonic_rank, pagerank_rank). SQLite, NOT MariaDB — ~6–8 GB would blow the 2G buffer pool; disk is cheap, lookups are point PKs. Quarterly refresh (scheduled or manual); build to temp file + atomic rename.
2. **New lookup service `app/Services/Reports/CcDomainRanks.php`** — `scoreFor(domain): ?array{citation_pct, trust_pct}` converting ranks to 0–100 percentiles: `pct = 100·(1 − log10(rank)/log10(N))`. Missing domain → null (treated as low, weight renormalizes). Handles registrable-domain fallback like `OpenPageRankClient::registrable()`.
3. **Wiring:** during `assemble()`/backfill, look up main domain + referring-domain rows; stash values INTO payload (`cc_citation`, `cc_trust` per row) so `AuthorityScoreCalculator` stays pure and old snapshots stay renderable.
4. **Formula upgrade:** CS gains component `0.30 cc_citation` (weights: 0.35 OPR, 0.30 cc, 0.20 rank, 0.15 rd_pts). TS: harmonic-centrality percentile replaces half the "ref-domain authority share" weight (0.15 harmonic + 0.10 one-hop share) — genuine graph-distance trust signal, no per-domain API call, no OPR quota pressure.
5. Graceful before import: sidecar absent → lookups null → weights renormalize → Phase 1 formulas unchanged. Ship Phase 1 first; 1.5 is pure upgrade.

## Data asset design — accumulate, don't discard

Make per-report data compound over time:

1. **`domain_metrics` table (MySQL)** — one row per domain ever touched (report targets, referring domains, competitors; thousands of rows). Upserted on every report build/enrichment: latest CC ranks, TS/CS, OPR score+history, spam/dofollow signals, seed flag, **LLM topic classification cached permanently** (paid once per domain, reused forever — Phase 2 cost decays toward zero), first/last seen. Read by future features: prospecting scorer, competitor discovery, keyword-gap quality, WP plugin.
2. **`domain_metric_history` table** — append-only, generic: `(domain_id, source [cc_harmonic|cc_pagerank|opr|dfs_rank], value, captured_at)`, upsert-idempotent by domain+source+period. Unlocks trend charts / rank-movement arrows — Majestic-style history, grows automatically (~100 MB/yr even at 100k domains).
2b. **Periodic refresh, decoupled from client lifecycle** — domains NEVER deleted on client churn; they drop to free-feed tier and history keeps accumulating:
   - Tier A (active client targets): paid DataForSEO via existing `RefreshPaidReports`/TTL flow, unchanged.
   - Tier B (referring domains, competitors, churned targets): free feeds only — quarterly CC snapshot (`ebq:import-cc-webgraph --snapshot-history`, all known domains, unlimited) + monthly `ebq:refresh-domain-metrics` OPR bulk sweep (quota governor: staleness × importance, cap ~28k of 30k/mo free quota).
   - `domain_metrics.tier` + per-source `last_refreshed_at`; commands scheduled in console kernel, idempotent, Horizon queue.
3. SQLite sidecar stays dumb overwrite-on-refresh bulk index; the MySQL tables are the curated asset.
4. **Long-term option (not in current phases):** own always-expanding link graph. Tiers: (T1) edges from pages already fetched — ~2–4 GB/yr, MySQL, use int domain-ids from day 1 so schema ports to ClickHouse; **infra-capable today, ship with Phase 1–2**. (T1.5 middle step) targeted crawl of domains already in `domain_metrics` at ~100–200k pages/day on existing Hetzner fleet autoscaler — ~20 GB/yr, still MySQL, no new hardware; needs Redis URL frontier + per-host politeness build. (T2) broad crawler ~1M pages/day — fetch/bandwidth/CPU fine via fleet, but requires dedicated ClickHouse node (~16 GB RAM/1 TB, €30–60/mo; web box is 7.6 GB RAM co-hosting MariaDB+Postal+Jitsi — cannot host it); defer until T1.5 proves demand. (T3) web-scale parity — petabytes/crawl-fleet; not planned, but path stays open via day-1 schema rules: BIGINT ids + `domains`/`urls` dictionary tables (edges reference ids never strings; nullable url ids make page-level additive), append-only edges with `first_seen`/`last_seen` + immutable history rows (future engine migration = pure copy), `source` tag per edge row (`own_crawl|cc_wat|enrichment`), versioned score formulas. Never store aggregates-only, never delete on churn. Shortcut: quarterly stream-extract page-level edges from Common Crawl WAT files ONLY for domains in `domain_metrics` (~20 TB streamed, GBs stored) → page-level backlink data for exactly the domains we serve. TrustRank/PageRank compute on domain graph is RAM-cheap (~2–4 GB even at CC scale).

## Phase 2 — Topical Trust (LLM, separate follow-up)

Latency note: this runs in a queued background job — per-domain fetch seconds do NOT block report render; section appears when ready. Fetches parallelizable (Http::pool) → ~15 domains in <10s total.

Config-gated `EnrichTopicalTrustJob` dispatched from `GenerateWebsiteReport` after `status=ready` (~line 184):
1. `CrawlFetcher` fetches homepage title/meta/snippet for top ~15 referring domains (free, SSRF-guarded — same pattern as `ReportEnrichmentService::fetchPageText()`). Optional later: Cloudflare Radar free API domain-category lookup as cheaper pre-filter (needs token + rate-limit verification), LLM stays fallback.
2. ONE `completeJson` LLM call (LlmClientFactory — Mistral/DeepSeek): classify each domain into fixed ~12-topic taxonomy + `relevant_to_target` bool.
3. Patch `payload['topical_trust']` via `ReportEnrichmentService`'s targeted-section-patch pattern (~line 532).
4. Render as "Topical relevance" breakdown (topic chips + relevant-share bar) on backlinks page + report. Fully guarded — job failure = section simply absent. Does NOT feed the TS number (TS stays deterministic/reproducible).

## Phase 3 — Tier 1 link-graph collection (approved)

Passive edge harvesting from fetches that already happen (client crawls via `PageCrawlProcessor`/HtmlAuditor outlinks, enrichment fetches, Phase 2 topical fetches):
1. Migrations: `domains` (BIGINT id, name unique), `urls` (BIGINT id, domain_id, path — ready, may stay sparse), `link_edges` (from_domain_id, to_domain_id, nullable url ids, dofollow, anchor_class, source enum `own_crawl|cc_wat|enrichment`, first_seen, last_seen; unique on from+to+source), append-only, never deleted on churn.
2. `app/Services/LinkGraph/EdgeRecorder.php` — upsert helper (batch, updates last_seen); called post-parse in the crawl processor + enrichment fetch paths. Queue-friendly, failure never breaks parent job.
3. Growth ~2–4 GB/yr MySQL; no new infra. Feeds future features (fresh-link feed, prospecting) — those UIs are Tier-1-follow-up, not this plan.

## Verification
1. Unit + feature test filters (after sqlite guard check).
2. Open `/backlinks` for a domain with an existing cached snapshot → scores appear, confirm NO `GenerateWebsiteReport` dispatch (no cost).
3. Public report + PDF: 6 gauges render; partial-report domain shows CS with TS "—".
4. Sanity band: check a known-big domain snapshot (TS/CS both high) vs small/spammy domain (low TS).
5. Phase 2: run enrichment job on one domain, verify topical section appears, then `sudo systemctl restart php8.3-fpm` before browser QA (opcache).

## Critical files
- `app/Services/Reports/AuthorityScoreCalculator.php` (new), `config/trusted_seed_domains.php` (new)
- `app/Services/Reports/CcDomainRanks.php` (new), `app/Console/Commands/ImportCcWebGraph.php` (new, Phase 1.5)
- `app/Services/Reports/ClientReportService.php`, `app/Services/Reports/ReportEnrichmentService.php`, `app/Jobs/GenerateWebsiteReport.php`
- `resources/views/backlinks.blade.php`, `resources/views/reports/web-body.blade.php`, `reports/_body.blade.php`
- `lang/en.json`, `lang/ar.json`, `infra/reports/client-report.md`
