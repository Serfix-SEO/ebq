# Adjacent systems

Systems that sit next to the crawler and how they connect to the shared crawl. The rule of
thumb: **page/link/finding data is per-crawl_site (shared); anything tied to a user's own
Google traffic stays per-website.**

## Sitemaps

- **Discovery is per-website.** `SyncSitemaps` (queue `sync`) reads each website's GSC
  sitemap list into `website_sitemaps` (keyed by `website_id`). No crawl trigger.
  Two GSC scoping traps handled since 2026-07-10: (1) `sitemaps.list` only returns
  SUBMITTED sitemaps — `SearchConsoleService::listSitemaps` re-queries each
  `is_sitemaps_index` entry with the `sitemapIndex` param (BFS, 25-index cap) to pull
  children; (2) the list is scoped to ONE property — a site stored under a narrow
  URL-prefix property (falik.com was `https://falik.com/en/`) misses sitemaps submitted
  on a broader property of the same domain, so `SyncSitemaps::candidateProperties()`
  merges every accessible account property matching the website's domain (sc-domain: or
  same host, www-tolerant), stored property's rows winning duplicates.
- **The frontier unions sitemaps across all subscribers** (`CrawlFrontierBuilder`);
  `source_sitemap` on a shared page = "in ANY subscriber's sitemap".
- **`CrawlSitemapDeltaJob`** (daily, queue `crawl`, unique per crawl_site) extracts sitemap
  URLs, seeds genuinely-new ones into `website_pages` (crawl_site-keyed, due now), updates
  `sitemap_lastmod`, and — if the domain's lastmod is trusted — pulls changed pages forward.
  It then dispatches `CrawlWebsitePagesJob($website->id)`; the unique lock collapses multiple
  subscribers to one crawl (see [known-issues.md](./known-issues.md)).
- **Lastmod trust is per-crawl_site.** `sitemap_lastmod_true/false` live on `crawl_sites`,
  incremented atomically in `PageCrawlProcessor` per fetch outcome. Trusted when ≥20 samples
  and ≥30% predictive. `CrawlSitemapDeltaJob` checks this before early-recrawling.

## Incremental / change detection

Per-page, shared. `PageCrawlProcessor` computes `content_simhash`; if the Hamming distance
from the previous simhash exceeds `crawler.simhash_threshold` (default 3) the page is
"changed" (resets `consecutive_unchanged`), else unchanged (increments it). `next_crawl_at`
backs off geometrically — `min_days * 2^consecutive_unchanged`, clamped `[3, 30]` days.
Conditional GET (etag/last-modified → 304) skips re-download entirely.

## Redirects / 404 bridging — per-website

`redirect_suggestions` and `AiRedirectMatcherService` are tied to the user's own GSC traffic
and redirects, so they stay **per-website**:
- `AnalyzeSiteJob::bridge404s` reads the shared open `broken_internal`/`broken_page` findings,
  then dispatches `MatchRedirectFor404Job(website_id, path, …)` **per subscriber**.
- `AiRedirectMatcherService` builds candidate inventory from that website's **own**
  `SearchConsoleData` (top ~200 URLs by clicks) and writes `redirect_suggestions` keyed by
  `website_id`. Each user gets redirect suggestions weighted by *their* traffic.

## Crawl-completion email — per-website (2026-07-07)

`AnalyzeSiteJob` dispatches `SendCrawlReportEmailsJob(crawlSiteId, runId)` from its clean-
completion path only (not the blocked/aborted `return`, not the enrichment-`catch`, not
`failed()`) — findings/health must be finalized first. The job loops
`crawlSite->websites()` (**not** crawl_site-level — each subscriber gets their own capped,
per-user report via `CrawlReportService::emailReportPayload()`, same as the admin Marketing
panel's manual send) and:
- skips a website with no owner email, or **0 open findings** (product decision: a
  "nothing's wrong" email isn't worth sending — matches the Marketing panel's own listing
  filter of open-findings-only sites).
- sends `CrawlReportMail` (health score, issue counts, top-3 examples per category, 28d
  GSC/GA headline traffic if connected) and logs a `CrawlReportSend` row with
  `sent_by_user_id = null` (the admin sends view already renders `sentBy?->name` as blank,
  so automatic vs manual sends are distinguishable for free, no schema change).
- Fires on **every** completed crawl, including auto-recrawls (adaptive 3–30d cadence per
  the Incremental / change detection section above) — deliberate, not throttled; the
  recrawl cadence itself is the natural rate limit.
- `CrawlReportService::emailReportPayload()`/`emailTraffic()` are the shared implementation
  — `MarketingController::send` (manual) and this job both call into the service now; don't
  re-duplicate the report-building logic in either caller again.

## Term extraction & link suggestions — shared

- `PageCrawlProcessor` extracts weighted significant terms (title/slug-boosted TF + bigrams)
  into `website_pages.content_terms` during the crawl (language-agnostic, no stopword lists).
- `AnalyzeSiteJob` builds a sampled document-frequency table over the crawl_site, then
  `InternalLinkSuggester` scores topical overlap (TF-IDF) and writes `suggested`
  `website_internal_links` (orphans/deep pages ← authority pages, ≤3 per target). All shared —
  suggestions are a pure function of the shared crawl.
- `CRAWLER_PRUNE_BODY_TEXT` (opt-in) trims `body_text` to an excerpt **after** analysis.

## Plugin API — safe

`PluginHqController` reads **no crawl tables directly**. Its endpoints read
`SearchConsoleData` / `RankTrackingKeyword` / `PageIndexingStatus`, and its insights call
`ReportDataService` (which is GSC-scoped or routes through `CrawlReportService`). Per-website
Sanctum token → inherits the same scoping. No leak surface here.

## AI — scoped via the read service

`Services/Ai/ContextBuilder` pulls crawl signals only through
`CrawlReportService::pageIntel()` (cap window + overlay + per-user impact).
`AiContentBriefService` uses GSC-clicked URLs, not raw crawl tables.

## Verified unaffected by the shared-crawl change

`DetectTrafficDrops`, `SyncPageIndexingStatus`, `GenerateAiInsights`, and the Pages tables
read GSC / indexing data, **not** the crawl tables — re-verify if they ever start reading
`website_pages`/`crawl_findings` directly.
