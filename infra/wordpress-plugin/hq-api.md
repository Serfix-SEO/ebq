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
