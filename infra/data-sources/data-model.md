# Data model & schema

The tables that store connected accounts and the data pulled from them. Account tables key by
`(user_id, provider_id)`; fact tables key by `website_id` and cascade-delete with the website.

## Entity map

```
 users ─┬─< google_accounts      (encrypted tokens; one row per Google login)
        └─< microsoft_accounts   (encrypted tokens; Outlook mail only)
                  ▲                        ▲
        ga_google_account_id /  gsc_google_account_id   (nullable FKs on websites, nullOnDelete)
                  │                        │
 websites ─┬──────┴──< analytics_data          (GA4 daily traffic)
           ├──────────< search_console_data     (GSC query×page×country×device×date — the big one)
           └──────────< page_indexing_statuses  (GSC URL-Inspection verdict per page)
```

## Models

| Model | Table | Role |
|---|---|---|
| `GoogleAccount` | `google_accounts` | Connected Google login. Tokens `encrypted`; `label()`. `belongsTo User`. |
| `MicrosoftAccount` | `microsoft_accounts` | Connected Outlook login (mail send only). Same shape. |
| `SearchConsoleData` | `search_console_data` | GSC fact row. `scopeForDateRange`; nullable `keyword_id` → `keywords` dimension. |
| `AnalyticsData` | `analytics_data` | GA4 daily traffic-by-source fact row. `scopeForDateRange`. |
| `PageIndexingStatus` | `page_indexing_statuses` | Per-page Google index verdict; `google_status_payload` cast `array`. |

Google data-source columns live **on `websites`** (not a separate table): `gsc_site_url`,
`ga_property_id`, `gsc_google_account_id`, `ga_google_account_id`, `gsc_keyword_lookback_days`,
`last_search_console_sync_at`, `last_analytics_sync_at`.

## Schema (key columns / indexes)

### `google_accounts` / `microsoft_accounts`
`user_id` (**cascadeOnDelete**), `access_token` (text, **encrypted**), `refresh_token`
(nullable, **encrypted**), `expires_at`, `{google,microsoft}_id`, `email`. **unique**
`(user_id, {google,microsoft}_id)` — same EBQ user can connect multiple provider accounts.
`google_accounts.email` was added later (nullable, lazily backfilled on next email-bearing OAuth).

### `websites` (source columns)
`gsc_site_url` / `ga_property_id` (original NOT-NULL strings; empty `''` = "not connected"
placeholder). `gsc_google_account_id` / `ga_google_account_id` — nullable FKs → `google_accounts`,
**nullOnDelete** (added `2026_06_08`, backfilled by `..._backfill_source_account_ids...`). The
nullOnDelete is what makes "the user deleted that Google account" degrade (`hasGsc()` →false)
instead of cascading away the website. `gsc_keyword_lookback_days` (nullable int; clamped 7–480,
default 28 — used by audits/keyword windows, not the sync window).

### `analytics_data`
`website_id` (**cascadeOnDelete**), `date`, `users`, `sessions`, `source`, `bounce_rate(5,2)`.
**unique** `(website_id, date, source)`; index `(website_id, date)`.

### `search_console_data`  — the large fact table
- `website_id` (**cascadeOnDelete**), `date`, `query`, `page`, `clicks`, `impressions`,
  `position(8,2)`, `country(10)`, `device(20)`, `ctr(8,4)`, nullable `keyword_id` → `keywords`
  (nullOnDelete).
- **unique** `sc_unique = (website_id, date, query, page, country, device)` — the upsert key.
- Indexes (added incrementally as the table grew to tens of millions of rows):
  - `(website_id, date)` — original.
  - `scd_wid_date_query`, `scd_wid_date_position` — covering indexes for HQ/report aggregates
    (`2026_05_27`, added MySQL-online `ALGORITHM=INPLACE, LOCK=NONE` so nightly sync keeps inserting).
  - `scd_wid_query_agg = (website_id, query, clicks, impressions, ctr, position)` — the Keywords
    page does `GROUP BY query ORDER BY SUM(clicks)`; the date-first indexes can't stream that
    group-by, so this puts `query` right after `website_id` and includes the aggregated columns
    (killed a ~25s temp-table+filesort → page timeout) (`2026_06_15`).
  - `scd_keyword_date_idx = (keyword_id, date)`.

### `page_indexing_statuses`
`website_id` (**cascadeOnDelete**), `page(700)`, `last_reindex_requested_at`,
`last_google_status_checked_at`, `google_verdict`, `google_coverage_state`,
`google_indexing_state`, `google_last_crawl_at`, `google_status_payload` (json/`array`).
**unique** `(website_id, page)`; index `(website_id, last_google_status_checked_at)`. The
`google_*` verdict columns mirror the GSC URL-Inspection `indexStatusResult` fields;
`last_reindex_requested_at` records an `indexing` API reindex request (separate from a status check).

## Why account tables and fact tables differ on delete

- **Account tables cascade on `user`** — deleting an EBQ user removes their Google/Microsoft
  tokens (and the per-source FKs on their websites null out via nullOnDelete, not cascade).
- **Fact tables cascade on `website`** — deleting a website removes its GSC/GA/indexing rows.
  But deleting just the *account* (FK nullOnDelete) keeps the website and its historical fact
  rows; the site simply reads as "GSC/GA disconnected" until reconnected.

## Gotchas / invariants

- **`gsc_site_url` / `ga_property_id` empty-string ≠ null.** The columns are NOT-NULL strings; a
  pay-first / placeholder site stores `''`, which `hasGsc()`/`hasGa()` (and the sync guards)
  treat as *absent*. Don't assume null. **Audited 2026-07-06** (repo-wide grep of every
  `gsc_site_url`/`ga_property_id` read-path): every consumer already checks both — `hasGsc()`/
  `hasGa()` (`Website.php:567,577`), all 4 job guards (`SyncSearchConsoleData`,
  `SyncPageIndexingStatus`, `SyncSitemaps`, `SyncAnalyticsData`), the one raw `whereNotNull()`
  query (`CrawlWebsites.php:102`, paired with `!= ''`), `PageDetail.php`/`PluginHqController.php`
  (check `=== ''` only, which is sufficient since the DB schema enforces NOT NULL — a real row can
  never actually be null). No live bug found — this is a gotcha to stay careful about in *new*
  code, not a currently-broken path.
- **`country`/`device` default `''`** — GSC rows without those dimensions (or older backfills)
  sort into the empty bucket; they're part of the unique key, so re-syncing with the full
  dimension set creates *new* rows rather than updating the bucketed ones (hence the
  `ResyncGsc`/`ImportHistoricalData` backfill commands).
- **Token columns are encrypted at rest** — querying them raw in SQL returns ciphertext; always
  go through the model. Never log them.
- **`search_console_data` index changes must be online** on production (no DB backups, huge
  table) — follow the `INPLACE/LOCK=NONE` pattern in the existing migrations; the migrations are
  re-entry-safe and no-op on non-MySQL dev DBs.

## Key files

- Migrations — `database/migrations/2026_04_14_2008{13,14,15}_*`,
  `2026_04_16_22/23_*page_indexing*`, `2026_05_20_000002_create_microsoft_accounts_table.php`,
  `2026_05_27_120000_*query_indexes*`, `2026_06_15_190000_*keyword_aggregation_index*`,
  `2026_06_08_1001/02_*source_account*`/`*email*`
- Models — `app/Models/{GoogleAccount,MicrosoftAccount,SearchConsoleData,AnalyticsData,PageIndexingStatus,Website}.php`
