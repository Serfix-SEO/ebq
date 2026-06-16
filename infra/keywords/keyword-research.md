# Keyword research, metrics & gap

The user-facing keyword tools — volume lookup, idea discovery, competitor gap, the
striking-distance fix plan — plus the **`keyword_metrics` cache** and **`KeywordMetricsService`**
that back them all. Provider-agnostic: every read is DB-first, refreshes route to whichever
provider is configured (see [keyword-finder.md](./keyword-finder.md)).

## Overview / metrics service

`KeywordMetricsService` (`app/Services/KeywordMetricsService.php`) is the **sole entrypoint**
for every volume read. Shape:

| Method | Behaviour |
|---|---|
| `metricsFor` / `metricsForMany` | pure DB read, keyed by `keyword_hash` (`:34`,`:48`) |
| `metricsOrQueue` | DB read **+ background `FetchKeywordMetricsJob`** for missing/stale keys — what the UI wants (`:77`) |
| `refresh` | synchronous: finder → async `dispatchIdeas` (returns 0); else KE sync upsert (`:109`) |
| `ingestFinderResults` | webhook ingest of finder rows (bucketed volume, index/100 competition, bid range) (`:261`) |

Provider routing is a single branch on `KeywordProviderConfig::usingKeywordFinder()`
(`KeywordMetricsService.php:128`).

## Key components

| Component | Subsystem | Caps | File |
|---|---|---|---|
| `KeywordResearch` | tab router (ideas/volume/gap); cross-tool **handoff** via `research-handoff` event | — | `app/Livewire/Keywords/KeywordResearch.php` |
| `KeywordIdeaFinder` | seed/URL expansion via `KeywordFinderPool::dispatchIdeas`; async poll on `KeywordApiRequest` | **20 seeds** | `app/Livewire/Keywords/KeywordIdeaFinder.php` |
| `KeywordVolumeFinder` | bulk volume; dual backend (KE sync / finder async); cache-first | **100 keywords** | `app/Livewire/Keywords/KeywordVolumeFinder.php` |
| `KeywordGapService` | competitor gap: fan-out discovery → bucket → score → verify | 3 competitors, 1000 rows | `app/Services/Competitive/KeywordGapService.php` |
| `KeywordFixPlaybook` | striking-distance fix guide; audit + lazy AI levers | 8 audits/120s/user | `app/Livewire/Keywords/KeywordFixPlaybook.php` |
| `KeywordsTable` | GSC query table enriched with volume/CPC/trend | `simplePaginate(25)` | `app/Livewire/Keywords/KeywordsTable.php` |
| `KeywordDetail` | single-keyword deep dive (GSC + metrics + tracker + projections) | top-10 pages/geos | `app/Livewire/Keywords/KeywordDetail.php` |
| `KeywordValueCalculator` | pure CTR-curve → clicks/value/upside, trend classify | — | `app/Services/KeywordValueCalculator.php` |
| `FetchKeywordMetrics` | `ebq:fetch-keyword-metrics` — backfill from GSC queries (manual) | — | `app/Console/Commands/FetchKeywordMetrics.php` |

## Data model

- **`keyword_metrics`** (`app/Models/KeywordMetric.php`) — `keyword, keyword_hash, country,
  data_source, search_volume, cpc, low/high_top_of_page_bid, currency, competition, trend_12m,
  fetched_at, expires_at`. Cache key = `hashKeyword()` (sha256 of lower-trimmed keyword) +
  country. `isFresh()` = `expires_at` in the future (30-day default). `trend_class` /
  `next_peak_month` are computed accessors delegating to `KeywordValueCalculator`.
- **`keyword_gap_analyses`** (`app/Models/KeywordGapAnalysis.php`) — header for one gap run:
  `our_url, competitor_urls[], country, status (queued|collecting|completed|failed),
  request_ids[], total/completed_requests, summary, expires_at`, plus a separate verification
  sub-lifecycle (`verify_status, verify_total/done, verified_at`).
- **`keyword_gap_rows`** (`app/Models/KeywordGapRow.php`) — one keyword per run:
  `bucket (missing|weak|strength|shared), search_volume, competition, cpc, our_position,
  competitor_domains[], opportunity_score, score_components`.

## Flows

### Volume Finder (cache-first, dual backend)
1. Clean+cap to 100 keywords; `metricsForMany()` cache check.
2. **KE path**: bill only uncached keywords; quota pre-flight (`UsageMeter::assertCanSpend`),
   then `KeywordsEverywhereClient::getKeywordData` (chunked at 100/call).
3. **Finder path**: `dispatchIdeas([seeds])` → poll `KeywordApiRequest` while
   `queued|running`; on completion re-read cache. The webhook has by then cached the searched
   terms **plus** the wider related set (UI only displays the searched ones).

### Keyword Gap (async fan-out)
`KeywordGapService::start` creates the analysis (`collecting`) and dispatches **one
website-mode `dispatchIdeas` per URL** (ours + ≤3 competitors); records `request_ids` with
roles. `maybeAggregate()` (polled) atomically counts finished requests; when all done (or a
5-min collect timeout), it runs aggregation **once**: diff our keywords/GSC positions vs
competitors → bucket (**missing** = they have it, we don't; **weak** = we rank >10;
**strength** = ≤10; **shared** = we have it, no GSC position) → enrich from cached metrics →
score via `OpportunityScoreService` → cap to top-1000 by volume. Optional **verification**
re-checks the Missing bucket (≤25 kw) against the live Serper SERP, confirms competitor +
our real positions, re-buckets, and recomputes scores from the same response. `tries=1`,
unique per analysis so a double-dispatch can't double-bill SERP
(`app/Jobs/RunKeywordGapVerification.php`).

### Cross-tool handoff
Child tools emit `research-handoff` (target tab + keywords); `KeywordResearch` switches tab and
preseeds via a nonce-bumped key so the child remounts fresh
(`app/Livewire/Keywords/KeywordResearch.php:49`).

## Value calculator (pure)

`KeywordValueCalculator` — Sistrix-style CTR curve (pos1 ≈ 28%, pos10 ≈ 2%, >20 ≈ 0),
`projectedMonthlyClicks/Value`, `upsideValue` (dollar upside of moving currentPos→targetPos),
`trendClassify` (rising/falling/seasonal/stable/unknown via log-slope + coefficient-of-variation;
**seasonality wins before slope**), `nextPeakMonth`. Null-tolerant: missing data → null/0,
never throws (`KeywordValueCalculator.php:14`).

## Providers / config

| Env | Default | Notes |
|---|---|---|
| `KEYWORDS_EVERYWHERE_API_KEY` | — | secret; missing key ⇒ client returns null (no throw) |
| `KEYWORDS_EVERYWHERE_BASE_URL` | `https://api.keywordseverywhere.com` | |
| `KEYWORDS_EVERYWHERE_FRESH_DAYS` | `30` | KE cache TTL |
| `KEYWORDS_EVERYWHERE_COST_PER_KEYWORD_USD` | `0.0001` | usage metering |
| `COMPETITIVE_GAP_MAX_COMPETITORS` | `3` | gap fan-out cap |
| `COMPETITIVE_GAP_ROW_CAP` | `1000` | rows kept (top by volume) |
| `COMPETITIVE_GAP_COLLECT_TIMEOUT_MINUTES` | `5` | collect-phase timeout |
| `COMPETITIVE_GAP_VERIFY_MAX` | `25` | SERP-verified keywords |
| `COMPETITIVE_GAP_VERIFY_INCLUDE_SHARED` | `false` | verify Shared/Weak too |
| `COMPETITIVE_SERP_CACHE_DAYS` | `7` | gap SERP cache |

KE accepts only `global|us|uk|ca|au|in|nz|za` (`app/Support/KeywordsEverywhereCountries.php`),
validated before any credit is spent. The finder accepts the much wider
`KeywordFinderLocations::COUNTRIES` set.

## Gotchas / limits

- **Always write under the input keyword's hash, not the provider's returned string** — KE may
  normalize/drop a keyword; writing under its echo breaks skip-fresh on re-run
  (`KeywordMetricsService.php:170`).
- **KE bills per keyword in the request, even ones it has no data for** — so an empty
  `keyword_metrics` row with a fresh `expires_at` is written deliberately as a "we asked, KE has
  nothing" cache entry, to avoid re-billing long-tail misses (`:215`).
- **Finder `refresh()` returns 0 synchronously** — rows arrive later via the webhook; the UI's
  `metricsOrQueue` polling surfaces them on the next Livewire tick.
- **`metricsForMany` returns only what's on file** — a fresh search shows partial data until the
  background job/webhook completes; callers must tolerate missing keys.
- **`KeywordsTable` uses `simplePaginate` and a GROUP-BY-without-COUNT** aggregate view on
  purpose — a full count over 30k+ GSC queries would time out (`KeywordsTable.php`).
- **Gap aggregation runs exactly once** — guarded by an atomic finished-request count;
  `tries=1` keeps a partial result rather than re-running the paid batch.

## Key files

- `app/Services/{KeywordMetricsService,KeywordValueCalculator,KeywordsEverywhereClient}.php`
- `app/Services/Competitive/KeywordGapService.php` · `app/Jobs/{FetchKeywordMetricsJob,RunKeywordGapVerification}.php`
- `app/Livewire/Keywords/*` (`KeywordResearch,KeywordIdeaFinder,KeywordVolumeFinder,KeywordFixPlaybook,KeywordsTable,KeywordDetail`, `Concerns/TracksKeyword`)
- `app/Models/{KeywordMetric,KeywordGapAnalysis,KeywordGapRow}.php`
- `app/Support/{KeywordProviderConfig,KeywordsEverywhereCountries,KeywordFinderLocations}.php`
- `app/Console/Commands/FetchKeywordMetrics.php` (`ebq:fetch-keyword-metrics`, not scheduled)
</content>
