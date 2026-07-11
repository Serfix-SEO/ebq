# HQ API — `PluginHqController`

> The "EBQ HQ" admin-page API. Powers the top-level WP-admin menu the plugin
> renders: overview, performance, keywords, pages, index status, the insights
> opportunity feed, growth reports, page audits, and the Phase-3 network-effect
> + AI-writer surfaces.

`app/Http/Controllers/Api/V1/PluginHqController.php` (~78 KB, one method per
endpoint). It is the API *surface* over the existing service layer — **no parallel
domain logic**. Constructor injects `ReportDataService` and `PluginInsightResolver`
(`PluginHqController.php:48`).

## Auth + middleware stack

Every route is inside the `routes/api.php:91` `hq` group, which inherits the parent
group's middleware (`routes/api.php:18`):

```
website.api:read:insights → website.features → throttle:60,1
```

- **`website.api:read:insights`** (`WebsiteApiAuth`) — bearer Sanctum token whose
  tokenable is a `Website`, ability `read:insights`. Sets `api_website`.
  `PluginHqController::website()` (`:1657`) reads it back; **never** trusts a param for
  tenancy.
- **`website.features`** (`InjectFeatureFlags`) — runs *after* the controller, spreads
  `features` / `frozen` / `frozen_reason` / `tier` / `free_promo` onto every JSON body
  unless the controller already set them (`InjectFeatureFlags.php:43`). This is the
  passive flag-sync channel the plugin relies on.
- **`throttle:60,1`** — 60 req/min per token.

## What it reads (and does NOT)

All data comes from the **GSC-scoped service layer**, identical to the EBQ.io Livewire
dashboards:

| Source | Used for |
|---|---|
| `SearchConsoleData` (`website_id`-scoped) | KPIs, performance, GSC keywords, pages, daily clicks, position dist. |
| `RankTrackingKeyword` / `RankTrackingSnapshot` | tracked keywords, history, candidates |
| `PageIndexingStatus` | index-status verdicts, reindex bookkeeping |
| `ReportDataService` | insights feed, insight counts, growth report (routes through `CrawlReportService` when it needs crawl signals) |
| `CustomPageAudit` + `PageAuditService` | page-audit queue / list / report URLs |
| `*Service` (Serp/Backlink/Topical/Benchmark) | Phase-3 network-effect endpoints |

It reads **no crawl tables directly** (`website_pages`, `crawl_findings`,
`website_internal_links`). Crawl-derived findings arrive only via `ReportDataService`,
which is itself scoped — so the HQ API inherits per-website GSC privacy and has **no
cross-tenant leak surface** (cross-ref `../crawler/adjacent-systems.md`).

## Endpoint catalog

All paths are under `/api/v1/hq`. Auth is uniform (the stack above), so the column
notes only *deviations* (writes, external calls, rate limits).

| Method · path | Reads / does | Cache / notes |
|---|---|---|
| `GET /overview` | `aggregateGsc` + ranking/tracked keyword counts + insight counts | **`hq:overview:v1:{websiteId}:{range}:{ReportCache::version}`**, 24 h sanity TTL (`:67`). Invalidated event-driven via `ReportCache::flushWebsite()` from nightly GSC sync + rank job |
| `GET /performance` | `SearchConsoleData` time series | — |
| `GET /keywords` | `RankTrackingKeyword` paginated + per-keyword GSC join | — |
| `POST /keywords` | **write** — `RankTrackingKeyword::updateOrCreate`; mirrors the Livewire add-keyword form | dispatches tracking |
| `GET /keywords/candidates` | GSC rows minus already-tracked | — |
| `PATCH /keywords/{id}` | **write** — update a tracked keyword | scoped to website |
| `DELETE /keywords/{id}` | **write** — delete a tracked keyword | scoped to website |
| `POST /keywords/{id}/recheck` | **write** — dispatch `TrackKeywordRankJob` | — |
| `GET /keywords/{id}/history` | `RankTrackingSnapshot` series | — |
| `GET /gsc-keywords` | `SearchConsoleData` keywords + tracked overlay | — |
| `GET /pages` | `SearchConsoleData` per-page aggregates | — |
| `GET·POST /index-status` | `SearchConsoleData` + `PageIndexingStatus`; sitemap presence; sort priority | POST accepts a URL list body |
| `POST /index-status/submit` | **external** — Google Indexing API `urlNotifications:publish`; needs `gscAccountResolved()`; writes `last_reindex_requested_at` (`:887`) | 502 on Google error; `needs_google_reconnect` flag |
| `POST /index-status/recheck` | **external** — live URL Inspection via GSC; needs `gsc_site_url` (`:972`) | writes verdict to `PageIndexingStatus` |
| `GET /insights/{type}` | `ReportDataService` — `cannibalization` / `striking` / `decay` / `index_fails` / `quick_wins` / `audit_performance` / `backlink_impact` (`:1086`) | `type` regex `[a-z_]+`; 404 on unknown |
| `GET /insight-counts` | `ReportDataService::insightCounts` | tile counts |
| `GET /growth-report` | `ReportDataService::generate` — same payload as EBQ Reports → Custom | 422 on bad range/type |
| `POST /growth-report/send` | **email** — `ReportMailDispatcher` to `getReportRecipientUsers()` (`:1152`) | rate-limited 5/h per website (`hq-growth-report:{id}`) |
| `GET /page-audit/countries` | SERP `gl` catalog | — |
| `GET /page-audit/suggestions` | `PageAuditService` suggested URLs | — |
| `POST /page-audit` | **write** — `RunCustomPageAudit` queue (`:1287`) | validates URL belongs to site |
| `GET /page-audits` | `CustomPageAudit` list | — |
| `GET /page-audits/{id}/report-url` | mints a **20-min signed** `wordpress.embed.page-audit` URL on the public root (`:1418`) | 409 if not completed |
| `GET /serp-features` | `SerpFeatureTrackerService` | Phase 3 |
| `POST /backlink-prospects` · `/draft` | `BacklinkProspectingService` (+ AI outreach draft) | Phase 3 |
| `GET /outreach-prospects` · `POST .../auto-discover` · `POST .../{id}` | `BacklinkProspectingService` CRUD | Phase 3 |
| `GET /benchmarks` | `CrossSiteBenchmarkService` | cross-site network effect |
| `GET /topical-authority` | `TopicalAuthorityService` | — |
| AI Writer wizard (`/writer-projects*`) | `WriterProjectController` — multi-step drafts, Serper images, content-credit accounting | not in `PluginHqController` |
| AI prompts / AI Studio (`/ai-writer-prompts*`, `/ai/tools*`, `/ai/brand-voice`) | `AiWriterPromptController` / `AiToolController` | registry-driven |

(The `/writer-projects`, `/ai-writer-prompts`, `/ai/*` routes live in the same `hq`
group but are handled by sibling controllers — see `routes/api.php:143`.)

## Site Audit (crawler report) — `PluginCrawlController` (2026-07-10)

First token-authorized JSON surface over the shared-crawl subsystem (previously
iframe-embed-only). All read-only, all delegate to `CrawlReportService` (cache-backed:
`actionGroups`/`typeBreakdown`/`typeCounts` are 24 h version-keyed). **Gated on the
`hq` feature flag** in-controller (`website()` helper aborts 403) — deliberately NOT a
new plan-flag key: `featureMap()` defaults missing keys to `false`, which would kill
the feature fleet-wide until a plan-row backfill. Works with zero GSC (impact → 0).

| Method · path | Reads | Notes |
|---|---|---|
| `GET /site-audit/summary` | `summary()` — health score, run status, blocked+reason, page counts, severity totals | uncached but cheap; `last_crawled_at` ISO-8601 |
| `GET /site-audit/issues` | `actionGroups()` | `{groups:[{key,title,count,severity,impact,types[]}]}` |
| `GET /site-audit/issues/{category}` | `typeBreakdown()` + `issuesQuery()->simplePaginate(≤50)`; `guidance` (fix/about) only when `?type=` set | `{category}` validated against `CrawlFinding::CATEGORY_*` → 422; filters `type,severity,q,per_page,page` |
| `GET /site-audit/pages` | `inventory($filter)` paginated + `pageFindingCounts()` (per-page `open_issues`, same matching as the page detail view) | `filter ∈ all,orphans,broken,noindex,deep` |
| `GET /site-audit/page?url=` | `pageLinkStructure()` + `pageIntel()` + `pageFindings()` | 404 `not_crawled` when URL not in inventory; 422 without `url` |
| `GET /site-audit/links?url=` | `pageLinkStructure()`; no `url` → `topInboundPages(8)` as `suggestions` | link-explorer payload |

Pagination is `simplePaginate` (`{data,current_page,per_page,has_more}`) — counts come
from the cached breakdowns, never a COUNT(*) per request. Tests:
`tests/Feature/Api/V1/PluginCrawlApiTest.php`.

## Keyword Finder — `PluginKeywordFinderController` (2026-07-10)

Async discovery/volume over the self-hosted fleet (see `infra/keywords/keyword-finder.md`).
Mirrors the portal Livewire orchestration: cache-first, dispatch via `KeywordFinderPool`,
caller polls. Same `hq`-flag gate.

| Method · path | Does | Notes |
|---|---|---|
| `POST /keyword-finder/ideas` | seeds (≤20) or `url`+`scope` mode; `KeywordIdeasMonthlyCache` hit → results inline (`from_cache`), else `dispatchIdeas` (`website_id` = token website) → 202 `{request_id,status}` | invalid location/language silently fall back to United States/English |
| `POST /keyword-finder/volume` | keywords ≤100; fresh `keyword_metrics` (30 d, gkp) served inline; only misses dispatch (`dispatchIdeas` seeds-mode with `countryKey`) | |
| `GET /keyword-finder/requests/{requestId}` | poll — 404 unless the row's `website_id` matches the token website; completed ideas rows come back normalized (`keyword,volume,competition_index,comp_level,low_bid,high_bid` — raw Ads bids, never $ projections); `?keywords=` re-reads `keyword_metrics` for volume-style rows | completed ideas polls warm the monthly cache with a **server-recomputed** key (`KeywordIdeasMonthlyCache::key($req->mode,$req->payload)`) — never client-supplied (cache-poison risk) |

**Fleet is capacity-constrained** (single-tab nodes): real dispatches are limited to
10/website/day via `RateLimiter` key `plugin-kwf:{websiteId}` (86400 s decay) → 429
`rate_limited`; cache hits are free. Provider off (`keyword.volume_provider` ≠
`keyword_finder`) → 503 `unavailable`. Tests:
`tests/Feature/Api/V1/PluginKeywordFinderApiTest.php` (both suites seed `PlanSeeder` —
factory users resolve to the trial plan row, which must exist for `hq` to be true).

## GSC/GA degradation

Endpoints inherit EBQ's "handle all four GSC/GA presence combos" rule (see project
memory `gsc-ga-degradation`). GSC-dependent writes guard explicitly:
`index-status/submit` returns `not_connected` when `gscAccountResolved()` is null
(`:900`); `recheck` returns early when `gsc_site_url` is empty (`:985`). Own-keyword
rank tracking works without GSC. Don't assume GSC presence in new endpoints.

## Gotchas

- **`api_website` is the only tenancy source** — resolve it via `website()`; never read
  a `website_id` from the request.
- **`hq:overview` is the only request-path cache.** Everything else hits the DB live
  (cheap, GSC tables are pre-aggregated). The cache key embeds
  `ReportCache::version($websiteId)`, so any version bump silently invalidates it — you
  do **not** delete the key directly.
- **Page-audit report URLs force the public root** (`config('services.ebq.public_url')`)
  before signing and restore it in a `finally` (`:1439`) — otherwise the signed URL
  would carry the internal app URL and fail signature check from WP-admin.
- **External-call endpoints can 502/500** — `index-status/submit|recheck` hit Google
  synchronously; they surface `needs_google_reconnect` so the plugin can prompt a
  reconnect rather than retry blindly.
