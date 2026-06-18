# Known issues & gaps

Verified against the current code (2026-06-16, shared-crawl deep-dive). These are real
behaviours to be aware of — some are bugs to fix, some are accepted trade-offs.

## Bugs / inconsistencies

### Cap-window leak — `LinkStructurePanel::render` (~lines 68-72)
The Link Structure panel's "example pages" picker reads the top-8 pages by
`inbound_link_count` **across the whole crawl_site**, via a raw `WebsitePage` query, with
**no `value_rank <= cap` filter**:

```php
\App\Models\WebsitePage::where('crawl_site_id', $website->crawl_site_id)
    ->whereNotNull('last_crawled_at')->whereNull('removed_at')
    ->orderByDesc('inbound_link_count')->limit(8)->pluck('url');
```

Every other read path enforces the cap window; this one doesn't, so a small-cap user can be
shown example URLs outside their window. **Severity: low** (only suggestion URLs in an input
helper, not data/metrics). **Fix:** source the examples through `CrawlReportService`
(cap-filtered) or add `->where('value_rank', '<=', $cap)`.

## Accepted trade-offs (intentional, documented so they're not "discovered" as bugs)

### Unbounded-by-cap link reads
`CrawlReportService::bfsParents` (homepage→page BFS for orphan/path detection) and
`pageLinkStructure` (a page's inbound + outbound, ≤500 each) read the **full** link graph
without the cap window. The *page* in question is cap-checked; its neighbours are not.
Accepted because correct structure/orphan detection needs the whole graph. Revisit only if
it ever surfaces cross-cap URLs directly to a user.

### Sitemap-delta dispatch is per-website
`CrawlSitemapDeltaJob` dedupes per crawl_site (its `uniqueId`), but then triggers
`CrawlWebsitePagesJob($website->id)` for the subscriber that saw the delta. If several
subscribers' deltas fire, several dispatches occur — but the `crawl-site-{id}`
`ShouldBeUnique` lock + the start-lock collapse them to **one** crawl. Safe, just slightly
redundant dispatch.

### Analysis is best-effort
If `AnalyzeSiteJob` throws partway, its `failed()` handler still marks the run `completed`
(so it isn't stuck `running`), but findings / score / link graph may be partial. The run's
`notes` records the failure. Chosen over leaving runs wedged on huge sites.

## Reliability

- **Wedge (mitigated, not eliminated).** The multi-pass loop continues only via the
  `Bus::batch` `->finally()` callback; a worker recycle/restart mid-batch can drop it, leaving
  a run stuck `running`. **`ebq:crawl-supervisor`** (every 5 min, `stall_minutes` default 10)
  resumes/finalizes such runs — so a wedge self-heals within ~10 min rather than never, but the
  pipeline now *depends on* the watchdog. The supervisor is time-based, so a run whose next
  pass is merely queued behind a slow backlog can be re-dispatched (a harmless duplicate
  upsert). See [pipeline.md](./pipeline.md) §Reliability.
- **Slow fetch throughput.** Observed ~1.5 pages/sec under load (≈90s per 25-page batch),
  dominated by per-page fetch latency (proxy `on_block` retries + politeness delay + target
  speed), not the pipeline. It's the biggest lever on "big sites take a long time" and is
  un-investigated.

## Large-site pressure points

- **In-memory graph BFS** — `SiteGraphAnalyzer` loads the adjacency list into memory for
  inbound-count + click-depth BFS (O(V+E)). Assumes the link graph fits in RAM. As of
  2026-06-18 the adjacency is **integer-indexed** (dense int ids, not 26-char ULIDs) and built
  in a **single edge pass**, and the BFS uses a **pointer queue** (not `array_shift`, which is
  O(n²)) — this is what lets a ~1.5M-edge / ~168k-page site (xplate) finalize in budget.
- **`AnalyzeSiteJob` 1200s timeout** — repeated analyze timeouts on very large sites are the
  known "crawls not finishing" failure mode. `retry_after` (1320s) sits just above it.
  `tries=2` (since 2026-06-18) so one transient failure no longer strands the run.
- **Referrer / external-link sampling** — broken-referrer sampling (5/target, ~50k edge cap)
  and external-link checking (≤25/page, ≤500 total) are bounded, so on pathological fan-in or
  link-heavy pages the coverage is partial by design.

### 2026-06-18 incident — finalize failed on large sites (1205 lock-wait + finalize loop)
Two `AnalyzeSiteJob`s failed (`MaxAttemptsExceeded`) on 39k- and 168k-page sites; one site
was left stuck in `finalizing`. Two compounding causes, both fixed:
1. **Table-wide UPDATEs in `SiteGraphAnalyzer`** — `recomputeInboundCounts` did a whole-site
   `UPDATE … JOIN` (+ whole-site reset) and `recomputeClickDepth` a whole-site reset. On
   40k–168k-row sites these held InnoDB row locks long enough to trip
   `innodb_lock_wait_timeout` (**SQLSTATE 1205**) — because finalize **contends with live
   `CrawlPageBatchJob` writes** to the same `website_pages` rows. **Fix:** both passes now
   compute in PHP (keyset stream) and write in **bounded id-keyset chunks**
   (`resetColumnChunked` / `writeGroupedChunked`). `CrawlValueRank::assign` was already chunked.
2. **Supervisor finalize loop / overlapping finalizes** — `CrawlSupervisor` re-dispatches
   `AnalyzeSiteJob` for any stalled `FINALIZING` run, but a slow-but-alive finalize (≤1200s)
   doesn't bump `updated_at`, so a **second concurrent finalize** could start and fight the
   first for the same row locks. **Fix:** `AnalyzeSiteJob` now carries
   `WithoutOverlapping('analyze-site:'.$crawlRunId)->dontRelease()->expireAfter(1500)` — the
   duplicate is dropped, the lock auto-expires if a holder dies hard. With `failed()` finalizing
   the run after `tries=2`, the loop is broken.

> Upstream contributor (crawl side, not yet fixed): the 50 sibling `CrawlPageBatchJob`
> **timeouts** that day broke `Bus::batch` completion and left pages selected-but-unfetched.
> `pages_seen` is incremented at **selection** (`CrawlPassJob.php:117`), not at successful
> fetch, so a run can hit its page cap with pages never actually crawled — the crawl looks
> "done at cap" while incomplete.

## Transitional cruft

- The four crawl tables still carry a **nullable, FK-dropped, unused `website_id`** column
  (safe-rollback leftover from the re-key). Nothing reads/writes it. A future migration can
  `dropColumn('website_id')` once we're confident — not urgent.
- `websites` keeps legacy `sitemap_lastmod_true/false` + `crawl_protection*` columns; the
  canonical copies now live on `crawl_sites`. The `Website` helpers delegate to the crawl_site.
