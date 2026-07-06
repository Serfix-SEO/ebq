# Reports, Action Queue & Anomaly Detection

The **derived-insight layer** of EBQ: it reads the already-synced data sources
(GSC, GA4, the shared crawl, rank-tracker, Keywords-Everywhere cache, page
audits) and turns them into the things a user acts on — the dashboard insight
cards, the **Priority Action Queue**, the **Reports** tab, the emailed **growth
report**, and the **traffic-drop alert**.

It writes almost nothing. Every number here is a read over data that other
subsystems produce. The one hard problem it solves is **freshness without
re-querying**: a per-website "data version" integer ([ReportCache](#caching--invalidation))
busts every cached payload the moment new GSC rows, a rank result, or a finished
crawl lands.

The crawl-finding half of the Action Queue is documented separately — see
[../crawler/read-path.md](../crawler/read-path.md) (`CrawlReportService`,
the three per-user scopes, `actionGroups`/`issueRows`). This doc covers the
GSC/GA/rank/audit halves and how they merge.

## Read in this order

| Doc | What it covers |
|---|---|
| [insights.md](./insights.md) | **Start here.** `ReportDataService` — every insight method (cannibalization, striking distance, content decay, quick wins, indexing fails), its heuristic + magic thresholds, the GSC lag-aware date logic, the dollar-upside math, and the 24h version-keyed cache. |
| [action-queue.md](./action-queue.md) | `ActionQueueService` — merging 5 GSC reports + rank drops + page audits + crawl findings into one severity-ranked queue. The dashboard widget, the `SiteIssues` detail page, and the `StrikingDistanceFixService` "fix this keyword" playbook. |
| [growth-reports.md](./growth-reports.md) | The emailed report (`SendGrowthReports` → `ReportMailDispatcher` → `GrowthReportMail` + PDF), white-label branding/transport routing, and the anomaly subsystem (`DetectTrafficDrops` → `TrafficAnomalyDetector` → `TrafficDropAlert`). Plus the `GenerateAiInsights` placeholder. |

## Key components

| Component | File | Role |
|---|---|---|
| `ReportDataService` | `app/Services/ReportDataService.php` | The insight engine. All report numbers + the master `generate()` payload. |
| `ReportCache` | `app/Services/ReportCache.php` | Per-website version integer; busted by GSC sync + crawl finalize. |
| `RankCache` | `app/Services/RankCache.php` | Separate version integer busted by rank checks only; see Caching section. |
| `ActionQueueService` | `app/Services/ActionQueueService.php` | Merges all sources into the ranked, grouped action queue. |
| `StrikingDistanceFixService` | `app/Services/StrikingDistanceFixService.php` | The per-keyword fix playbook (audit + AI rewrites + brief + internal links). |
| `TrafficAnomalyDetector` | `app/Services/TrafficAnomalyDetector.php` | z-score + relative-threshold single-day anomaly detection. |
| `Reports/ReportMailDispatcher` | `app/Services/Reports/ReportMailDispatcher.php` | Branding + transport routing for report email. |
| `Reports/ReportBrandingResolver` | `app/Services/Reports/ReportBrandingResolver.php` | Which `ReportBranding` a (user, website) gets. |
| `Reports/ReportPdfRenderer` | `app/Services/Reports/ReportPdfRenderer.php` | The branded PDF attachment (dompdf). |
| `Dashboard/PriorityActionQueue` | `app/Livewire/Dashboard/PriorityActionQueue.php` | The dashboard queue widget. |
| `Dashboard/InsightCards` | `app/Livewire/Dashboard/InsightCards.php` | The 6 insight-count + PPC-equivalent cards. |
| `SiteIssues` | `app/Livewire/SiteIssues.php` | Paginated, filterable detail page for one queue group. |
| `Reports/ReportGenerator` / `InsightsPanel` | `app/Livewire/Reports/*` | The Reports tab (preview/send + insight tabs). |

## Data sources it reads

| Source | Table / model | Producer |
|---|---|---|
| Search Console | `search_console_data` (`SearchConsoleData`) | `SyncSearchConsoleData` job |
| Analytics (GA4) | `analytics_data` (`AnalyticsData`) | `SyncAnalyticsData` job |
| Rank tracker | `rank_tracking_keywords`, `rank_tracking_snapshots` | `TrackKeywordRankJob` |
| Keyword metrics | `keyword_metrics` (`country='global'`) | Keywords-Everywhere sync (shared across tenants) |
| Page indexing | `page_indexing_status` | `SyncPageIndexingStatus` job |
| Backlinks | `backlinks` (`Backlink`) | backlink sync jobs |
| Crawl findings | `crawl_findings` etc. via `CrawlReportService` | the crawler — see [../crawler](../crawler/README.md) |
| Page audits | `page_audit_reports`, `custom_page_audits` via `AuditPerformanceService` | audit jobs |

## Scheduled commands (`routes/console.php`)

| Cron | Command | Effect |
|---|---|---|
| `07:30` daily | `ebq:detect-traffic-drops` | dispatches `DetectTrafficDrops` per website (anomaly alerts) |
| `08:00` daily | `ebq:send-reports` | queues one `GrowthReportMail` per recipient per site |

`ebq:sync-daily-data` runs first (`daily()`) so the report/anomaly commands read
fresh GSC/GA rows, and each sync calls `ReportCache::flushWebsite()`.

## Caching & invalidation

Two version-integer classes underpin all caching (full detail in [insights.md](./insights.md)):

- **`ReportCache::version($websiteId)`** — mixed into every GSC/crawl-derived cache key.
  `flushWebsite()` increments it, atomically orphaning all cached payloads for that website
  (works on any driver — no tag support needed). 24h sanity TTL ages out orphaned versions.
  **Flushed by**: `SyncSearchConsoleData` (nightly GSC sync), `SyncAnalyticsData` (GA sync —
  added 2026-07-06, KPI/traffic cards read AnalyticsData) and `AnalyzeSiteJob::flushSubscribers`
  (crawl finalize). **Not** flushed by rank checks — see below.

  **Auto-warming (2026-07-06):** every flusher above also dispatches
  `App\Jobs\WarmDashboardCaches` (sync queue, `ShouldBeUnique` 5min), which recomputes all
  /dashboard + /statistics card payloads under the new version so the first visitor never
  pays the cold aggregate (~2min on the largest accounts). Zero-drift rule: each card's
  cached payload is a `public static payload()` on its Livewire component — render() and
  the warmer call the SAME method. Adding a new dashboard card cache? Follow that pattern
  and register it in `WarmDashboardCaches::handle()`.
- **`RankCache::version($websiteId)`** (`app/Services/RankCache.php`) — same integer mechanic,
  but tracks rank-tracker freshness only. **Flushed by**: `TrackKeywordRankJob` on each
  successful rank check. Mixed into `PluginHqController::overview`'s cache key (that payload
  surfaces `tracker_distribution` + `tracked_keywords`). **Not** mixed into dashboard or
  `ReportDataService` caches — they're GSC-only and must not bust on hourly rank checks.

**Why the split (2026-06-28):** `ebq:track-rankings` runs hourly. Before the split,
`TrackKeywordRankJob` called `ReportCache::flushWebsite()`, orphaning the 24h cannibalization
and top-countries caches every hour and triggering 590K-row GROUP BY rescans on large sites
(visible as 60s `SELECT … GROUP BY country` in `SHOW PROCESSLIST`). The split keeps GSC
caches warm for their full 24h TTL.

**Lag-aware windows (2026-07-06):** every statistics aggregate (KPI cards, traffic
chart, top-countries, content-decay, indexing-fails, quick-wins) anchors its window
end to `lastSafeReportDate()` via `ReportDataService::statsWindowEnd()` — never
"yesterday", which silently included 2-3 empty GSC-lag days (deflated totals, biased
comparisons, fake chart cliff). New date-windowed aggregates MUST use the same anchor.
The Settings "Search Console window" (`gsc_keyword_lookback_days`, default 28) is the
page-audit keyword window only — statistics windows are fixed 30-data-day by design.

## Gotchas (cross-cutting)

- **`keyword_metrics` is shared across all tenants** — `quickWins` and the upside
  math must gate on the site's own GSC presence or they'd surface keywords the
  site has nothing to do with. See [insights.md](./insights.md#gotchas).
- **Everything anchors to "yesterday" (app tz), not "today"** — today is always a
  partial GSC day. Date-keyed caches roll daily for free.
- **Action-queue counts read as "the real count"** (no 50-cap) but the underlying
  insight methods *default* to 50 — callers pass `PHP_INT_MAX`. See
  [action-queue.md](./action-queue.md).
</content>
</invoke>
