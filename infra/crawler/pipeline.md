# Crawl pipeline

How a domain gets crawled, end to end. All jobs run on the `crawl` queue (see
[deployment-and-queues.md](../deployment-and-queues.md)). Everything is keyed by
`crawl_site_id` (see [architecture.md](./architecture.md)).

## Flow

```
CrawlWebsitePagesJob(websiteId)
  ├─ resolve website → crawl_site  (create+link if missing)
  ├─ bail if crawl_site already crawling   (ShouldBeUnique: "crawl-site-{id}")
  ├─ create crawl_runs row (status=running)
  ├─ CrawlFrontierBuilder::build(crawlSite)   ── seeds website_pages frontier
  └─ dispatch CrawlPassJob (pass 1)
        │
        ▼  (multi-pass loop)
CrawlPassJob(runId, crawlSiteId, pass)
  ├─ maxPages = crawlSite.effective_cap        (re-read every pass)
  ├─ stop → AnalyzeSiteJob   if pass cap hit, budget hit, or nothing due
  ├─ select due pages, value-ordered, limited to remaining budget
  └─ Bus::batch(CrawlPageBatchJob…)
        └─ .finally → dispatch CrawlPassJob(pass+1)
              │
              ▼
CrawlPageBatchJob(pageIds, runId)
  └─ PageCrawlProcessor::process(page, crawlSite) per page
        ├─ conditional GET (etag / last-modified) + anti-block proxy policy
        ├─ classify (2xx / 3xx / 4xx-5xx / blocked) + analyze HTML
        ├─ SimHash change detection → adaptive next_crawl_at
        └─ rebuild outbound internal-link edges + stub pages for new links
              │
              ▼  (final pass)
AnalyzeSiteJob(runId)
  ├─ block rollup → if site wholesale-blocked: crawl_blocked finding + abort
  ├─ status → finalizing  ("computing your results")
  ├─ SiteGraphAnalyzer   → inbound counts, click depth (BFS), value_rank
  ├─ SiteIssueDetector   → findings catalog (shared, impact=0, base severity)
  ├─ InternalLinkSuggester → suggested edges (shared; term-overlap)
  ├─ scorePages          → page_score + site health_score
  ├─ resolveStale        → close findings not re-seen this run
  ├─ bridge404s          → per-subscriber MatchRedirectFor404Job
  └─ status → completed  + flush ReportCache for ALL subscribers
```

## The multi-pass design (why it exists)

A single pass only crawls the pre-selected frontier (GSC ∪ sitemaps). The homepage
links to category pages that aren't in the frontier; without crawling them the link
graph is disconnected and everything looks like a false orphan / "too deep". So
each page's `rebuildEdges` creates **stub** `website_pages` rows for discovered
links (due immediately), and the **next pass** crawls those. The loop ends when a
pass finds nothing new or the per-run page budget (`effective_cap`) is reached.

**Fairness — `crawler.pages_per_pass` (default 1000).** Each pass crawls at most this
many pages, then dispatches the next pass to the **back** of the queue. This stops one
large site from enqueuing its whole frontier in a single `Bus::batch` and starving every
other site on the shared `crawl` queue — concurrent crawls interleave (round-robin). The
old `crawler.max_passes` count is deprecated; `CrawlPassJob` derives its own runaway
ceiling from `ceil(maxPages / pages_per_pass) * 2 + 50`, so total pages are bounded only
by the cap, not the pass count.

## The value ordering (capped crawls buy what matters)

`CrawlValueRank::order()` — `source_gsc desc → source_sitemap desc → CHAR_LENGTH(url)
asc → id`. A capped crawl fetches the highest-value pages first, and the same
ordering is persisted as `value_rank` so each user's cap window is consistent with
what was crawled.

## Incremental / adaptive recrawl

- Conditional GET (etag + last-modified) → `304` skips re-download.
- `content_simhash` + a distance threshold → noise-tolerant change detection.
- `consecutive_unchanged` → geometric backoff of `next_crawl_at` (stable pages
  recrawl less often; changing pages more often).
- Sitemap `<lastmod>` trust is learned per domain (`sitemap_lastmod_true/false` on
  the crawl_site) and used by `CrawlSitemapDeltaJob` to pull genuinely-updated pages
  forward without waiting for the weekly recrawl.

## Frontier (shared across subscribers)

`CrawlFrontierBuilder::build(crawlSite)` unions, across **all** subscriber websites:
their GSC pages + every `<loc>` in their sitemaps, plus always the canonical
homepage (`https://{normalized_domain}`) so a domain-only site still gets crawled.
`source_gsc` becomes an aggregate flag. New rows are due immediately.

### ⚠️ Scope containment — a crawl must never leave its own domain

Three places create `website_pages` rows, and **all three** filter through
`DomainName::urlBelongsToSite($url, $crawlSite->normalized_domain)` (site itself
or a subdomain):

| Where | File |
|---|---|
| discovered links | `PageCrawlProcessor::rebuildEdges()` (`app/Services/Crawler/PageCrawlProcessor.php:366`) |
| GSC + sitemap seed | `CrawlFrontierBuilder::build()` (`app/Services/Crawler/CrawlFrontierBuilder.php:88`) |
| daily sitemap delta | `CrawlSitemapDeltaJob` (`app/Jobs/CrawlSitemapDeltaJob.php:95`) |

**Why (2026-08-16 incident).** `PageCrawlProcessor` anchors relative links to the
**post-redirect** URL — correct, and load-bearing for apex→www sites. But
`HtmlAuditor::links()` (`app/Support/Audit/HtmlAuditor.php:234`) calls a link
"internal" when it matches the host of the **document it parsed**. Combine the
two and one off-domain redirect hands the crawler a foreign site:
`serfix.io/auth/google/sso` 302s to `accounts.google.com`, so every link on
Google's page counted as internal, got a page row under serfix.io's crawl site,
and went back into the frontier. Result: **194 of serfix.io's 234 pages were
policies.google.com**, surfacing to the client as 164 unfixable "broken internal
links"; facebook.com's crawl held **15,508** off-domain pages of 22,603.

`HtmlAuditor`'s per-document rule is *right for a standalone page audit* — do not
change it there. The crawl-side boundary is the filter above.

A batch whose crawl site has been GC'd mid-run also writes nothing
(`CrawlPageBatchJob`) — see [known-issues.md](./known-issues.md).

## Scheduling

`ebq:crawl-websites` (cron) iterates **crawl_sites** (not websites):
- default: weekly cadence (no run in 7 days);
- `--backfill`: never-crawled crawl_sites only (run after deploy);
- `--sitemap-deltas`: daily, dispatch `CrawlSitemapDeltaJob`.
It dispatches `CrawlWebsitePagesJob` via a representative subscriber website.

`ebq:crawl-supervisor` (cron, **every 5 min**) is the watchdog — see Reliability.

## Reliability notes

- `CrawlWebsitePagesJob` / `CrawlPassJob`: `tries = 1` (no blind retries of a whole
  crawl). `AnalyzeSiteJob`: `timeout = 1200s`, and its `failed()` finalizes the run
  (`completed`) instead of leaving it stuck "running" — important on very large
  sites where analysis can exceed the limit.
- **The wedge (important).** The multi-pass loop continues *only* via the `Bus::batch`
  `->finally()` callback. If a worker is recycled mid-batch (`queue:work --max-time=3600`,
  or any container restart) that callback can be lost, so the next pass / `AnalyzeSiteJob`
  is never dispatched and the run sits in `running` forever (observed: several domains
  wedged for hours). **`ebq:crawl-supervisor`** (every 5 min) recovers them: a `running`
  run whose row hasn't been touched in `crawler.stall_minutes` (default 10) with work
  still due is **resumed** (dispatch next pass) or **finalized** (`AnalyzeSiteJob`); past
  `crawler.max_run_hours` (default 6) it's force-finalized. Active runs touch their row
  every batch, so they're never falsely flagged.
- `retry_after` (`REDIS_QUEUE_RETRY_AFTER=1320`) must exceed the longest job
  timeout, or a long crawl is re-dispatched while still running.

## Findings & scoring

The finding catalog (categories, types, severity rules, referrer attachment) and the
health-score formula have their own doc — see
[findings-and-scoring.md](./findings-and-scoring.md).

## Known issues

Verified gaps and pressure points (cap-window leak, unbounded link reads, large-site
limits) are tracked in [known-issues.md](./known-issues.md).
