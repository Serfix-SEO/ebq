# ReportDataService — the insight engine

`app/Services/ReportDataService.php` (~1520 lines) is the single source of every
SEO insight number in the app: the dashboard cards, the Reports tab, the Action
Queue's GSC half, and the emailed report. It reads the synced sources and applies
hand-tuned heuristics. Nothing here writes data; every public insight method is
cached 24h, version-keyed.

## Public methods

| Method | `file:line` | Returns / heuristic |
|---|---|---|
| `lastSafeReportDate(id, ?minLag, ?tz)` | :38 | Newest GSC date that is both lag-final and present. Null → caller skips send. |
| `lastSafeAnalyticsDate(id, ?tz)` | :58 | Latest GA4 date present (no lag floor — GA finalizes in hours). |
| `reportReadiness(Website, ?tz)` | :80 | `{ga, gsc, any, date}` — per-source readiness; degraded sites still report. |
| `siteHealthSection(id)` | :103 | Technical-SEO block from `CrawlReportService::summary`+`actionGroups`. |
| `generate(id, start, end, ?country)` | :122 | Master report payload (period + sources + analytics + GSC + backlinks + indexing + ppc_equivalent). **Not cached** — composes the cached pieces fresh. |
| `cannibalizationReport(...)` | :733 | Queries where ≥2 of the site's own pages split clicks. |
| `strikingDistance(...)` | :836 | Queries ranking pos 5–20 with impressions — top ROI list. |
| `contentDecay(id, limit, ?country)` | :1004 | `{pages, has_yoy_history}` — pages with sustained click decline. |
| `indexingFailsWithTraffic(id, windowDays, ...)` | :1203 | Pages whose Google verdict ≠ PASS but still earn impressions. |
| `quickWins(id, limit)` | :1307 | Volume + low-competition keywords the site under-ranks. |
| `insightCounts(id, ?country)` | :1280 | Counts for the dashboard cards (calls the cached methods). |
| `topCountriesTrend(id, limit)` | :1431 | Top-N countries, last 30d vs prior 30d delta. |

## Insight heuristics & magic thresholds

**Cannibalization** (:765) — default 28-day window. Candidate query needs ≥2
distinct pages, `total_impressions ≥ 100`, and the primary page's click-share
**< 90%** (≥90% = one URL clearly dominates, no real split), plus ≥1 competing
page after the primary. Sorted by impressions desc.

**Striking distance** (:860) — 28-day window, grouped by (query, page).
Inclusion: `impressions ≥ 200 AND impression-weighted position in [5, 20]`.
Legacy score `(impr/100) + (20 − pos) − (ctr*0.6)`, but the final sort puts rows
with a real `$ upside_value` first (by $ desc), un-valued rows fall back to score.

**Content decay** (:1025) — anchored to yesterday. current = last 28d, previous =
the 28d before, YoY = same 28d one year earlier (`hasYoy` only if GSC history
reaches back that far). Per page: drop if `curImpr < 100` or click-change is null
or `> −15%` (must be **≥15% click decline** while still earning impressions).
Left-joins `page_indexing_status` so decay vs de-indexing is distinguishable.
`tagDecayReasons` (:1134) takes each page's top-3 impression queries; if ≥2 have a
known KE 12-month trend and ≥2 are `falling` → `decay_reason = 'market_decline'`,
else `'recoverable'`.

**Indexing fails with traffic** (:1225) — default 14-day window. Cohort =
`page_indexing_status` where `google_verdict` is non-null and **≠ 'PASS'**, kept
only if `impressions > 0` in the window. Sorted by recent impressions desc. This
is the **critical** tier of the Action Queue.

**Quick wins** (:1325) — `$minVolume = 500`, `$maxCompetition = 0.4`. Candidates
from `keyword_metrics` (`country='global'`, vol ≥ 500, competition ≤ 0.4 or null),
ordered by volume, capped 2000. **Tenant gate**: keyword must have GSC presence
for *this* site over a 90-day window (else skipped — see gotchas). `best_position
= MIN(position)` over 90d; if `bestPos ≤ 10` it's already winning → skip. Upside =
`KeywordValueCalculator::upsideValue(vol, bestPos, target=3, cpc)`; skip if ≤ 0.

**PPC equivalent** (`buildPpcEquivalent` :171) — GSC queries with `SUM(impr) ≥ 50`
priced via `projectedMonthlyValue`; returns null unless **≥10** queries could be
priced (keeps the headline number from being misleading on a sparse KE cache).
Mirrored in `InsightCards::computePpcEquivalent`.

## GSC lag-aware dates & GA/GSC degradation

`lastSafeReportDate` (:38) computes a ceiling `today(tz) − max(1, gsc_lag_days)`
(`config('reports.gsc_lag_days')`, default **3**, floored at 1 because today is
always partial) and returns the newest stored GSC date `≤ ceiling`. Google takes
24–72h to finalize daily numbers, so this prevents comparing two partial days. A
site stalled at D-7 still reports (D-7 vs D-8); a caught-up site reports D-3 vs
D-4. Zero rows → null → `SendGrowthReports` skips that site.

`reportReadiness` (:80) picks `date = gscDate ?? gaDate` (GSC preferred as the
lag-aware anchor, GA as fallback) and returns `any = (date !== null)`. **`any` is
false only when neither source has data** — that's the sole skip case. A GA-only
or GSC-only site still gets a (degraded) report anchored to whichever source has
data. This is the report-side half of the GSC/GA degradation rule (see project
memory `gsc-ga-degradation.md`).

## Dollar upside (`KeywordValueCalculator`)

Three static functions: `projectedMonthlyValue(vol, pos, cpc)` (value at current
position), `upsideValue(vol, curPos, targetPos, cpc)` (gain from reaching the
target — **target is always position 3** here), and `trendClassify(trend_12m)`.
`attachKeywordMetrics` (:935) decorates striking-distance + cannibalization rows
with `upside_value`, `projected_value`, `addressable_value` (value at position 1 —
the market ceiling), `search_volume`, `cpc`, `competition`, `trend_class`,
`language`. `quickWins` computes `upside_value`/`projected_value` directly.
`addressable_value` appears only on the `attachKeywordMetrics` rows.

## Caching & invalidation

Every cached insight uses a 24h TTL and ends its key with
`ReportCache::version($websiteId)`; date-anchored methods also embed `yesterday`
so the cache auto-rolls daily.

| Method | Key pattern (suffix `…:{version}`) | TTL |
|---|---|---|
| `cannibalizationReport` :744 | `report:cannibalization:v1:{id}:{start}:{end}:{limit}:{country}` | 24h |
| `strikingDistance` :843 | `report:strikingDistance:v2:{id}:…` (v2 added page+page_position) | 24h |
| `contentDecay` :1009 | `report:contentDecay:v1:{id}:{limit}:{country}:{yesterday}` | 24h |
| `indexingFailsWithTraffic` :1208 | `report:indexingFails:v1:{id}:{windowDays}:{limit}:{country}:{yesterday}` | 24h |
| `quickWins` :1310 | `report:quickWins:v1:{id}:{limit}:{yesterday}` | 24h |
| `insightCounts` :1285 | `report:insightCounts:v1:{id}:{country}` | 24h |
| `topCountriesTrend` :1433 | `report:topCountries:v1:{id}:{limit}:{yesterday}` | 24h |

`cannibalizationReport` supports a `$cacheOnly` flag (:751) → `Cache::get(key, [])`,
returning the cached value without ever computing (used where a cache miss should
read empty rather than pay the query).

**`ReportCache`** (`app/Services/ReportCache.php`): `version()` is a
`rememberForever` integer (seed 1); `flushWebsite()` increments it. Bumping the
version orphans every key for that website at once — no enumeration, any driver.
Flushed by `SyncSearchConsoleData`, `TrackKeywordRankJob`, and
`AnalyzeSiteJob::flushSubscribers`.

## Gotchas

- **`keyword_metrics` is shared across tenants** — it's a global KE cache. `quickWins`
  *must* gate on the site's own GSC presence (:1380), otherwise it would surface
  high-volume keywords the site has no relationship to.
- **Everything is anchored to yesterday, app tz** — never "today" (partial GSC day).
- **`generate()` is not cached** — it composes the individually-cached insight
  pieces fresh each call. Heavy preview calls hit the cached sub-methods.
- **Quick wins isn't country-segmented** (`InsightsPanel` :84) — stays aggregate
  even when a country filter is active.

## Key files

- `app/Services/ReportDataService.php`, `app/Services/ReportCache.php`
- `app/Services/KeywordValueCalculator.php` (the $ math)
- `app/Livewire/Dashboard/InsightCards.php`, `app/Livewire/Reports/InsightsPanel.php`
- Source-table producers — see [README.md](./README.md#data-sources-it-reads)
</content>
