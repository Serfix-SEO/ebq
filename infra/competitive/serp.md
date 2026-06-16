# SERP subsystem

Everything that touches the live Google SERP goes through one client (Serper) and, for the
competitive features, one shared cross-client cache. Rankings are public facts, so they're fetched
once and reused across tenants until a short TTL lapses.

## Key components

| Component | File | Role |
|---|---|---|
| `SerperSearchClient` | `app/Services/SerperSearchClient.php` | The only Serper HTTP wrapper. Multi-endpoint, meter-gated, usage-logged. |
| `SerpCache` | `app/Services/Competitive/SerpCache.php` | Read-through cross-client cache over `serp_cache`; normalizes the payload. |
| `SerpCacheEntry` | `app/Models/SerpCacheEntry.php` | `serp_cache` row — `(query_hash, gl)`, `payload`, `expires_at`. |
| `OpportunityScoreService` | `app/Services/Competitive/OpportunityScoreService.php` | 0–100 explainable keyword opportunity score from SERP + DA + volume + position. |
| `SerpFeatureTrackerService` | `app/Services/SerpFeatureTrackerService.php` | Per-keyword SERP-feature presence timeline + ownership, from rank-tracking snapshots. |
| `SerpFeatureRiskService` | `app/Services/SerpFeatureRiskService.php` | Flags keywords where a click-absorbing feature appears and we don't own #1; detects lost features. |
| `NetworkInsightService` | `app/Services/NetworkInsightService.php` | Anonymized cross-network "what #1–3 pages share" for a keyword. |
| `CrossSiteBenchmarkService` | `app/Services/CrossSiteBenchmarkService.php` | "Your site vs peers" GSC aggregates across the network. |

## Serper client

`SerperSearchClient::query($params)` (`SerperSearchClient.php:75`) — one method, many SERP types.

- Endpoints (`ENDPOINTS` :16): organic/search/images/videos/news/shopping/maps/places/scholar/autocomplete.
- Validates + clamps params: `num` 1–100, `page` 1–10, `gl` 2-letter ISO, `hl` lang pattern,
  `device` desktop/mobile, plus `location`/`autocorrect`/`safe`/`tbs`.
- `search()` :36 is the legacy thin wrapper kept for page-audit callers.
- 30s timeout, `X-API-KEY` header. Returns **null on any failure** (HTTP error, bad JSON,
  transport) — callers log and skip rather than crash.
- **Billing**: resolves the website owner (`__website_id`/`__owner_user_id` private params),
  `UsageMeter::assertCanSpend(..., 'serp_api', 1)` before the call, then `ClientActivityLogger`
  logs `units_consumed = 1` with query/type/gl/device meta and the calling `__source`.

## The shared SERP cache

`SerpCache::organic($keyword, $gl, $websiteId?, $ownerUserId?, $source)` (`SerpCache.php:37`):

1. Hash `(keyword, gl)` → look up `serp_cache`. **Fresh hit returns stored payload — no Serper
   call, no quota spend.** A keyword one client checks is free for every other tenant. (:46)
2. Miss/stale → live `SerperSearchClient::query` (organic, num=10).
3. Live fetch failed → **serve stale `payload` rather than null** if a row exists. (:62)
4. Success → `normalize()` then `updateOrCreate` with `expires_at = now + serp_cache_days` (7).

`normalize()` (:91) keeps only what consumers read: `organic[]` (top-10 `position`/`link`/`domain`)
plus **present-only feature markers** (`answerBox`, `knowledgeGraph`, `ads`, `shopping`,
`peopleAlsoAsk` reduced to a count array) so `isset`/`!empty` checks stay valid without storing
bulk text. `QuotaExceededException` propagates from the live call so callers can show the plan-cap
CTA — a cache hit never reaches that path.

TTL is **7 days** (`COMPETITIVE_SERP_CACHE_DAYS`), deliberately shorter than the 30-day keyword
volume cache because rankings shift faster than volume.

## Opportunity scoring

`OpportunityScoreService` (`OpportunityScoreService.php`) — transparent 0–100, no new vendor.

- `score($avgTop10Da, $serpFeatures, $volume, $ourPosition)` :35 is pure:
  - **difficulty** = `0.6·daNorm + 0.4·crowding`; crowding weights answerBox/KG/ads/shopping/PAA.
  - **ease** = `1 − difficulty`.
  - **worth** = `log10(volume)/5` (log-scaled to tame bucket coarseness).
  - **proximity** = 1.0 in striking distance (pos 4–15), 0.25 unknown, 0.0 otherwise.
  - opportunity = `100 · (0.55·ease + 0.30·worth + 0.15·proximity)`.
  - Returns a **component breakdown** so the UI shows "Top-10 DA 62 · 3 SERP features · 12k vol".
- `lightScore()` :92 — no SERP call (neutral DA 0.5), used for bulk gap rows.
- `liveScore()` / `scoreFromSerp()` :104/:131 — fetch (cached) SERP, average DA of top-10 domains
  (`queueRefresh` warms missing DA in background), combine. Shared with the gap verifier so **one
  SERP call powers both ranking verification and scoring**.

## SERP-feature tracking & risk

These read **rank-tracking snapshots** (`RankTrackingSnapshot.serp_features`), not the live SERP —
pure DB, no API spend. The daily rank-tracking cron accumulates the data.

- `SerpFeatureTrackerService::forWebsite()` (`SerpFeatureTrackerService.php:64`) — per active
  keyword: features today, **features we own** (our domain appears inside the feature block —
  `blockContainsDomain()` walks the block recursively, :189), and a per-date timeline. Plus a
  site summary (with answer-box / PAA / image-pack counts). Feature keys at :34. MOAT: timeline
  depth compounds with site age — a fresh competitor tool can't reproduce 90 days of volatility.
- `SerpFeatureRiskService::riskFor()` (`SerpFeatureRiskService.php:28`) — flags `at_risk` when a
  click-absorbing feature (`RISK_FEATURES`: answerBox/knowledgeGraph/topStories/shopping, :15) is
  present **and** we don't hold position ≤1; `lost_feature` when the prior snapshot had a feature
  the latest lost. `riskMapForWebsite()` :71 builds the whole site's map in one query (PHP-side
  two-most-recent ranking per keyword).

## Cross-network aggregates (privacy-gated)

- `NetworkInsightService::forKeyword()` — for a keyword across the network: word-count
  percentiles, schema-type / SERP-feature shares, typical headings/links/images, common entities.
  Cohort = distinct websites tracking the keyword; **min 5 sites or returns `insufficient_cohort`**.
  No domains ever returned; cached 24h.
- `CrossSiteBenchmarkService::forWebsite()` — "your avg position/CTR vs the global (and per-country)
  cohort" + your percentile. Per-site GSC aggregates → cross-site p50/p90; **min cohort 5**,
  sites with <100 (global) / <50 (country) rows excluded; cached 24h. Single-site competitors
  physically can't match this — network-effect MOAT.

## Gotchas

- **`serp_cache` ignores `websiteId` for keying** — it's `(query_hash, gl)` only, so it is
  genuinely cross-tenant. A stale ranking can persist up to 7 days; `SerpCache` serves stale on
  live-fetch failure (better than nothing for discovery/scoring).
- **Feature markers are present-only** in the cached payload — consumers must use `isset`/`!empty`,
  not read values. `scoreFromSerp` and discovery already do.
- **`serp_features` snapshots store bool OR array** per feature; the tracker treats both as
  "present" (`extractFeatures` :144). Ownership detection only works when the block is an array.
- Cross-network/benchmark services **fail closed** below the 5-site cohort — don't lower the
  threshold without re-checking re-identification risk.
- Serper `num` is requested as 10 in `SerpCache` regardless of caller intent; `normalize` only
  keeps the top-10 anyway.

## Key files

- `app/Services/SerperSearchClient.php`, `app/Services/Competitive/SerpCache.php`, `app/Models/SerpCacheEntry.php`
- `app/Services/Competitive/OpportunityScoreService.php`
- `app/Services/{SerpFeatureTrackerService,SerpFeatureRiskService,NetworkInsightService,CrossSiteBenchmarkService}.php`
- Config — `config/services.php` `serper` (`:90`) + `competitive.serp_cache_days` (`:177`)
