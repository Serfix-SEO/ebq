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
| `ReportCache` | `app/Services/ReportCache.php` | Per-website data-version integer for event-driven invalidation. |
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

One mechanism underpins everything (full detail in [insights.md](./insights.md)):

- **`ReportCache::version($websiteId)`** — a `rememberForever` integer mixed into
  every report/queue/HQ cache key. `flushWebsite()` increments it, atomically
  orphaning all cached payloads for that website with no key enumeration (works
  on any cache driver — no tag support needed).
- **Flushed by** (`grep flushWebsite`): `SyncSearchConsoleData`,
  `TrackKeywordRankJob`, and `AnalyzeSiteJob::flushSubscribers` (per subscriber
  website on crawl finalize). A 24h sanity TTL ages out orphaned versions.

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
