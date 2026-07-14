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

## Ideas tab — research-grade table (upgraded 2026-07-13)

`KeywordIdeaFinder` now carries the full research toolset over the in-memory result set
(≤ a few hundred rows, so array pipeline — no DB):

- **Filters**: include/exclude text, min/max volume, competition, **intent**
  (all/informational/commercial/transactional/navigational/other), **questions-only**.
- **Intent** = `App\Services\KeywordResearch\KeywordIntentClassifier` — deterministic
  heuristic (EN patterns; transactional > navigational > commercial > informational
  precedence; unmatched → `other`). Free, applied to every row in `normalizeRow()`.
- **Groups rail** = `App\Services\KeywordResearch\KeywordTermGrouper` — algorithmic
  unigram+bigram term groups (stopword-filtered, min 2 kws, bigram-over-unigram dedupe),
  volume-ranked; clicking a term filters via whole-word match (`keywordHasTerm()`).
  The rail is computed with every filter EXCEPT the active group so switching never
  dead-ends (Keyword Magic behavior).
- **AI clusters** = `App\Services\KeywordResearch\AiKeywordClusterService` (v2, 2026-07-13) —
  ONE batched `LlmClient::completeJson` call (active provider: DeepSeek/Mistral admin
  setting) groups up to **300 keywords (volume-first)** into named topic clusters.
  **Declines below `MIN_KEYWORDS=6`** (clustering adds nothing over the flat list at that
  size) — component shows "Not enough keywords" instead of spending a call. Cluster-count
  target is **dynamic** (`round(count/7)`, clamped 3–20) instead of a fixed "5-15" that
  fragmented small sets. Prompt instructs "every cluster ≥ 2 keywords, else → Other", AND
  a deterministic **`mergeSingletonClusters()`** safety net re-folds any label the model
  still gave only 1 member into "Other" — never trust prompt compliance alone (v1's real
  bug: small/typical result sets came back mostly 1-keyword "clusters", no better than the
  free Groups rail). "Other" always sorts last in the clusters view regardless of volume,
  and renders visually muted/dashed with an "(ungrouped)" hint.
  Strict membership validation (invented keywords dropped, unassigned → "Other").
  **Month-cached** (cache key version `v2`) keyed on the ideas monthly-cache key + keyword
  list, so re-clicks/cached result sets never re-bill. **Recluster button** (`force: true`,
  rate-limited 5/10min per user) bypasses the cache — the escape hatch when a clustering
  attempt is unsatisfying (v1 had none; a bad result was stuck for the rest of the month).
  UI: "Cluster with AI" button → clusters view (sections with label + count + volume);
  List/Clusters toggle + Recluster button appear after the first successful run.
- **Bulk selection**: checkbox column + select-page; action bar → **Track all** (caps
  `MAX_TRACK_BATCH=50`, via `TracksKeyword::trackOne()` — the trait's new protected core,
  `track()` wraps it), **Copy** (browser `copy-to-clipboard` event → Alpine listener),
  Clear. CSV export gained Intent + Cluster columns.
- **Per-row GSC metrics replace the old Volume/Track/Brief action links (2026-07-13).** The
  "Brief" link was actually **broken** — `route('keywords.fix', ['keyword'=>...])` omits the
  `page` param `KeywordFixPlaybook::mount()` requires, so it always hit its `fail('Missing
  keyword or page...')` guard; "Track" was redundant once bulk-select existed; "Volume" just
  duplicated the ideas volume already on the row. Now the last column shows, per keyword, the
  website's own Search Console data over the trailing 28 full days (lag-aware, ends
  yesterday): **position/clicks/impressions** if the site already has a GSC row for that
  exact query (case-insensitive match), a **"New"** badge if it doesn't (untapped topic), or
  a muted "—" if the site has no GSC connected at all. Query: `KeywordIdeaFinder::gscMetricsFor()`
  — one indexed `whereIn(LOWER(query))` + `GROUP BY` against `search_console_data`
  (mirrors `KeywordsTable`'s aggregation pattern), computed once per render over whichever
  rows are actually displayed (paginated list rows, or the full filtered set in clusters view).
- Row partial shared by list + clusters views: `livewire/keywords/partials/idea-row.blade.php`.
- Intent pills avoid indigo/violet (brand rule) — commercial uses teal.
- Tests: `tests/Feature/KeywordResearchUpgradeTest.php`.

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
**Own page since 2026-07-14:** the Gap tool moved OUT of the `/keyword-research` hub tabs
to its own Orbit sidebar page — route `keyword-gap.index` (`/keyword-gap`,
`feature:keywords`, view `keyword-gap.blade.php`). Hub tabs are now Ideas · Volume only;
old `?tab=gap` deep links redirect via `KeywordResearch::mount()`, and `/competitive`
redirects to `/keyword-gap`. **Cross-page handoff:** the gap page's "send to
Ideas/Volume" actions can no longer reach the hub via Livewire events — they navigate to
`/keyword-research?tab=X&kw=<keyword>` instead, and `KeywordResearch::mount()` turns
`?kw=` into the same preset payload the event used to carry.

**Competitor picker (2026-07-14):** `Livewire\Competitive\KeywordGapAnalysis::mount()`
sources one-click competitor suggestions from the website's **Site Explorer snapshot**
(`WebsiteReportSnapshot::forDomain()->payload['competitors']` — DataForSEO Labs organic
competitors, already sorted by shared keywords; top 12 shown, top 3 pre-selected). This is
a direct snapshot READ, deliberately **not** `ReportViewController::resolve()` (which would
dispatch a billed generation as a side effect of opening the tab) — guarded by
`tests/Feature/KeywordGapCompetitorPickerTest.php`. Fallbacks: SERP auto-discovery pre-fill
when no snapshot exists (old behaviour), plus a manual add-domain input (normalized via
`CompetitorBacklink::extractDomain`). Selection capped at `gap_max_competitors` (3) with an
explicit deselect-first message. The old UI was 3 bare text inputs.

**Shared discovery cache (2026-07-14):** gap runs now READ and WRITE the same
cross-user `KeywordIdeasMonthlyCache` the Ideas tab + WP plugin use. `start()` checks the
cache per URL first — a hit becomes a `{cache_key, id: null}` entry in `request_ids`
(counted as instantly finished by `maybeAggregate()`, consumed by `aggregate()` via
`keywordsFromRows()`) and NO keyword-server request is dispatched. Every COMPLETED
dispatched discovery is written back to the cache during `aggregate()`, so the first
user to gap-analyze any domain warms it platform-wide for the rest of the calendar month
(the cache's existing Y-m semantics — up to ~30 days, month rollover invalidates).
Before this, every gap run re-dispatched all N website-mode discoveries even seconds
after an identical run. Note the volume-enrichment backfill (`metricsOrQueue`,
keywords-mode) may still dispatch for uncached metrics — different, cheaper concern.
Tests: `tests/Feature/Competitive/KeywordGapSharedCacheTest.php`.

**Collecting-state UX (2026-07-14):** while a run collects, the competitor picker
collapses into a live progress teaser — per-source rows (domain + your-site tag +
cached / discovering / collected / failed state icons), an N/M counter with a
what-happens-next line, and a skeleton of the incoming results table
(`collectingProgress()` in the Livewire component feeds it). The old UX was just a
spinner on the Run button with results appearing far below.

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
