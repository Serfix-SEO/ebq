# Rank tracking

Tracks where a website ranks for a keyword on the live Google SERP over time. Each
**`rank_tracking_keyword`** is re-checked on its own interval against **Serper**, producing a
**`rank_tracking_snapshot`** time series. Independent of the volume-metrics cache, but the same
keyword's GSC data overlays both surfaces read-time.

## Overview

```
ebq:track-rankings (hourly cron)
  └─ select active keywords where next_check_at is null OR <= now  (chunks of 200)
        └─ TrackKeywordRankJob(keywordId)   [queue: sync, timeout 120s, tries 2]
              └─ RankTrackingService::check()
                    ├─ SerperSearchClient::query()  (gl/hl/location/device/depth…)
                    ├─ scan results → our position (url-match else domain-match)
                    ├─ competitor positions + SERP features + PAA + related
                    ├─ write RankTrackingSnapshot (status ok|failed)
                    ├─ update keyword: current/best/initial_position, position_change,
                    │   next_check_at = now + check_interval_hours
                    └─ ReportCache::flushWebsite()  (Overview tracker_distribution rebuild)
```

## Key components

| Component | Role | File |
|---|---|---|
| `RankTrackingService` | The SERP check: query Serper, locate target, build snapshot, update keyword | `app/Services/RankTrackingService.php` |
| `TrackKeywordRankJob` | Queue wrapper; flushes report cache; on error writes failed status + reschedules | `app/Jobs/TrackKeywordRankJob.php` |
| `RankTrackingKeyword` | Tracked keyword + last-known state; `gscQuery()`/`hasGscMatch()` overlay | `app/Models/RankTrackingKeyword.php` |
| `RankTrackingSnapshot` | One SERP check result (position, top results, features, PAA, competitors) | `app/Models/RankTrackingSnapshot.php` |
| `RankTrackingKeywordObserver` | On create: enforce plan cap + queue a volume lookup | `app/Observers/RankTrackingKeywordObserver.php` |
| `TracksKeyword` (trait) | Canonical "Track this keyword" upsert used across research surfaces | `app/Livewire/Keywords/Concerns/TracksKeyword.php` |
| `RankTrackingManager` | Browse/filter/manage tracked keywords; bulk-add from GSC | `app/Livewire/RankTracking/RankTrackingManager.php` |
| `RankTrackingDetail` | One keyword: snapshot history, position chart, GSC insights | `app/Livewire/RankTracking/RankTrackingDetail.php` |
| `TrackRankings` | `ebq:track-rankings {--force}` scheduler (hourly) | `app/Console/Commands/TrackRankings.php` |
| `RankTrackerConfig` | Platform defaults (interval, depth) + target-URL normalization | `app/Support/RankTrackerConfig.php` |

## Data model

- **`rank_tracking_keywords`** — `website_id, user_id, keyword, keyword_hash, target_domain,
  target_url, search_engine, search_type, country, language, location, device, depth, tbs,
  autocorrect, safe_search, competitors[], tags[], notes, check_interval_hours, is_active`, plus
  last-known state: `last_checked_at, next_check_at, last_status, last_error, current_position,
  best_position, initial_position, position_change, current_url`. Unique slot =
  `website_id + keyword_hash + engine + type + country + language + device (+ location)` — so
  re-saving the same config doesn't burn a plan slot (`TracksKeyword.php`,
  `RankTrackingManager.php`).
- **`rank_tracking_snapshots`** — `rank_tracking_keyword_id, checked_at, position, url, title,
  snippet, total_results, search_time, serp_features[], competitor_positions[], top_results[]
  (≤20), related_searches[], people_also_ask[], status (ok|failed), error, forced`.

## Flow details

- **Position match** — for each SERP row, prefer an exact normalized-URL match against
  `target_url`, else a normalized-domain match against `target_domain`; first match wins
  (`RankTrackingService.php:90`). `www.` stripped, scheme-normalized.
- **Result key by type** — `search_type` selects which Serper array to scan
  (organic/news/images/videos/shopping/places; scholar→organic) (`:186`).
- **Billing attribution** — `__website_id`/`__owner_user_id`/`__source='tracker'` are passed
  to `SerperSearchClient::query` and written into `client_activities` for usage metering
  (`:27`).
- **State derivation** — `position_change` = previous − current; `best_position` =
  min(existing, current); `initial_position` set once on first successful position (`:155`).
- **Failure** — a null Serper response writes a `failed` snapshot and still advances
  `next_check_at`, so a flaky check doesn't hammer the API (`:37`). Job-level exceptions also
  set failed status + reschedule and rethrow (`TrackKeywordRankJob.php:41`).

## Scheduling & caps

- **`ebq:track-rankings`** runs **hourly** (`routes/console.php:14`); selects active keywords
  past `next_check_at`, chunks 200, dispatches `TrackKeywordRankJob` per keyword. `--force`
  re-checks every active keyword ignoring the schedule.
- **Default interval 72h**, clamped 1–168h, admin-configurable
  (`rank_tracker.default_check_interval_hours` Setting); **depth fixed at 100**
  (`RankTrackerConfig.php`).
- **Per-plan active-keyword cap** enforced two ways: the Livewire UI blocks at the form level,
  and `RankTrackingKeywordObserver::creating` re-checks `UsageMeter::rankTrackerCap` as
  defense-in-depth for API-route additions (Plugin HQ) that bypass Livewire; over-cap throws
  `QuotaExceededException` (`RankTrackingKeywordObserver.php:23`). Toggling inactive→active
  doesn't re-bill (it goes through `updating`, slot already owned).
- On create the observer also queues a global `FetchKeywordMetricsJob` so the UI has
  volume/CPC on first render (`:54`).

## GSC overlay

`RankTrackingKeyword::gscQuery()` builds a `SearchConsoleData` query matching the same website
and case-insensitive query text (optional device filter); `hasGscMatch()` is the existence
check (`RankTrackingKeyword.php:86`). `RankTrackingDetail` overlays 30-day GSC totals/by-device/
top-pages and a 90-day series alongside the Serper position chart (last 60 ok snapshots);
`RankTrackingManager` enriches the table and bulk-imports candidates from GSC traffic
(impressions/position thresholds, deduped against already-tracked).

## Providers / config

| Env | Default | Notes |
|---|---|---|
| `SERPER_API_KEY` | — | secret; missing ⇒ client returns null ⇒ snapshot `failed` |
| `SERPER_SEARCH_URL` | `https://google.serper.dev/search` | |
| `SERPER_COST_PER_CALL_USD` | `0.0003` | usage metering |
| `rank_tracker.default_check_interval_hours` (Setting) | `72` | clamped 1–168h |

## Gotchas / limits

- **`TrackKeywordRankJob` runs on the `sync` queue** with `timeout=120, tries=2, backoff=30`;
  the observer/manager dispatch *additions* on the `interactive` queue for snappy first checks.
- **A failed Serper call still reschedules** `next_check_at` — by design, but it means a
  persistently-broken keyword silently produces only failed snapshots until someone notices
  `last_status='failed'`.
- **Position is from Serper, not GSC** — the two can disagree (personalization, geo, sampling);
  the GSC overlay is a separate, complementary signal, not a cross-check.
- **`depth` is clamped 10–100** at query time (`RankTrackingService.php:21`); a target outside
  the top `depth` records `position=null` (not found), not an error.
- **No reaper for the hourly select** — if the queue backs up, checks just run late; chunking by
  200 bounds dispatch bursts.

## Key files

- `app/Services/RankTrackingService.php` · `app/Jobs/TrackKeywordRankJob.php`
- `app/Models/{RankTrackingKeyword,RankTrackingSnapshot}.php` · `app/Observers/RankTrackingKeywordObserver.php`
- `app/Livewire/RankTracking/{RankTrackingManager,RankTrackingDetail}.php` · `app/Livewire/Keywords/Concerns/TracksKeyword.php`
- `app/Console/Commands/TrackRankings.php` (`ebq:track-rankings`, hourly) · `app/Support/RankTrackerConfig.php`
- Migrations: `database/migrations/2026_04_20_100000_create_rank_tracking_keywords_table.php`,
  `..._100100_create_rank_tracking_snapshots_table.php`
</content>
