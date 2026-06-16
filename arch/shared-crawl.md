# Shared crawl store

> One crawl per normalized domain, shared across every user who adds it, read
> through a per-user cap-limited + privacy-scoped view.

## Why

Previously each `Website` got its own crawl. A domain added by N users was
fetched N times and re-fetched on N schedules — wasteful and slow, and it hammered
the target site. The shared store crawls each **domain** once.

## Core entity: `crawl_sites`

One row per **normalized domain** (`CrawlSite::normalizeDomain()` lowercases and
strips scheme, `www.`, and path — so `https://www.Basepaws.com/` and
`basepaws.com` collapse to `basepaws.com`).

Columns: `normalized_domain` (unique), `effective_cap` (max page cap among
subscribers), `health_score`, `status` (`pending|crawling|ready|blocked`),
`subscriber_count`, and the domain-level crawl signals moved off `websites`:
`crawl_protection` / `crawl_protection_at`, `sitemap_lastmod_true/false`.

`websites.crawl_site_id` links many users' websites to one crawl_site. The link is
created/maintained automatically by a `Website::saved` hook
(`firstOrCreate` by normalized domain), so **every website always has a crawl_site**.

## Data model

```
                         ┌──────────────┐
   many users' websites  │  crawl_sites │  one per normalized domain
   ─ websites.crawl_site_id ─▶│  (effective_cap, health, protection…) │
                         └──────┬───────┘
        ┌───────────────┬───────┴────────┬────────────────┐
        ▼               ▼                ▼                ▼
 website_pages   website_internal   crawl_runs       crawl_findings
 (+ value_rank)  _links             (one per crawl)  (shared catalog,
        ▲               ▲                                 impact = 0)
        └─ page_id / from_page_id / to_page_id ────────────┘

 per-user overlay:  website_finding_states (website_id, finding_id, status)
```

All four crawl tables are keyed by `crawl_site_id`. The `website_id` column still
exists (nullable, no FK) for transition but is unused by application code.

## Shared vs per-user split (the crux)

**Shared** (stored once on the crawl_site):
- The full page inventory (URL, status, content, hashes, link counts, click depth,
  `source_sitemap`, `page_score`, **`value_rank`**).
- The internal-link graph — **including AI suggestions** (`InternalLinkSuggester`
  uses only `content_terms` + `inbound_link_count`, no per-user input).
- `crawl_runs`.
- The **finding catalog** (category, type, affected_url, detail, page_id) with a
  click-independent base severity. `impact` is stored as **0**.
- `source_gsc` on a page is an **aggregate** ("any subscriber's GSC has this URL"),
  used only for value ordering.

**Per-user** (never shared / computed read-time):
- **Impact** (clicks-at-risk) — computed from the requesting website's own
  `SearchConsoleData` at read time (`CrawlReportService::loadUserClicks`).
- **Finding status** (open / ignored / resolved) — `website_finding_states` overlay.
- A user's own GSC / GA / keywords / rank tracking — untouched, still per-website.

This split is what lets the crawl be shared without leaking one user's traffic
data to another.

## Cap windowing — `value_rank`

After each crawl, `SiteGraphAnalyzer` (via `CrawlValueRank::assign`) writes a dense
`value_rank` (1..N) over the crawl_site's live pages in the **value ordering**
(`source_gsc desc → source_sitemap desc → shorter URL → id`). The exact same
ordering drives which pages a capped crawl fetches first (`CrawlPassJob`), so the
two can't drift.

Every read resolves the website → `crawl_site_id` + the owner's
`crawlPageCap()`, and filters `value_rank <= cap` (nulls treated as in-window).
So a cap-1000 user sees the top 1,000 pages; a cap-100k user sees up to 100k — of
the **same** crawl. Verified: with 3 ranked pages, a cap-2 user sees 2, a cap-1000
user sees 3.

## Write path (one crawl per domain)

`CrawlWebsitePagesJob(websiteId)` resolves the website's crawl_site, **bails if a
crawl is already running** for it (the `isCrawling()` check + run creation are
wrapped in a short atomic `Cache::lock('crawl-site-start-{id}')` so two
near-simultaneous dispatches can't both create a run), creates a `crawl_runs` row
keyed by `crawl_site_id`, builds the frontier, and hands off to the multi-pass loop. Cap =
`crawlSite->effective_cap` (re-read each pass, so a higher-cap subscriber joining
mid-crawl extends the in-flight crawl). See [crawl-pipeline.md](./crawl-pipeline.md).

Findings are upserted by `(crawl_site_id, type, affected_url_hash)`. Clicks are
aggregated across **all** subscribers only to decide whether a click-conditional
finding exists (e.g. `noindex_important`); the stored impact stays 0.

## Read path

`CrawlReportService` is the single read surface (Site Health, action queue, Link
Structure, growth + marketing reports, AI context). Every method:
1. `context(websiteId)` → `{crawl_site_id, cap, clicks[]}` (memoized per request).
2. Filters findings/pages to the cap window (`value_rank <= cap`, or page-null
   site-level findings like `crawl_blocked`).
3. Excludes findings the user ignored/resolved (`website_finding_states`).
4. Computes per-user impact from `clicks[affected_url_hash]`.

The shared link-graph BFS cache is keyed on `crawl_site_id`; `ReportCache`
(per-user report payloads) stays per-website and is flushed for **all** subscribers
when a crawl finalizes.

## Lifecycle

- **Subscribe / charge** — `CrawlSiteBootstrapper::subscribeWebsite()` (called from
  both add flows: `ConnectGoogle`, `WebsitesList`): logs a `crawl_reuse` usage
  charge (`units_consumed = min(crawled pages, cap)` — visibility only, no enforced
  budget yet) and dispatches a crawl **only if** no completed crawl exists or this
  user's cap exceeds what's already crawled. If a fresh shared crawl already covers
  the cap → **instant reuse, no crawl**.
- **Live progress** — `CrawlBanner` shows `min(pages_seen, cap)/cap` so a cap-1000
  user sees `500/1,000` while a cap-100k user sees `500/100,000` for the same run.
  `CrawlRun` has a `finalizing` state ("computing your results") between fetch and
  scoring; Site Health shows partial results with a notice during the crawl.
- **Delete / GC** — a `Website::deleted` hook detaches and recomputes
  `effective_cap`; when the **last** subscriber leaves it GCs the crawl_site and its
  data. The crawl tables' `website_id` cascade FK was dropped so deleting one
  subscriber never touches the shared crawl.

## Key files

| Concern | Files |
|---|---|
| Entity + normalization | `app/Models/CrawlSite.php`, `app/Models/Website.php` (hooks, delegated crawl helpers) |
| Cap ordering | `app/Support/Crawler/CrawlValueRank.php` |
| Write path | `app/Jobs/CrawlWebsitePagesJob.php`, `CrawlPassJob.php`, `CrawlPageBatchJob.php`, `app/Services/Crawler/{CrawlFrontierBuilder,PageCrawlProcessor,SiteGraphAnalyzer,SiteIssueDetector,InternalLinkSuggester}.php`, `app/Jobs/AnalyzeSiteJob.php` |
| Read path | `app/Services/Crawler/CrawlReportService.php` |
| Lifecycle | `app/Services/Crawler/CrawlSiteBootstrapper.php`, `app/Models/WebsiteFindingState.php` |
| Schema | `database/migrations/2026_06_17_0000*..0003*` |
| Tests | `tests/Feature/SharedCrawlTest.php`, `tests/Unit/CrawlSiteNormalizeDomainTest.php` |
