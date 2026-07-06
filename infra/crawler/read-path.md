# Read path & per-user scoping

Everything users see that is derived from a crawl goes through **one** service:
`app/Services/Crawler/CrawlReportService.php`. It turns the **shared** crawl_site data into a
**per-user view** by applying three scopes on every call. This is what makes a single shared
crawl safe to expose to many users.

## The three scopes (applied in every public method)

1. **Cap window** — `windowPages()` filters pages to `value_rank <= cap` (NULL rank treated
   as in-window — site-level findings + in-progress pages). `cap = Website::crawlPageCap()`
   (the user's plan limit, default 200k).
2. **Overlay** — `findingsBase()` excludes findings the user has ignored/resolved via a
   `NOT EXISTS` against `website_finding_states` (where `status IN ('ignored','resolved')`
   for this `website_id`). No row = open.
3. **Per-user impact** — `impactFor()` reads the user's own clicks for the finding's
   `affected_url_hash` from the memoized clicks map. Stored finding `impact` is 0; the number
   the user sees is *their* clicks-at-risk.

## `context()` memoization

`context(websiteId)` (memoized per request in `$ctx[$websiteId]`) resolves:
- `cs` — `Website.crawl_site_id`
- `cap` — `Website.crawlPageCap()`
- `clicks` — `loadUserClicks()`: `SearchConsoleData WHERE website_id AND date >= now()-28d AND
  page != ''`, summed per URL, keyed by `WebsitePage::hashUrl()`.

Every `impactFor()` reads this map — so impact/severity differ per user with zero cross-leak.

## Public methods

| Method | Returns / purpose | Scope notes |
|---|---|---|
| `summary($websiteId)` | Site health overview: pages, indexables, orphans, findings-by-severity, health, run status. **health/last-crawled come from the last `completed` run** (a later failed/aborted recrawl can't wipe a good score or show `blocked` over valid data); `run_status` reflects the latest run for the live UI | window + overlay |
| `actionGroups($websiteId)` | Open findings grouped by category, summed per-user impact (chunked 2000) | window + overlay + impact |
| `issuesQuery($websiteId, $category, …filters)` | Base builder for paginated category issues | window + overlay, severity+recency order |
| `issueRows($websiteId, $category)` | Detail rows (≤100) via `mapFinding` | as above |
| `typeCounts($websiteId, $category)` | Type breakdown within a category | window + overlay |
| `categoryFindings($websiteId, $category)` | Full category list (≤200), severity then impact | window + overlay + impact ordering |
| `reportBreakdown($websiteId)` | Per-category breakdown + top-N examples (email/report) | built on `actionGroups` + `categoryFindings` |
| `inventory($websiteId, $filter)` | Paginated page list (orphans/broken/noindex/deep) | window |
| `linkGraph($websiteId)` | Compact graph (≤120 nodes / ≤600 edges) for the diagram | windowed nodes |
| `pageLinkStructure($websiteId, $url)` | One page's inbound/outbound/suggested + BFS path home | page cap-checked; **neighbours not windowed** (see known-issues) |
| `pageIntel($websiteId, $url)` | Crawl signals for the AI ContextBuilder | window + overlay + impact |
| `mapFinding($finding, $websiteId)` | Normalize one finding to a UI row, inject impact | impact |
| `bfsParents($websiteId)` (internal) | Cached homepage→page parent map | full graph (cached per crawl_site) |

## Consumers

| Consumer | Calls | For |
|---|---|---|
| `Livewire/Dashboard/SiteHealthStats` | `summary` | dashboard health card |
| `Livewire/Dashboard/PriorityActionQueue` → `ActionQueueService` | `actionGroups`, `issueRows` | the action queue (merged with GSC/rank/audit sources) |
| `Livewire/SiteIssues` | `issuesQuery`, `mapFinding`, `typeCounts` | paginated issue detail |
| `Livewire/LinkStructure/LinkStructurePanel` | `pageLinkStructure`, `topInboundPages` (cap-filtered examples — the raw query it replaced was the known-issues cap leak, fixed 2026-07-06) | link-structure explorer |
| `Services/ReportDataService` | `summary`, `actionGroups` | growth-report "Technical SEO" section |
| `Http/Controllers/Admin/MarketingController` | `summary`, `reportBreakdown` | admin email snapshots |
| `Services/Ai/ContextBuilder` | `pageIntel` | AI tool context |
| `Http/Controllers/Api/V1/PluginHqController` | *(none direct)* — via `ReportDataService` | WordPress plugin HQ API |
| `Livewire/Admin/CrawlerProgress` | *(raw, admin-only, all crawl_sites)* | the `/admin/crawler` fleet panel |

## Caching & invalidation

- **`ReportCache`** (`app/Services/ReportCache.php`) — per-website, version-keyed
  (`ws:dataver:{websiteId}` bumped on data change). Cached payloads include the version, so a
  bump orphans stale keys. Keys: `action-queue:{websiteId}:{reportVer}:{rankVer}:…` (24h —
  includes the RankCache version too since 2026-07-06, so hourly rank drops surface without
  waiting for a GSC sync), `hq:overview:v1:{websiteId}:…:{version}` (24h). All dashboard/
  statistics Livewire card caches are also version-keyed at 24h as of 2026-07-06 (they were
  600s, five of them un-versioned — see main.md changelog).
- **Link-graph BFS cache** — `ls-parents-cs:{crawl_site_id}:{version}` (3600s), keyed on the
  crawl_site (shared) + version.
- **Flush** — `AnalyzeSiteJob::flushSubscribers` calls `ReportCache::flushWebsite()` for
  **every** subscriber website on crawl finalize (any terminal status). Also flushed by
  `SyncSearchConsoleData` and (since 2026-07-06) `SyncAnalyticsData` — GA rows feed the KPI/
  traffic cards, so a GA sync must orphan them too. `TrackKeywordRankJob` bumps **RankCache**,
  not ReportCache (the 2026-06-28 split — this doc previously said ReportCache, stale).
- **`context()` is deliberately click-free** — `CrawlReportService::context()` resolves only
  `{crawl_site_id, cap}`; the 28d per-user GSC click map (a full GROUP BY page, 20s+ on a
  1.3M-row site) is lazy via `userClicks()`, built only when a finding-impact path calls
  `impactFor()`. Before 2026-07-06 it was eager, which made the deliberately-uncached
  `summary()` (live crawl banner) take ~20s per /dashboard load on big sites.

## Scoping-leak audit

All AI / plugin / report reads go through `CrawlReportService` or `ReportDataService`
(GSC-scoped) — **no raw crawl-table reads** there. (The one former exception — the
`LinkStructurePanel` example-pages query — was fixed 2026-07-06 via `topInboundPages()`;
see [known-issues.md](./known-issues.md).)
