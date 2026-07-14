# Priority Action Queue — the multi-source merge

`app/Services/ActionQueueService.php` builds the dashboard's single, impact-ranked
list of "what to fix next" by merging **everything that already exists** into one
queue: 5 GSC insight reports + rank-tracker drops + page audits + crawl findings.
It writes nothing — every source method is itself cached.

## The seven GSC/rank/audit sources + the crawl merge

`groupedActions(websiteId, ?country)` (:49) assembles grouped summary rows (one per
issue type), then appends the crawl-derived groups:

| Group key | Source method | Severity | `file:line` |
|---|---|---|---|
| `indexing_fails` | `ReportDataService::indexingFailsWithTraffic` | **critical** | :60 |
| `cannibalization` | `ReportDataService::cannibalizationReport` | high | :63 |
| `content_decay` | `ReportDataService::contentDecay` | high | :66 |
| `rank_drops` | `rankDropRows()` (local query) | high | :69 |
| `audit_performance` | `AuditPerformanceService::underperformingPages` | high | :72 |
| `striking_distance` | `ReportDataService::strikingDistance` | growth | :75 |
| `quick_wins` | `ReportDataService::quickWins` | growth | :78 |
| `crawl_*` (broken links, orphans, on-page…) | `CrawlReportService::actionGroups` → merged | per-finding | :84 |

The crawl half (`crawl_*` keys, the per-user cap/overlay/impact scopes) is
documented in [../crawler/read-path.md](../crawler/read-path.md) — it is *merged*
here, not re-implemented.

**Rank drops** (`rankDropRows` :175): active `RankTrackingKeyword` rows where
`position_change <= -5` (`RANK_DROP_THRESHOLD`), ordered most-negative first.

## Ranking & the count cap

After dropping empty groups, `usort` (:88) sorts by **severity tier**
(critical → high → growth) then by **impact desc**. Impact is the summed dollar
`upside_value` when available, else the group count (`summary()` :197) — so busier
groups float up within a tier when no $ value exists.

`COUNT_LIMIT = PHP_INT_MAX` (:35): the queue requests *every* row from each source
so counts read as the real number. The default-50 cap on the source methods would
otherwise make any large group display as exactly "50". The expensive grouping
happens before the final slice and each source caches by limit, so requesting
everything is cheap.

## Detail rows (lazy)

`issueRows(key, websiteId, ?country)` (:103) loads the normalized detail rows for
ONE group only when the user opens it. A `match` per non-crawl key maps each
source's rows to a uniform shape `{title, subtitle, metric, fix_url, fix_feature}`;
the `default` arm (:166) delegates `crawl_*` keys to
`CrawlReportService::issueRows`. `fix_feature` drives a plan-feature gate in the
view (`pages` / `rank_tracking` / `keywords` / `audits`).

## Flow: dashboard widget → detail page

```
Dashboard
  └─ Livewire/Dashboard/PriorityActionQueue   (#[Lazy] widget)
       ├─ NEVER hides outright (fixed 2026-07-13). While the site's first
       │    crawl is queued/running/finalizing (Website::isInitialCrawl()),
       │    it calls groupedActions(includeCrawlIssues: false) — crawl_*
       │    groups are excluded (not final yet) but GSC/rank-tracking-derived
       │    groups (cannibalization, rank_drops, quick_wins, etc.) still show,
       │    since those don't depend on crawl state. The empty state is
       │    three-way: "Crawl in progress" (still initial), "Still finalizing
       │    your results" (crawl completed <60s ago — recentlyFinishedCrawl(),
       │    a settling grace window), or genuine "You're all caught up".
       │    Previously hid the WHOLE card (including non-crawl items) for as
       │    long as isInitialCrawl()'s queued-window covered a brand-new site
       │    (up to 6h) — a real regression once that window was widened to
       │    cover the pre-crawl queued state, caught via a failing test
       │    (a tracked rank-drop keyword on a not-yet-crawled site read as
       │    "You're all caught up").
       ├─ Cache::remember 'action-queue:v4:{id}:{version}:{rankVersion}:
       │    {country}:{locale}:{includeCrawlIssues}' 86400s — PriorityActionQueue::payload()
       │    version = ReportCache::version(id), so a finished crawl /
       │    GSC sync / rank result busts it. includeCrawlIssues is IN the key
       │    (v4) — a site flipping in/out of its initial-crawl window must not
       │    read the other state's cached shape. WarmDashboardCaches passes
       │    the same flag (computed from the SAME isInitialCrawl() check).
       └─ groupedActions() → row click navigates to ↓

Livewire/SiteIssues  (dedicated paginated detail page for one group)
  ├─ crawl_* groups : DB-paginated straight from crawl_findings via
  │    CrawlReportService::issuesQuery (type / severity / URL filter) —
  │    essential when one category holds tens of thousands of findings
  ├─ non-crawl groups : ActionQueueService::issueRows (small set),
  │    paginated in-memory, free-text filter over title+subtitle — :148
  └─ per-row fix_allowed = hasFeatureAccess(fix_feature) — :126
```

`SiteIssues` re-reads the same cached `action-queue:{id}:{version}:all` payload
for the group's title/severity/count meta (:91). Access is gated on
`canViewWebsiteId` (404 on unknown key, 403 on no access).

## The "Fix this keyword" playbook — `StrikingDistanceFixService`

`app/Services/StrikingDistanceFixService.php` powers the slide-over a user opens
from a striking-distance / quick-win row. Given `(websiteId, keyword, pageUrl)` it
stitches together four fix levers, all from existing infrastructure:

1. **On-page recs + metrics** — a keyword-aware `PageAuditService` run
   (`recommendations()` :96, `onPageMetrics()` :117 — keyword presence in
   title/H1/meta + word-count vs SERP top-3 median).
2. **AI title/meta rewrites** — `AiSnippetRewriterService` fed from the audit's
   fetched copy + SERP competitor titles (`snippetRewrites()` :156).
3. **Content brief / topical gaps** — `AiContentBriefService` (`brief()` :181 —
   returns a cached brief for free; only spends a Serper credit when `$generate`).
4. **Internal-link suggestions** — `internalLinkTargets()` (:207), no AI call,
   excludes the ranking page itself (no self-link).

**Synthetic post id** (:43): the AI services were built for the WordPress editor
and take an `int $postId` purely as a 7-day cache key — they never load a Post.
Striking-distance keywords are GSC URLs with no WP Post, so the service derives a
deterministic positive 31-bit id from `xxh3(websiteId|url)`. Same URL → same id →
same cache slot; the 31-bit mask guarantees it can never collide with a real WP
post id used by the plugin's snippet cache.

`queueAudit()` (:70) reuses the `CustomPageAudit` pipeline and **dedupes** against
any audit already queued/running for the same (website, url, user) so a repeat
click never pays twice; `findFreshReport()` (:54) reuses any completed audit
younger than `$maxAgeHours` (default 24h) instead of running a new one.

## Gotchas

- **The queue hides during the first crawl** (`isInitialCrawl`) — without this it
  would render empty/half-baked because most actions are crawl-derived.
  `Website::isInitialCrawl()` (`app/Models/Website.php:689`) covers the **queued
  window too**, not just an active RUNNING run: the `CrawlRun` row is only created
  once `CrawlWebsitePagesJob` starts on the worker, so between subscribe-dispatch
  (`SyncSitemaps` → `CrawlWebsitePagesJob` chain) and that job running there is no
  running crawl. Before 2026-07-13 the gate was `isCrawling() && !hasCompletedCrawl()`,
  so a brand-new client hitting the dashboard in that gap saw **"You're all caught up"**
  (empty queue) with **no crawl banner**. Now: no completed crawl AND (a run is
  running OR the `crawl_site` was created <6h ago) → in-progress. `CrawlBanner`
  renders a "We're setting up your site" stand-in for that same queued window;
  `SiteHealthStats` gate aligned to `isInitialCrawl()`. Bounded by crawl_site age so
  a crawl that never starts can't spin the banner forever.
- **Counts are uncapped on purpose** but the source methods default to 50 — always
  pass `COUNT_LIMIT` when you want the true total.
- **Crawl groups paginate from the DB; non-crawl groups paginate in-memory** — the
  GSC/rank/audit sets are small, crawl findings can be tens of thousands.

## Key files

- `app/Services/ActionQueueService.php`, `app/Services/StrikingDistanceFixService.php`
- `app/Livewire/Dashboard/PriorityActionQueue.php`, `app/Livewire/SiteIssues.php`
- `app/Services/Crawler/CrawlReportService.php` → [../crawler/read-path.md](../crawler/read-path.md)
- `app/Services/AuditPerformanceService.php` (`underperformingPages`)
</content>
