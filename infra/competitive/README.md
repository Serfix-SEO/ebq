# Competitive, Backlinks & SERP subsystem

Three intertwined feature families that all share **one principle: a SERP / backlink /
keyword fact is client-agnostic, so it is fetched once and reused across every client**
until a TTL lapses. External vendor spend (Serper, Keywords Everywhere) is gated behind
freshness checks, per-domain caches, hard caps, and per-owner usage metering.

## Read in this order

| Doc | What it covers |
|---|---|
| **README.md** (this) | The map, the competitive-discovery + keyword-gap flow, the shared data model, vendor config, gotchas. |
| [backlinks.md](./backlinks.md) | Own + competitor backlinks: KE client, the unified freshness gate, audit, prospecting/outreach, impact attribution. |
| [serp.md](./serp.md) | Serper client, the cross-client `serp_cache`, opportunity scoring, SERP-feature tracking + risk, cross-network insight. |

## One paragraph

Every audit that surfaces SERP neighbours feeds two caches: `serp_cache` (organic top-10 +
feature markers, 7-day TTL) and `competitor_backlinks` (top-N referring pages per competitor
domain, 30-day TTL). **Competitor discovery** samples a site's top GSC queries against the live
SERP and tallies recurring domains. **Keyword gap analysis** diffs our keyword footprint vs
competitors' into Missing/Weak/Strength/Shared buckets, optionally verifying the Missing bucket
against the live SERP. **Opportunity scoring** combines top-10 domain authority + SERP-feature
crowding + volume + our position into an explainable 0–100. **Backlink prospecting** mines the
network's `competitor_backlinks` for referring domains that link to competitors but not us, and
(Pro) drafts outreach via the LLM. The MOAT throughout is network data gravity: every EBQ user's
audits enrich the shared caches the next user reads for free.

---

## Competitive discovery

`app/Services/Competitive/CompetitorDiscoveryService.php` — finds a site's organic competitors.

| Step | Where | Notes |
|---|---|---|
| Stale check | `isStale()` `CompetitorDiscoveryService.php:73` | No completed run within `discovery_refresh_days` (14) ⇒ stale. |
| Queue guard | `queueRunIfStale()` :92 | One in-flight run per website (`queued`/`running`); never stacks. |
| Seed keywords | `gscSeedKeywords()` :319 | Top GSC queries (28d), **preferring positions 4–30** (where competitors are most visible), padded by impressions. Falls back to manual seeds with no GSC. |
| Fan-out | `run()` :155, job `RunCompetitorDiscovery` | Up to `discovery_max_keywords` (25) cached SERP calls via `SerpCache::organic`. |
| Tally | :199 | Count a domain **once per SERP at its best position**; skip own domain + `GIANT_DOMAINS` (wikipedia/youtube/reddit/etc, :36). |
| Score | `score()` :272 | `100 × (0.65·frequency + 0.35·positionScore)`; frequency = appearances/sampled, positionScore from avg position 1→10. **DA is informational, not scored.** |
| Persist + prune | `persist()` :236 | `updateOrCreate` per domain keyed `(website_id, competitor_domain)`; rows from older `run_id` deleted. |
| DA enrich | `enrichDomainAuthority()` :288 | Background `queueRefresh` for top-10, then copies cached DA onto rows. |

Dispatch is interactive-queue; the job is `tries=1` (a partial paid result beats re-running the
whole fan-out) and `uniqueFor=1800` per `run_id`. UI: `app/Livewire/Competitive/CompetitorDiscovery.php`
(poll loop, "track top" → rank tracking, CSV export).

## Keyword gap analysis

`app/Services/Competitive/KeywordGapService.php` — diffs our keyword footprint vs 1–N competitors.

- **Async collection.** `start()` :47 dispatches **website-mode** keyword discovery (no Google
  needed) for our URL + each competitor via `KeywordFinderPool`, then sits in `collecting`. A
  poller calls `maybeAggregate()` :122 which **atomically claims** the `collecting → completed`
  transition so concurrent polls aggregate exactly once.
- **Bucketing.** `bucketFor()` :568 — Missing (we don't have it), or with GSC positions
  Weak (we rank >10) / Strength (≤10); without GSC the "we have it" case is Shared.
- **GSC-aware.** `gscPositions()` :618 pulls avg position per query (90d). `reprocessWithGsc()`
  :330 re-buckets stored rows for free when GSC connects later (no new discovery spend).
- **Live verification.** `startVerification()`/`verify()` :377/:414 — for the Missing bucket
  (optionally Shared/Weak), one **cached** SERP call per keyword captures real competitor + our
  positions, re-buckets from reality, and re-scores from the same response. Quota-safe: a
  `QuotaExceededException` stops the batch, persists progress, records `verify_error`.
- Caps: `gap_max_competitors` (3), `gap_row_cap` (1000, top-by-volume), `gap_verify_max` (25),
  `gap_collect_timeout_minutes` (5). Repeat requests served from `latestFresh()` :547.

`CompetitiveReprocessService` (+ `ReprocessCompetitiveData` job, debounced `uniqueFor=120`) runs
both halves after GSC connects: free gap re-bucketing + a forced (still in-flight-guarded)
discovery re-run now seeded by real GSC queries.

---

## Shared data model

| Table | Model | Keyed by | TTL/freshness | Written by |
|---|---|---|---|---|
| `serp_cache` | `SerpCacheEntry` | `(query_hash, gl)` | `expires_at`, 7d (`serp_cache_days`) | `SerpCache::organic` |
| `competitor_backlinks` | `CompetitorBacklink` | `(competitor_domain, referring_page_hash)` | `fetched_at`/`expires_at`, 30d | `CompetitorBacklinkService::refresh` |
| `backlinks` | `Backlink` | `(website_id, referring_page_url, target_page_url)` | `created_at` vs 30d gate | `OwnBacklinkSyncService`, manual UI |
| `outreach_prospects` | `OutreachProspect` | `(website_id, referring_domain)` | status workflow (persistent) | `BacklinkProspectingService` |
| `competitor_discovery_runs` | `CompetitorDiscoveryRun` | `run_id` | `completed_at` vs 14d | `CompetitorDiscoveryService` |
| `discovered_competitors` | `DiscoveredCompetitor` | `(website_id, competitor_domain)` | per-`run_id`, pruned | `CompetitorDiscoveryService::persist` |
| `keyword_gap_analyses` / `keyword_gap_rows` | `KeywordGapAnalysis` / `KeywordGapRow` | analysis id | `expires_at`, 30d | `KeywordGapService` |

Domain normalization is centralized: `CompetitorBacklink::extractDomain()` (strip
scheme/www/port/path/query → bare host) and `BacklinkFreshnessGate::normalizeDomain()` use the
same rule so casing/trailing-slash variants can't bypass a cache or gate.

---

## External APIs & config (non-secret)

| Provider | Used for | Client | Key env (secret) |
|---|---|---|---|
| **Serper** (google.serper.dev) | Live organic SERP (discovery, gap verify, opportunity score, page-audit benchmark) | `SerperSearchClient` | `SERPER_API_KEY` |
| **Keywords Everywhere** | Backlinks-by-domain (own + competitor) + keyword volume | `KeywordsEverywhereBacklinkClient` | `KEYWORDS_EVERYWHERE_API_KEY` |
| Self-hosted Keyword Finder | Website-mode keyword discovery for gap analysis | `KeywordFinderPool` | (DB-stored per server) |

Cost-control knobs live under `config/services.php` `competitive`, `competitor_backlinks`,
`keywords_everywhere` (`config/services.php:90`–`178`):

- `COMPETITIVE_DISCOVERY_MAX_KEYWORDS=25`, `COMPETITIVE_DISCOVERY_REFRESH_DAYS=14`
- `COMPETITIVE_GAP_MAX_COMPETITORS=3`, `COMPETITIVE_GAP_ROW_CAP=1000`, `COMPETITIVE_GAP_VERIFY_MAX=25`
- `COMPETITIVE_SERP_CACHE_DAYS=7`, `COMPETITIVE_GAP_VERIFY_INCLUDE_SHARED=false`
- `COMPETITOR_BACKLINKS_LIMIT=50`, `COMPETITOR_BACKLINKS_FRESH_DAYS=30`
- `KE_BACKLINKS_TTL_DAYS=30` (the universal backlink freshness window)
- `SERPER_COST_PER_CALL_USD`, `KEYWORDS_EVERYWHERE_COST_PER_KEYWORD_USD` — admin usage estimates only.

**Billing attribution.** Both vendor clients resolve the website **owner** (`website_user` role
owner → fallback `websites.user_id`), call `UsageMeter::assertCanSpend` before spending, and log
to `ClientActivityLogger` with `units_consumed` so the admin usage page charges the right client.

---

## Gotchas / known issues

- **`serp_cache` is global across tenants.** A keyword one client checks is free for every other
  until the 7-day TTL lapses. Intentional (it's a public fact) — but it means a stale ranking can
  persist up to a week. `SerpCache::organic` serves stale on live-fetch failure rather than null.
- **`CrossSiteBenchmarkService` / `NetworkInsightService` enforce a min cohort of 5 sites** and
  return aggregates only (no domains). Below threshold they report `cohort_too_small` /
  `insufficient_cohort` to prevent re-identifying a single tenant.
- **KE backlink credits aren't refunded** if it returns fewer rows than asked; the client
  pre-flights the worst-case spend (`num` rows) against the meter.
- **DA in discovery is best-effort** — populated only if a background `FetchCompetitorBacklinks`
  has already cached it; first-run rows often have null DA. It never affects the discovery score.
- **0-result KE calls** are marked fresh via a cache sentinel (see backlinks.md) so small domains
  aren't re-billed every page load.
- `CompetitorBacklink::extractDomain` is the single choke point for domain shape — changing it
  re-keys every cache. Don't fork it.

## Key files

- Discovery — `app/Services/Competitive/CompetitorDiscoveryService.php`, `app/Jobs/RunCompetitorDiscovery.php`,
  models `app/Models/{CompetitorDiscoveryRun,DiscoveredCompetitor}.php`, UI `app/Livewire/Competitive/CompetitorDiscovery.php`
- Gap — `app/Services/Competitive/KeywordGapService.php`, `CompetitiveReprocessService.php`,
  `app/Jobs/ReprocessCompetitiveData.php`, UI `app/Livewire/Competitive/KeywordGapAnalysis.php`
- Shared SERP cache — `app/Services/Competitive/SerpCache.php`, `app/Models/SerpCacheEntry.php`
- Config — `config/services.php:90-178`
