# Known issues & gaps

Verified against the current code (2026-06-16, shared-crawl deep-dive). These are real
behaviours to be aware of — some are bugs to fix, some are accepted trade-offs.

## Bugs / inconsistencies

### Cap-window leak — `LinkStructurePanel::render` — FIXED 2026-07-06
The Link Structure panel's "example pages" picker used to read the top-8 pages by
`inbound_link_count` **across the whole crawl_site** via a raw `WebsitePage` query with no
`value_rank <= cap` filter, so a small-cap user could be shown example URLs outside their
window. Fixed: examples now come from `CrawlReportService::topInboundPages()` (windowed via
the same `windowPages()` every other read path uses). Regression test:
`tests/Feature/LinkStructureExamplesCapTest.php`.

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

## Exports & reporting

### Site Audit PDF export (added 2026-06-23)
`GET /site-audit/download` (`SiteAuditExportController`, `feature:link_structure` +
`throttle:10,1`) renders the crawler's full sitewide finding catalog as a branded PDF — the
crawl-data equivalent of Semrush's "Site Audit: Issues" export, which was used as the
reference for structure (Errors/Warnings/Notices tiers, per-issue "About this issue" + "How
to fix" copy) but deliberately exceeded in a few ways:
- **Real sample affected URLs** per issue type (capped 10 + a "+N more" line) — Semrush's
  own static export shows bare counts only, no URLs.
- **Health score with a letter grade** (A–F, `CrawlReportService::healthGrade()`) up front
  — a bare 0–100 number means little to a non-technical reader on its own.
- **"New this week" badge** per issue type (`first_seen_at >= now()-7d`), mirroring the
  small delta sub-number Semrush shows under its own "Amount" column.
- **"Start here" priority shortlist** — top 5 critical/high issues ranked by volume, shown
  before the full Errors/Warnings/Notices breakdown, so the reader isn't left to guess which
  of ~30 issue types to tackle first.

Source data: `CrawlReportService::auditExport($websiteId)` — one row per finding TYPE
across **all** categories (not scoped to one category like `typeBreakdown()`), bucketed by
severity tier (critical/high → errors, medium → warnings, low → notices) — paired with
`auditAbout()` (new, "why this matters" copy for all ~37 types) and the existing
`fixGuidance()` ("what to do"). GSC-sourced types (`isGscSourced()`) get the same amber
caveat note as the dashboard/Page Health views (crawl-only-over-GSC-gating principle,
documented above under "crawler findings must stand on crawl data alone").

Rendering: `CrawlAuditPdfRenderer` (dompdf, mirrors the pre-existing `ReportPdfRenderer`
used for the Growth Report email PDF) → `resources/views/pdf/site-audit.blade.php` +
`pdf/partials/site-audit-section.blade.php`.

**Whitelabeling reuses the existing system as-is** — no new feature flag was needed.
`ReportBranding`/`ReportBrandingResolver` (plan-gated on `report_whitelabel`, currently true
only on the Agency plan — see `PlanSeeder`) already existed for the Growth Report PDF; this
export just calls the same resolver. `?whitelabel=0` lets an eligible user pull the plain
EBQ-branded copy on demand instead (e.g. to send to EBQ support) — the resolver itself
still enforces the plan gate either way, so a non-eligible plan always gets EBQ branding
regardless of this query param.

UI entry point: an "Export PDF" button on the dashboard's Priority Action Queue widget
(`resources/views/livewire/dashboard/priority-action-queue.blade.php`) — plain `<a href>`
download links, not a Livewire action, since file downloads need a real navigation.

### Post-crawl aggregates now cached until the next crawl (added 2026-06-23)
`actionGroups()`, `typeBreakdown()`, `categoryFindings()`, and `auditExport()` were
re-running their full queries (`actionGroups()` in particular does a `chunk(2000)` scan over
every open finding just to sum per-user click impact) on every page load — slow on sites
with a lot of open findings. Wrapped them in `CrawlReportService::remember()`, a thin
`Cache::remember()` keyed by `ReportCache::version($websiteId)` (24h sanity TTL; real
freshness comes from the version bump). `AnalyzeSiteJob::flushSubscribers()` already bumps
that version at the end of **every** run outcome (completed/blocked/failed/exception), so
this needed no new invalidation wiring — it also gets bumped by nightly GSC syncs, which is
a harmless superset (occasionally refreshes sooner than strictly necessary, never stale
past the next crawl).
**Deliberately NOT cached: `summary()`.** Its `run_status`/`blocked` fields reflect the
*current* run's live state (running/finalizing) and drive the crawl-progress banner's
real-time polling — caching it would freeze the banner mid-crawl. `auditExport()` calls
`summary()` directly (not the cached wrapper) since it only reads the post-crawl fields
(health_score/pages_total/last_crawled_at), but the whole `auditExport()` result is itself
cached as one unit, so that's still a single query pass per version, not per request.

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
- **Proxy pool: import vs. health-check are two separate commands (2026-06-20) — don't
  conflate them.** Both live-test via the shared `ProxyPool::testBatch()` (concurrent
  HTTPS GET to `api.ipify.org`, cert verification ON — catches dead nodes AND the rarer
  MITM ones that swap in a self-signed cert; confirmed against a live sample of
  `iplocate/free-proxy-list` + `proxifly/free-proxy-list`, both `scheme://host:port` form).
  1. **`ebq:proxy-list-refresh`** — import-only, never touches already-tracked rows. Pulls
     candidate URLs from free public lists (`--source` repeatable; a failed source just
     warns and is skipped), tests a random `--limit` sample of *new* ones, inserts only the
     passers (label `free-proxy-list (auto)`). Scheduled `everyThirtyMinutes` but **gated
     OFF by default** via `CRAWLER_PROXY_AUTO_IMPORT` (`config/crawler.php` `proxy.auto_import`)
     — the command itself always works regardless of the flag; only the *automatic* firing is
     gated. Manual trigger: `php artisan ebq:proxy-list-refresh`, or the admin
     `/admin/proxies` **"Import now"** button (dispatches `RunProxyListRefreshJob` on the
     `default` queue so the click doesn't block on a multi-source fetch + test pass).
  2. **`ebq:proxy-pool-prune`** — health-check-only, never imports. Tests every
     already-tracked proxy and **deletes it immediately on a single failed test** — no
     fail_count/threshold, each run is a fresh independent check. Scheduled
     `everyFifteenMinutes`, **always on**, NOT gated by `auto_import` (that flag only
     controls new imports; the pool must stay clean even with import off).
  3. **Real-usage failures** — `ProxyPool::markFailure()`, called from actual production use
     (`PageAuditService`/`LinkChecker`'s broken-link checkers, `PageCrawlProcessor`'s
     page-fetch retry) — still deletes after `CRAWLER_PROXY_MAX_FAILURES` consecutive real
     failures, independent of the two scheduled commands above.
  4. **Admin "Retest all"** (`/admin/proxies`) — manual bulk sweep, same delete-on-first-fail
     semantics as the pruner, client-driven (Alpine, **concurrency 1, sequential** as of
     2026-06-23 — was 5; see false-positive bug below).
  Both scheduled commands run only on the web box's scheduler (`schedule:work`), so no
  worker-box (box B) deploy is needed for changes to either — box B only needed redeploying
  because `ProxyPool.php` itself (the shared `testBatch()`/`markFailure()` code) is also used
  by `LinkChecker`/`PageCrawlProcessor`, which DO run there.
  Free proxies are inherently low-trust (unknown operators, can see plaintext HTTP and
  attempt HTTPS MITM) — fine for the crawler's anti-block use case (fetching public pages),
  would NOT be fine for anything carrying credentials/PII.
- **Image/binary assets ran through on-page SEO checks (fixed 2026-06-23).** Image URLs
  reached via image sitemaps (`CrawlFrontierBuilder.php`) or a direct `<a href="...jpg">`
  (`PageCrawlProcessor::rebuildEdges`) were stored as ordinary `website_pages` rows with no
  content-type gate, so a successful 200 fetch sailed through `PageCrawlProcessor::process()`
  straight into `PageAnalyzer::analyze()` — producing `missing_title`/`missing_h1`/
  `missing_meta_description`/`missing_open_graph`/`missing_structured_data`/`thin_content`
  findings against a JPEG (none of which can have an HTML title/H1/etc). `website_pages` has
  no `content_type` column; `CrawlFetcher::fetch()` already captures the response
  `Content-Type` header (`content_type` key) but it was discarded before persistence. Fixed
  by checking `$res['content_type']` in `PageCrawlProcessor::process()` right after the
  status/redirect/block checks (before `analyze()`): non-`text/html` responses are marked
  `is_indexable = false` and skip analysis entirely — `SiteIssueDetector::detectForPage()`
  already early-returns on `! $indexable` (line ~127), so no detector change was needed.
  One-time cleanup: 22 pre-existing image rows (all on one site, found via a URL-extension
  regex sweep across every site — only this one was affected) were set `is_indexable=false`
  and their 33 open false-positive findings marked `resolved` directly (not waiting for the
  next crawl).
- **`ebq:crawl-websites --website=` ULID cast bug (fixed 2026-06-23).**
  `Website::where('id', (int) $single)` — `$single` is a ULID string, not numeric.
  Every current-era ULID starts `01`, so `(int)` always collapsed it to `1`; under MySQL's
  numeric string coercion the column comparison could then match whichever row happens to
  satisfy `= 1`, not the intended site — wrong-site crawl-trigger risk. Same landmine class
  as other `(int)`-on-ULID bugs in this codebase (see [[ulid-formatting-landmines]] in
  memory). Fixed: cast to `(string)` instead. Found while manually re-crawling
  soulfamburger.com (had to dispatch `CrawlWebsitePagesJob` directly to avoid hitting it).
- **No duplicate-title detection existed at all (added 2026-06-23).** Catalog had
  missing/too-long/too-short title checks but nothing comparing titles *across* pages.
  User caught it via a Semrush export: 24 duplicate-title issues on soulfamburger.com
  (8 distinct titles × 3 pages each — the `/ar/`/`/en/`/`/fr/` i18n variants never got
  translated titles) that we silently missed. Added `SiteIssueDetector::detectDuplicateTitles()`
  — groups indexable 200 pages by exact `title`, flags any group >1, severity high/medium by
  clicks. **Gotcha hit during this fix:** the query used `->select('title','id','url',
  'url_hash')` for the group-by pass, which left `crawl_site_id` un-hydrated on the partial
  Eloquent models → `SiteIssueDetector::row(): Argument #1 ($crawlSiteId) must be of type
  string, null given` — silent in the UI, only visible in the **worker box's** log (logs
  for finalize jobs live on 10.0.0.3, not the web box). Fixed by adding `crawl_site_id` to
  the select. Verified live: 24/24 findings now match Semrush exactly.
  **Two-box deploy reminder (re-learned this same session):** code edits only take effect on
  the web box until rsynced to the worker box (10.0.0.3) + container restarted — `docker
  compose up -d` does NOT recreate a container whose compose config is unchanged; use
  `docker restart $(docker ps -q --filter name=ebq)` to force it. See
  [deployment-and-queues.md](../deployment-and-queues.md).
- **Redirect cascade: every page on a site flagged `redirecting_url` (fixed 2026-06-23).**
  soulfamburger.com had 28/28 pages flagged as redirecting (apex `soulfamburger.com` →
  `www.soulfamburger.com`) — not 28 separate issues, one bug repeating. Root cause:
  `CrawlSite::homepageUrl()` always seeds from the bare `normalized_domain` (apex, www
  stripped); the live site force-redirects apex→www on every request. Guzzle auto-follows
  the redirect and fetches the real (www-hosted) HTML — correct — but
  `PageCrawlProcessor::process()` then called `PageAnalyzer::analyze($page->url, ...)` using
  the *original* (pre-redirect, apex) URL as the base for resolving the page's relative
  `href`s, instead of the *effective* post-redirect URL. Every relative internal link on the
  page got re-anchored to the apex host, so each newly discovered link inherited the same
  apex→www redirect — cascading one host-level redirect into a same-finding-per-page flood.
  Fixed by resolving against `$res['redirect_target']` when `$res['redirected']` is true.
  **Not a false-positive cleanup** like the other two entries below — each of the 28
  existing findings is individually true (those URLs really do redirect), so they were left
  open rather than mass-resolved; the fix only stops the cascade going forward. The 28 apex
  rows will keep re-flagging until a fresh crawl populates www-hosted rows behind them (no
  forced re-crawl was triggered as part of this fix — runs on the normal schedule). Separately
  worth knowing: `homepageUrl()` always seeds apex regardless of which host a site treats as
  canonical, so a site with a forced www redirect will always pick up at least one
  `redirecting_url` finding on the homepage itself — that one's expected, not a bug.
- **No hreflang detection at all (added 2026-06-23).** Caught via a second Semrush export
  (`semrush/namesforfreefire.com_hreflang_conflicts_on_page_20260623.xlsx`): 4 i18n pages
  (`/ar/`, `/de/`, `/fr/`, `/it/`) each flagged `No self-referencing hreflang` AND `Conflicting
  hreflang and rel=canonical`. Confirmed by grep: zero hreflang logic anywhere in the
  codebase before this — `HtmlAuditor::localeSignals()` already extracted `<link rel=alternate
  hreflang>` tags (for Serper/locale resolution) but `PageAnalyzer::analyze()` never read
  them. Added:
  - `PageAnalyzer`: calls `localeSignals()`, computes `hreflang_self_ref` (does any hreflang
    entry's href, normalized via the existing `canonicalKey()`, match this page's own URL?),
    returns `hreflangs` + `hreflang_self_ref`.
  - `PageCrawlProcessor::seoSignals()`: persists `hreflang_count`, `hreflang_self_ref`, and a
    capped `hreflangs` sample into the existing `seo_signals` JSON column (no migration).
  - `SiteIssueDetector::detectForPage()`: two new checks, `missing_self_hreflang` (has
    hreflang tags, none self-referencing) and `hreflang_canonical_conflict` (has hreflang
    tags AND `canonical_points_away` is true — telling crawlers two different "correct" URLs
    at once). **Placed before the `if (! $indexable) return` gate**, same spot as
    `canonical_mismatch` — a locale page failing both checks is by definition non-indexable
    (that's the bug), so the gate would otherwise hide it.
  Both new types use `CrawlFinding::CATEGORY_INDEXABILITY` (closest existing bucket; no
  dedicated "international" category exists yet). Not yet cross-checked against the rest of
  Semrush's hreflang catalog (reciprocal/return-link validation, x-default, multiple entries
  for one language) — only the two issue types actually seen in a real export were built.
- **TTFB, redirect-chain length, schema validity, Twitter Cards: captured but discarded
  (fixed 2026-06-23).** Full Semrush-catalog gap sweep (user request, not export-driven)
  found 4 more "data already exists, nothing reads it" gaps — same class as the hreflang
  and content-type misses above:
  - `CrawlFetcher::fetch()` (`CrawlFetcher.php:88/122`) already computes `ttfb_ms` and
    `redirect_chain` (Guzzle redirect-history hop count) on every fetch; `PageCrawlProcessor`
    never read either field before this fix. Note: despite the field name, `ttfb_ms` is
    measured **after the full body downloads**, not after headers — it's really total fetch
    latency, not strict TTFB. Kept the existing name to avoid touching the fetcher.
  - `HtmlAuditor::schema()` already tags each JSON-LD block `'valid' => $decoded !== null`;
    `seoSignals()` only ever collected `type`, silently dropping malformed blocks into the
    same bucket as "no schema at all."
  - `twitter_tag_count` was captured (mirrors `og_tag_count`) but never checked against zero.
  Wired all four through `PageCrawlProcessor::seoSignals()` (now takes `$res` as a 2nd arg)
  into `seo_signals` JSON (no migration), then 4 new `SiteIssueDetector` checks:
  `redirect_chain_too_long` (≥3 hops, `CATEGORY_REDIRECT`), `slow_response` (≥5000ms,
  `CATEGORY_PERFORMANCE` — first real use of that category constant), `missing_twitter_card`
  (`CATEGORY_ONPAGE`), `invalid_structured_data` (`CATEGORY_SCHEMA`, only fires when the page
  has *some* valid schema alongside a broken block — a page with zero valid blocks already
  gets `missing_structured_data`, the two are mutually exclusive by design). Added
  `CrawlFinding::CATEGORY_PERFORMANCE` to `CrawlReportService::CATEGORIES` (had no UI title/
  desc before — category existed in the model but nothing populated it).
- **Issue-detail page mixed every type in one category into one undifferentiated list
  (fixed 2026-06-23).** User asked for a Semrush-style UI: each issue TYPE listed
  separately with its own count, not lumped into the coarser category bucket (e.g.
  "Indexability" silently mixing `noindex_important`, `canonical_mismatch`,
  `missing_self_hreflang`, `hreflang_canonical_conflict` with no visual separation). The
  `/issues/{key}` page (`SiteIssues.php` + `livewire/site-issues.blade.php`) now defaults to
  a grouped-by-type breakdown (new `CrawlReportService::typeBreakdown()` — count + worst
  severity per type, sorted critical→low) with each type as a clickable card
  (`SiteIssues::selectType()`); clicking drills into the existing flat URL-list view (now
  reachable only after a type or search term is chosen) with a "Back to all issue types"
  control. Severity filter still narrows the grouped counts; typing in search bypasses
  grouping entirely (a free-text query implies the user already knows what they're looking
  for). Top-level Priority Action Queue (category-level rows) intentionally unchanged —
  severity ranking across categories is still useful there.
- **No robots.txt audit at all (added 2026-06-23).** The crawler ignores robots.txt by
  design (it must crawl blocked pages too, to be able to *report* the block — same
  rationale Semrush/Screaming Frog use), but nothing ever checked whether a high-traffic
  page is accidentally Disallow'd. Added `App\Support\Crawler\RobotsTxtParser` (pure parser,
  no I/O — groups `User-agent` records, picks the most specific group for our crawler's UA
  falling back to `*`, resolves Allow/Disallow by longest-match-wins with Allow winning
  ties, same precedence Google documents; supports `*` wildcards and trailing `$` anchors).
  `SiteIssueDetector::detectRobotsBlocked()` fetches `{homepage origin}/robots.txt` **once
  per run** via the existing `CrawlFetcher` (reused for its SSRF guard + UA + timeout, not a
  new fetch path). **Deliberately NOT gated on GSC clicks** like `noindex_important` is —
  many subscribers never connect GSC, and gating on it (the first version of this check did)
  meant zero coverage for them. Instead restricted to pages that are sitemap-listed OR have
  inbound internal links — a crawl-only proxy for "the site itself treats this as a real
  page," since intentionally-excluded utility paths (`/admin`, `/cart`, faceted-search
  params) are normally neither. Severity bumps `medium` → `high` when GSC traffic IS
  available, but the finding fires either way. New type `robots_blocked_important` under
  the existing `CrawlFinding::CATEGORY_CRAWLABILITY` (broadened that category's UI
  description — used to mean only "bot-walled," now also covers "robots.txt blocks a real
  page"). Missing/unreachable robots.txt is treated as "nothing blocked," not an error.
- **Cross-page exact-duplicate content not detected (added 2026-06-23).** `content_hash`
  (sha1 of analyzed `body_text`) already existed per-page for change detection, but nothing
  grouped it ACROSS pages — only title/meta_description had this. Added
  `SiteIssueDetector::detectDuplicateContent()`, exact mirror of `detectDuplicateField()`'s
  pattern: groups indexable 200 pages by `content_hash`, flags groups of 2+, severity
  medium/high by GSC clicks (clicks only affect severity, not whether it fires — same
  crawl-only-first principle as the robots.txt check above). Guarded with `word_count > 0`
  — empty `body_text` all
  hash to the same `sha1('')`, which would otherwise mass-flag every fetch/parse-edge-case
  blank page as "duplicate" of every other blank page. New type `duplicate_content` under
  `CATEGORY_ONPAGE`.
- **No mixed-content detection (added 2026-06-23).** Nothing scanned for plain-`http://`
  resource references on an https page (browsers actively block/upgrade these, or at least
  console-warn). Added `HtmlAuditor::mixedContentUrls()` — scans `img/script/iframe/source/
  video/audio` `src` and `link[rel=stylesheet]` `href` for literal `http://` absolute URLs;
  no-ops entirely on http pages (nothing's "mixed" there) and never matches relative or
  protocol-relative (`//host/...`) URLs since those inherit the page's own https scheme.
  Wired through `PageAnalyzer` → `seo_signals` (`mixed_content_count`/`mixed_content_urls`,
  capped sample) → new `SiteIssueDetector` check, type `mixed_content`, first real use of
  `CrawlFinding::CATEGORY_SECURITY` (added its `CrawlReportService::CATEGORIES` entry,
  `sev: high` — browsers actively block these, unlike most `growth`-tier onpage gaps).
- **Sitemap quality only checked one direction (added 2026-06-23).** Only
  `indexed_not_in_sitemap` existed (a GSC-trafficked page MISSING from the sitemap) — never
  the reverse: a sitemap-listed URL that's actually broken, redirecting, or non-indexable.
  Added three new types in `detectForPage()`, each attached right next to the existing check
  for that page state so no extra query pass is needed: `sitemap_broken_url` (next to
  `broken_page`, before its early return), `sitemap_redirect_url` (next to
  `redirecting_url`), `sitemap_noindex_url` (checked against `is_indexable` generally, not
  just the more specific `noindex_important` text reason — canonical-pointing-away also
  makes a page non-indexable and is just as wrong to list in a sitemap). All three are
  click-independent (gate severity only, not existence) — a sitemap shouldn't lie regardless
  of whether the URL has traffic. All under the existing `CATEGORY_SITEMAP`.
- **Click-to-chat/shortlink redirects flagged as `external_redirect` (fixed 2026-06-23).**
  soulfamburger.com's "Order on WhatsApp" links (`wa.me/...`) were flagged as external
  redirects — confirmed via `detail.final_url`: wa.me 302s to `api.whatsapp.com/send/?...`,
  which is wa.me's own documented behavior, not a site bug, not actionable by the owner.
  `SiteIssueDetector.php` flagged *any* redirected external href with no allowlist. Fixed by
  adding `KNOWN_REDIRECTOR_HOSTS` (`wa.me`, `api.whatsapp.com`, `t.me`, `m.me`, `bit.ly`) +
  `isKnownRedirector()` gate before the `external_redirect` finding is raised (the separate
  `broken_external` check — real 4xx/5xx — is untouched, still flags a truly dead
  click-to-chat link). Cleanup: 18 pre-existing open `external_redirect` findings across 3
  sites matched these hosts and were resolved directly.
- **Social-profile links false-flagged `broken_external` (fixed 2026-06-25).**
  lawncarewesleychapelfl.com's `https://x.com/WesleyLawn38331` (a live profile) was flagged
  broken. Root cause: `LinkChecker`/`PageAuditService::checkLinks()` fetch with a
  Googlebot-spoofed UA from the crawler's own (non-residential) IP; x.com (also linkedin.com,
  facebook.com, instagram.com, twitter.com) WAFs routinely 403/429/999/401 that fetch profile
  regardless of whether the link is actually dead — `LINK_FALLBACK_STATUSES` retries with a
  plain GET from the *same* IP/UA, which gets blocked identically, so the false 403 survives
  to the `status >= 400` check. No domain had ever been exempted from `broken_external`
  (the existing `KNOWN_REDIRECTOR_HOSTS` allowlist only suppresses `external_redirect`).
  Fixed in both `SiteIssueDetector::detectBrokenExternalLinks()` and
  `PageAuditService::checkLinks()` (duplicate logic, kept in sync): added
  `KNOWN_ANTIBOT_HOSTS` (`x.com`, `twitter.com`, `linkedin.com`, `facebook.com`,
  `instagram.com`) + `ANTIBOT_BLOCK_STATUSES` (`401,403,429,999`). When a checked link's host
  matches AND its status is one of those, the link is **not** flagged broken (skipped
  entirely, no finding persisted) — logged instead (`crawler.broken_external.antibot_skip` /
  `page_audit.broken_link.antibot_skip`) so the suppression is visible in logs without
  creating noise for the client. A genuine 404/410/500 on these hosts is still flagged
  normally — only the four anti-bot-shaped statuses are exempted. Because findings aren't
  cached/sticky (re-evaluated fresh every `SiteIssueDetector` pass), this self-corrects on
  the next crawl with no separate "recheck" mechanism needed: if x.com starts returning a
  real 404 instead of 403, the next run flags it.
- **Cross-site stale-render bleed in the dashboard (confirmed 2026-06-23, not yet fixed).**
  User saw a soulfamburger.com finding (wa.me redirect) while viewing childdaycaretracy.com's
  crawl report. Ruled out as a data bug — confirmed via direct DB query and
  `CrawlReportService::categoryFindings()` (the exact method the controller calls) that no
  such finding exists, ever, for childdaycaretracy's `crawl_site_id`. **User confirmed a hard
  refresh made it disappear** — client-side stale render, likely the same class of bug as the
  `LinkStructurePanel` cap leak (Livewire/Alpine component not fully replaced when switching
  between two sites' reports without a full reload). Not reproduced/fixed yet — needs a repro
  (switch site A → site B in the admin client-switcher without reload, check if a stale
  finding row survives) before a real fix can be scoped.
- **"Retest all" false-positive deletes (fixed 2026-06-23).** Single "Test" button passed a
  proxy; "Retest all" deleted every proxy in the pool. Root cause: pool entries sharing one
  provider account/credential (rotating-IP "backconnect" gateway — same auth token, same
  port, only the IP octet differs) are capped at the provider side to a small number of
  *concurrent* connections (likely 1). The old `concurrency: 5` Alpine sweep in
  `proxy-manager.blade.php` opened 5 simultaneous CONNECT tunnels through the same account;
  the provider 403'd the excess ones (confirmed via `Http::withOptions(['proxy'=>...])`
  throwing `Illuminate\Http\Client\RequestException` w/ a real 403 response, not a transport
  timeout — Guzzle surfaces a failed proxy CONNECT as a thrown exception regardless of
  `http_errors`), and `deleteOnFail` then nuked otherwise-healthy proxies. Fix: dropped
  retest concurrency to 1 (sequential) so it matches single-Test semantics exactly. If a
  larger, multi-account pool needs faster bulk retest later, group by credential/host and
  cap per-group concurrency instead of a flat global number.
- **Every "Fix" button landed on the same context-free page (fixed 2026-06-23).** All ~35
  finding types' `fix_url` routed to `/link-structure?url=X`, which only ever showed link
  graph data (inbound/outbound/click-depth) — clicking Fix on `missing_title`,
  `duplicate_content`, `mixed_content`, `hreflang_canonical_conflict`, etc. landed the user
  on a page with zero information about the actual problem. Root cause:
  `CrawlReportService::mapFinding()` always built the same generic link-structure URL
  regardless of `$f->type`. Rebuilt into a real "Page Health" feature instead of patching
  each type individually:
  - `CrawlReportService::pageFindings($websiteId, $url, $pageId)` — every OPEN finding for
    one URL in one call: matched by `affected_url_hash` for on-page types, by `page_id` +
    `whereIn('type', ['broken_external','external_redirect'])` for the two outbound-link
    types (their `affected_url` is the off-site target, not this page, so they can't match
    by hash). Each row carries `label`, `description` (existing `describe()`), a NEW
    `guidance` field (concrete "what to do" text, see `fixGuidance()` — every ~35 types has
    a real entry, no generic fallback), and the raw `detail` for type-specific rendering.
  - `CrawlReportService::fixGuidance(string $type): string` — one-line actionable
    instructions per type (e.g. `missing_self_hreflang` → "Add a `<link rel=alternate
    hreflang=... href=THIS-page-url>` tag pointing at this page itself"). This is the actual
    value-add: every finding now tells the client what to DO, not just what's wrong.
  - `mapFinding()` now appends `&issue={type}` to every `fix_url` (previously only the
    generic link). `LinkStructurePanel` reads it via `#[Url(as:'issue')]`, sorts that finding
    to the top of its Page Health list, and badges it "You're here" — so the destination
    knows WHY the visitor landed there.
  - `broken_external`/`external_redirect` changed too: `fix_url` used to be the literal
    SOURCE page on the LIVE site (opened in a new tab, never touched our app at all). Now
    routes to our Page Health view for that source page instead — consistent destination
    for every finding type, plus the source page's other issues are visible in the same
    place.
  - `resources/views/livewire/link-structure/link-structure-panel.blade.php`: new "Page
    Health" section renders every issue for the focused URL with severity, label,
    description, guidance, and type-specific detail (duplicate siblings as links, hreflang
    table, mixed-content URL list, redirect target, blocked robots.txt path, referrer
    list) — reads straight from each finding's `detail` JSON, no new columns needed.
  - `pageLinkStructure()` now also returns `page.id` (was computed internally and
    discarded) so `pageFindings()` doesn't need a second `WebsitePage` lookup.
  - **Gotcha hit:** `pageFindings()`'s `$pageId` param was first typed `int` —
    `WebsitePage::id` is a ULID string, not numeric (same landmine class as
    [[ulid-formatting-landmines]]). Fixed to `string` before it shipped.
- **`broken_internal`/`broken_page` showed no source in the main Site Issues list (fixed
  2026-07-03).** User flagged for robuststore.com: couldn't see which page links to a
  broken URL without clicking "Fix" through to Page Health. `mapFinding()` already special-
  cased `broken_external`/`external_redirect` to prepend `"On {source} · "` to the subtitle
  (`app/Services/Crawler/CrawlReportService.php`), but the generic branch (everything else,
  including `broken_internal`/`broken_page`) just showed `describe()` with no source, even
  though `detail.referrers` (see "Referrer attachment" in
  [findings-and-scoring.md](./findings-and-scoring.md)) was already populated and rendered
  fine on the Page Health drill-down. Fix: the generic branch now also prepends
  `"Linked from {first referrer} (+N more) · "` whenever `detail.referrers` is non-empty —
  general on the data (any type carrying referrers benefits), not type-special-cased.
- **`noindex_important`/`canonical_mismatch` required GSC traffic to exist at all (fixed
  2026-06-23).** User flagged: the crawler's OWN findings must stand on crawl data alone —
  GSC can inform severity but must never gate whether a crawler finding exists, and GSC-only
  signals need their own clearly-labeled section since Search Console history can lag the
  live site by days (false-positive risk). Audit found exactly 3 pre-existing finding types
  gated on GSC for EXISTENCE (not just severity): `noindex_important`, `canonical_mismatch`
  (both: `if ($clicks >= ...) { add(...) }` — no clicks, no finding at all, regardless of
  GSC presence per-subscriber) and `indexed_not_in_sitemap` (gated on `source_gsc`).
  Re-did the first two using the same crawl-only "is this page structurally real" proxy as
  `robots_blocked_important` (sitemap-listed OR has inbound internal links OR is the
  homepage) — they now fire for every subscriber, GSC-connected or not; GSC clicks only
  bump `medium`→`high`. Confirmed the existing intentional-dedup case (`?param` variant
  pages canonicalizing to a clean URL) stays quiet without GSC too — those pages are
  naturally neither sitemap-listed nor inbound-linked, so the proxy excludes them same as
  before. `indexed_not_in_sitemap` has no crawl-only equivalent ("Google has this indexed"
  is inherently a GSC-only fact) — left GSC-gated but newly marked via
  `CrawlReportService::GSC_SOURCED_TYPES`/`isGscSourced()`. UI now visually separates: the
  grouped issue-type view (`/issues/crawl_*`) sorts GSC-sourced types into their own amber
  "From Google Search Console" section with a staleness caveat instead of mixing them into
  the crawl-derived list; the Page Health panel badges any GSC-sourced finding the same way.
  See [[crawl-only-over-gsc-gating]] — this generalizes that principle from "new checks"
  to "audit and fix existing ones too."

## Large-site pressure points

- **In-memory graph BFS** — `SiteGraphAnalyzer` loads the adjacency list into memory for
  inbound-count + click-depth BFS (O(V+E)). Assumes the link graph fits in RAM. As of
  2026-06-18 the adjacency is **integer-indexed** (dense int ids, not 26-char ULIDs) and built
  in a **single edge pass**, and the BFS uses a **pointer queue** (not `array_shift`, which is
  O(n²)) — this is what lets a ~1.5M-edge / ~168k-page site (xplate) finalize in budget.
- **`AnalyzeSiteJob` timeout** — raised to **3600s** (2026-06-18) after a ~168k-page/~1.5M-edge
  site (xplate) timed out mid-`SiteIssueDetector::detect` even with the graph optimized. Runs on a
  dedicated **`redis-long`** queue connection whose `retry_after=3900` is a **code default**
  (`config/queue.php`), so the ceiling travels with the deploy — no per-box `.env` edit, and a
  still-running finalize is never re-reserved. `tries=2` so one transient failure no longer strands
  the run. The heavy steps (`detect`, suggester, value_rank) are all chunked/cursor-bounded → no OOM.
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

### 2026-06-23 — per-site hard cap to bound finalize cost on huge sites
Even with the 2026-06-18 fixes above, sites like xplate.com (100k+ pages) still made
`AnalyzeSiteJob` slow/risky to finalize — more crawled pages directly means more graph
edges, findings, and link-suggestion candidates for finalize to chew through, no matter how
well each step is chunked. **Fix:** a universal hard ceiling,
`config('crawler.max_pages_per_site')` (default 20,000, env `CRAWLER_MAX_PAGES_PER_SITE`),
now bounds every site's crawl depth regardless of plan. The owner's plan `max_crawl_pages`
was reinterpreted from a flat per-site number into an **account-wide pool** shared across
all the owner's sites — each site's actual cap is `min(hard cap, pool remaining after the
owner's other sites' usage)`, floored at 1 (never blocked outright). See
`Website::crawlPageCap()` and `infra/billing/plans-and-gating.md`.

## Transitional cruft

- The four crawl tables still carry a **nullable, FK-dropped, unused `website_id`** column
  (safe-rollback leftover from the re-key). Nothing reads/writes it. A future migration can
  `dropColumn('website_id')` once we're confident — not urgent.
- `websites` keeps legacy `sitemap_lastmod_true/false` + `crawl_protection*` columns; the
  canonical copies now live on `crawl_sites`. The `Website` helpers delegate to the crawl_site.
