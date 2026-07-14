# Client backlink/authority report (Mangools-style) + Analyze funnel

Customer-facing backlink & authority report, delivered as an interactive public
web page and a branded PDF, fed by two paid providers. Added 2026-07-13.

## What it is

A per-domain report — Domain Authority / Page Authority / Spam Score (Moz),
Authority score / referring domains-IPs-subnets / dofollow & active-link ratios /
backlink profile over time / anchor-type buckets / top referring domains /
competitors (DataForSEO) — plus a conditional GSC/GA traffic strip. Rendered
identically on the web share page and the PDF from one canonical payload.

**Not in scope (documented, deferred):** Majestic Trust/Citation Flow, Facebook
shares, Mangools' popularity rank, and the LLM-mentions / AI-visibility section
(DataForSEO AI Optimization — Phase 3, not built).

## Providers (credentials in .env on BOTH boxes, never committed)

| Provider | Client | Gives | Cost |
|---|---|---|---|
| DataForSEO Backlinks API | `App\Services\DataForSeoBacklinkClient` | summary/history/referring_domains/anchors/domain_pages/backlinks/competitors — **all list endpoints capped at 1000 rows/request (2026-07-13, was 100 for domain_pages/competitors)** — DataForSEO's hard per-request max (verified live). The $0.024 base fee is paid regardless of row count, so pulling 1000 vs 100 costs only ~$0.03 more per report while letting `ClientReportService` explicitly sort for the true top rows instead of trusting API order within a shallow window. + **Labs `competitors_domain`** for organic competitors (shared-keyword based — the backlinks `competitors` endpoint is noisy for small sites, kept only as fallback). Competitor rows filter self + mega-platforms, sorted by shared keywords, sliced to top 10. | $0.024/req + $0.06/1000 rows → ~$0.35/report, flat by site size |
| Moz Links API `url_metrics` | `App\Services\MozLinksClient` | DA + PA + Spam (1 row = 1 URL) | free tier 50 rows/mo — call ONCE per report on the OWN domain only |
| Open PageRank (by Keywords Everywhere) | `App\Services\OpenPageRankClient` | **Popularity rank** (global position) + 0-10 score + monthly history; enriches the site, competitors, referring domains & backlink sources | POST `/v1/domains/bulk` (Bearer, ≤100 domains/call), free 30k/mo. `OPENPAGERANK_API_KEY`. Subdomain sources looked up at registrable-domain level (`::registrable()`). |

Config: `config/services.php` → `dataforseo` (`DATAFORSEO_LOGIN`/`_PASSWORD`),
`moz` (`MOZ_API_TOKEN` base64, or `MOZ_ACCESS_ID`/`MOZ_SECRET_KEY`), `report`
(`REPORT_DEFAULT_TTL_DAYS`=90, `REPORT_PAID_TTL_DAYS`=30).

## Shared per-domain cache (network effect)

`website_report_snapshots` (`App\Models\WebsiteReportSnapshot`) — one row per
`normalized_domain` (via `CrawlSite::normalizeDomain`), holding the full report
`payload` JSON + denormalized DA/PA/Spam/rank/referring_domains/backlinks_total.
Cross-tenant shared cache (plain `HasUlids`, central connection, like
`CompetitorBacklink`). Any user querying a domain reads the same row.

**Freshness — `App\Services\ReportFreshnessGate`:** a snapshot is fresh while
younger than its TTL — **90 days default**, **30 days when the domain is a
`Website` owned by an `isPro()` account** (`ttlDaysFor()` / `isPaidOwned()`).
Fresh snapshots are served with NO provider call.

**Generation — `App\Jobs\GenerateWebsiteReport`** (`tries=1`, `uniqueFor=1800`,
queue `default`): freshness-gated; fetches DataForSEO (bounded) + Moz (own
domain), assembles via `ClientReportService::assemble()`, upserts the snapshot.
Never caches a broken report (aborts if DataForSEO summary is null). Paid-owned
domains are refreshed monthly by `ebq:refresh-paid-reports` (`RefreshPaidReports`,
scheduled `dailyAt('04:15')`, mirrors the TrackRankings due-filter pattern).

## Rendering

`App\Services\Reports\ClientReportService` builds ONE payload; `assemble()` is
pure transformation (cached in the snapshot), `withTraffic()` merges the private
GSC/GA strip only for an authed owner whose site `hasGsc()` (never stored in the
shared snapshot). Anchor-type buckets (branded/naked/generic/exact) are our own
classification over anchor strings.

Views under `resources/views/reports/` — **web and PDF render from the SAME
`$payload` but use different presentation templates** (like the app's email vs
PDF split), because dompdf can't handle Tailwind:
- **Web** — `web-body.blade.php` (Tailwind, matches the marketing site) +
  `partials/web-table.blade.php`. `view.blade.php` wraps it in `<x-marketing.page>`
  (full site header/nav/footer/CSS/Inter). `public.blade.php` = white-label
  standalone loading the site CSS via `@vite`.
- **PDF** — `_body.blade.php` — **fixed light "paper" theme (hex colors, table
  layout, no CSS vars/flex)** so dompdf is happy; used only by
  `pdf/client-report.blade.php`.
- Charts (shared by both): `charts/ring.blade.php` (arc-`<path>` gauge — dompdf-safe,
  NOT stroke-dasharray), `charts/profile.blade.php` (active/lost `<rect>` bars).
- `view.blade.php` states: teaser (blurred web-body + signup modal), pending
  (spinner + JS `location.reload`), unavailable, ready.
- **Blade gotcha:** a directive glued to a word (`report@isset(...)`) is NOT
  recognized as an opening directive but its `@endisset` compiles → orphan
  `endif` parse error. Use an inline `{{ ... ? ... : ... }}` instead.

PDF: `App\Services\Reports\ClientReportPdfRenderer` (mirrors `ReportPdfRenderer`,
A4 portrait, `isRemoteEnabled`). **dompdf-SVG spike passed** — arc-path rings +
rect bars render (verified: valid `%PDF-`).

## Surfaces & routes

- **Public share** — `GET /r/{token}` → `PublicReportController` (`throttle:60,1`).
  Resolves a `report_shares` token (`App\Models\ReportShare`, 40-char `Str::random`)
  → website → snapshot. **Bad/revoked/expired tokens 404, never 403** (no
  enumeration). Mint/revoke via `POST|DELETE /report/share` (`ReportShareController`).
- **PDF export** — `GET /report/download` → `ClientReportExportController`
  (session website + `canViewWebsiteId`, `?whitelabel=0`, `throttle:10,1`), mirrors
  `SiteAuditExportController`.
- **Authed view** — `GET /report/view` → `ReportViewController` (`auth`, NOT
  `verified` — first value lands before email verification).

## Homepage "Analyze website" funnel

`resources/views/partials/analyze-hero.blade.php` is now the SOLE homepage hero in
`landing.blade.php` (the old guest page-audit form was removed from the landing page —
it still lives on its own `/free-audit` page linked from the "Free tools" pills). URL
box + Analyze → `POST /analyze` (`WebsiteAnalyzeController`), which returns a
`results_url` the JS navigates to.

**Key rule — no anonymous provider spend:** a signed-OUT Analyze dispatches NO job and
calls NO DataForSEO/Moz API. `/analyze` validates the URL (per-IP throttle — **anonymous
requests only, see below** — + `SafeHttpGuard` + reCAPTCHA), stashes `session('analyze_domain')`, and returns the
`report.view` URL. That page (`ReportViewController`, public) renders a **blurred MOCK
teaser** (`ClientReportService::sampleTeaserPayload()` — illustrative numbers, never
persisted) behind an inline signup modal (name/email/**phone**/password → `POST
/register`). Only a signed-IN Analyze dispatches `GenerateWebsiteReport`.

Post-signup, `RegisteredUserController` redirects to `report.view?url=<analyze_domain>`
(authed → real report generates; a self-refreshing pending page polls until ready).

## Site Explorer (dashboard entry) + per-plan lookup limits

The report tool is surfaced in the authed app as **"Site Explorer"** — nav item in
`components/layouts/app.blade.php`, route `Route::view('/site-explorer', 'site-explorer')`,
page `resources/views/site-explorer.blade.php` (URL box → `POST /analyze` → `report.view`).

**Per-plan lookup throttle** (editable in the admin Plan editor): plans have
`site_explorer_limit` (max lookups, null = unlimited) + `site_explorer_window_hours`
(rolling window). Enforced in `WebsiteAnalyzeController` via `RateLimiter` keyed by user
(admins exempt). Defaults: trial **2/24h**, solo **20/1h**, pro **70/24h**, agency
**150/24h**, enterprise unlimited. Editable at `/admin/plans/{plan}/edit`
(`PlanController::validatePlanInput` + `admin/plans/edit.blade.php`). Plan helpers:
`Plan::siteExplorerLimit()` / `siteExplorerWindowHours()`. **Set on prod via targeted
column update, NOT a full PlanSeeder re-run** (that would overwrite admin plan edits).

**Per-IP throttle is ANONYMOUS-only (2026-07-14 fix).** `WebsiteAnalyzeController` also
has a coarse per-IP guard (5/min, 30/day, keys `analyze:m:{ip}` / `analyze:d:{ip}`) meant
to stop pre-signup scraping of the free teaser, where no other governance exists yet (no
account, no plan). It used to run unconditionally for EVERY request, authenticated or
not — which meant an authenticated user could get blocked by the generic "You've reached
the limit of 30 analyses per day" message (hiding the real, more specific per-plan
message) purely because their IP had made 30 requests that day, e.g. from a shared/office
IP, or even for a domain they already own. Fixed: both the check and the `RateLimiter::hit()`
now run only `if (! Auth::check())` — a signed-in request is governed exclusively by the
per-plan limiter above (which is domain-aware: exempt for the user's own websites, deduped
per-domain per window — see below). Anonymous requests are unaffected.

**Only a genuinely NEW distinct lookup consumes the quota** (2026-07-14). Two
exemptions, both in `WebsiteAnalyzeController::store()`:
- A domain that's one of the user's own attached `Website` rows never counts
  (`ownsWebsiteForDomain()` — `canViewWebsiteId` check, so team members are
  covered too), regardless of how many times they open it.
- Re-analyzing a domain they've already been charged for **within the current
  window** doesn't count again — tracked via a parallel cache marker
  `site-explorer-seen:{userId}:{domain}` with the SAME TTL as the plan's
  window (`RateLimiter::hit()` only fires, and the marker is only set, on a
  cache-miss for that marker). A DIFFERENT user analyzing the same domain is
  unaffected — the marker is per-user. Both the limit CHECK and the block
  response are skipped entirely for exempt lookups, so a user already at
  their limit can still re-open an already-counted domain or their own site.
  Test: `tests/Feature/SiteExplorerLimitDedupTest.php`.

**"Recently analyzed" history** on the Site Explorer page itself
(`<livewire:site-explorer-history>`, `App\Livewire\SiteExplorerHistory`) — reads the
user's own `site_explorer.query` `ClientActivity` rows (same log the admin usage
page reads), deduped to one entry per domain (most recent lookup wins), newest
first, capped at 15. Flags entries matching one of the user's own websites with a
"Your site" badge.

## Post-signup landing hub — `/overview`

`ConnectGoogle::finishOnboarding()` redirects a brand-new user to
`route('website-overview')` (was `dashboard` until 2026-07-13), landing them on
their own site's Site Explorer report with a top tab bar: **Site Explorer · Site
Health · Statistics**. Controller: `App\Http\Controllers\WebsiteOverviewController`;
view: `website-overview.blade.php`.

- **Site Explorer tab** — resolves the report for `$website->normalized_domain`
  via `ReportViewController::resolve()` (factored out of `show()` so the standalone
  `/report/view` page and this tab share identical pending/ready/no-data logic —
  `reports/_status.blade.php` is the shared partial both render). No URL typing
  needed; auto-generates on first view like any other authed Analyze.
- **Site Health tab** — embeds the existing `crawl-banner` /
  `dashboard.site-health-stats` / `dashboard.priority-action-queue` Livewire
  components verbatim (same polling, same cache, same everything).
- **Statistics tab** — a faithful replica of `/statistics` (same components, same
  layout/order: sync-and-report-panel, `kpi-cards`, country-filter, `insight-cards`,
  the 3-col `traffic-chart`/`top-countries`/`seasonality`/`quick-wins` grid), merged
  2026-07-13 from what were originally two separate tabs (Traffic Statistics / GSC
  Performance). `kpi-cards`/`traffic-chart` mix GA+GSC by design and already degrade
  gracefully with partial data, so they're never gated. Only the confirmed
  single-source blocks — `insight-cards` + `seasonality-card` (pure GSC) — swap in
  `<x-connect-source-prompt>` / `<x-overview.processing-panel>` when GSC isn't
  ready; `traffic-chart` gates on GA the same way.
- Every tab pill is a **real, checked signal** — `Website::isCrawling() ||
  isInitialCrawl()` (both — `isInitialCrawl()` alone goes false as soon as a site
  has EVER completed a crawl, so a RECRAWL of an established site would misread as
  "Ready" while the crawler is actively running), `hasGa()`/`hasGsc()`,
  `AnalyticsData::where('website_id', ...)->exists()`,
  `ReportDataService::lastSafeReportDate()`, and a real `WebsiteReportSnapshot`
  lookup for the Explorer tab (was hardcoded to always show "Processing" until
  2026-07-13, even on a cache hit) — the same checks the rest of the app already
  uses for the identical question, never inferred from cache warmth.

The old dashboard "just_onboarded" welcome modal (`resources/views/dashboard.blade.php`)
is removed — dead code once the redirect target changed (the flash flag never
reached `/dashboard` as the first post-onboarding request anymore).

**The tab bar only shows when a website is genuinely identified — never guessed.**
`<x-website-tabs :website :active>` (`resources/views/components/website-tabs.blade.php`)
is embedded on `dashboard.blade.php` (active=`health`), `statistics.blade.php`
(active=`statistics`), and `reports/view.blade.php` (active=`explorer`, **only**
when `$website` is non-null). It is deliberately NOT on the bare
`site-explorer.blade.php` tool page — that's a generic "analyze ANY domain" form
with no site context until submitted, and showing nav tied to a stale
session-pinned website there was the original bug (2026-07-14): analyzing an
account's own attached domain from Site Explorer showed no Site Health/Statistics
nav, while the same domain via the dashboard worked fine — because the tab bar was
reading whatever website happened to be session-pinned, not the domain actually
being analyzed. Fixed in `ReportViewController::resolve()`: the domain's `Website`
match is now returned in EVERY branch (was only in the ready-payload branch before),
and `session(['current_website_id' => $website->id])` is pinned whenever the
analyzed domain IS one of the user's own websites — so the nav (and the Site
Health/Statistics tabs it links to) always reflects the site actually being viewed,
and never appears for an arbitrary/competitor domain lookup. Status logic lives in
`App\Services\WebsiteTabStatus` (`forWebsite()` + `currentWebsite()`), extracted out
of the controller specifically so the Blade component and the global
`current_website()` helper (`app/Support/helpers.php`) can both call it — `dashboard`/
`statistics` have no controller to inject a website into (`Route::view()`), so they
resolve it themselves via `current_website()` in a `@php` block.

**Adding a website = same landing as onboarding (2026-07-14).**
`WebsitesList::addWebsite()` (the dashboard "add website" form) now pins the new site
as `current_website_id` and redirects to `/overview?tab=explorer` when the row was
newly created — the exact same landing `ConnectGoogle::finishOnboarding()` gives a
fresh signup. Nothing new is dispatched there: the overview Explorer tab already kicks
the initial Site Explorer generation on first view (freshness-gated / shared-snapshot
aware), the crawl (`CrawlSiteBootstrapper::subscribeWebsite`) + 365-day GSC/GA import
(`ebq:import-historical`) were already queued by addWebsite() itself, and the Site
Health / Statistics tab pills show processing / needs-action per source. Re-adding an
existing domain keeps the old no-redirect behavior. Test:
`tests/Feature/AddWebsiteFlowTest.php`.

## Dashboard "Competitors" page — `/competitors` (Pulse nav group)

Added 2026-07-14, same pattern as Backlinks below: `CompetitorsController` +
`resources/views/competitors.blade.php` — the organic-competitor slice
(`payload['competitors']`, DataForSEO Labs shared-keyword domains, ~1000 rows since the
display-cap raise) for the current website. Headline cards (competing domains / most
shared keywords / top competitor), scrollable table (#, domain, shared keywords, avg
position, OPR authority) with a client-side Alpine domain filter. Each domain links to
its own `report.view` — with a visible note that competitor lookups consume the plan's
Site Explorer limit. NOTE: the legacy `/backlinks` route collision below is why BOTH
these pages use controller routes registered BEFORE the `Route::view` block — check
`php artisan route:list` for URI collisions when adding more Pulse pages.

## Dashboard "Backlinks" page — `/backlinks` (Pulse nav group)

Added 2026-07-14: `BacklinksController` + `resources/views/backlinks.blade.php` — the
backlink slice of the Site Explorer snapshot for the CURRENT website
(`WebsiteTabStatus::currentWebsite()`), rendered in dashboard style (dark-mode aware)
instead of the report's fixed light "paper" theme. Reuses
`ReportViewController::resolve()` verbatim, so pending/no-data/dispatch behaviour and
the freshness gate are identical to the report page (opening it never double-bills; a
never-analyzed own domain auto-generates, same as the /overview Explorer tab). Sections:
headline stat cards (backlinks / referring domains / IPs / dofollow %), referring-domain
trend sparkline (+new/−lost this month), anchor-type split bars, and the three scrollable
tables (top referring domains, backlinks, anchor texts). Links out to the full
`report.view` page. Sidebar: Pulse group, `feature => null` (no plan gate — the per-plan
Site Explorer lookup limit governs generation, and viewing an existing snapshot is free).

## Admin usage page — who queried what

Every authed Analyze (`WebsiteAnalyzeController::store()`) writes one `client_activities` row
(`type='site_explorer.query'`, `user_id`=the querying client, `meta`={domain, cache_hit,
sandbox}) via `ClientActivityLogger`, computed from `ReportFreshnessGate::isFresh()` *before*
dispatching the job (the job itself no-ops silently on a cache hit, so this is the only place
that observes fresh-vs-cached at query time). Admin page at `/admin/site-explorer-usage`
(`SiteExplorerUsageController`, `admin.site-explorer-usage.index`) shows: summary cards
(queries/unique domains/unique clients/cache hits/real generations + REAL cost), a
per-client rollup, and a paginated raw query log (time, client, domain, fresh/cached badge,
link to the live report).

**Cost is real, not estimated (2026-07-14).** DataForSEO's own API response carries the
actual billed amount per call (`tasks[0].cost`) — `DataForSeoBacklinkClient` accumulates it
across every call in `totalCost()` (reset per instance; a queued job gets a fresh instance).
`GenerateWebsiteReport::handle()` reads it once after all DataForSEO calls complete and:
1. Stores it on `WebsiteReportSnapshot.dataforseo_cost_usd` (that domain's LATEST generation
   cost — gets overwritten on the next regeneration, so it's a point-in-time value, not a
   ledger).
2. Logs a `client_activities` row `type='site_explorer.generation'` (`meta`={domain,
   cost_usd}, no `user_id` — a generation isn't attributable to one user, since job dedup
   (`ShouldBeUnique`) can collapse several near-simultaneous users' fresh lookups of the same
   domain into ONE real generation) — THIS is the accurate historical ledger the admin page's
   "Real generations" / "Real cost" summary cards sum over the selected date range. Skipped
   entirely for sandbox generations (never billed). Also logged on the `no_data` terminal path
   (the summary call alone still costs money even when DataForSEO has nothing for the domain).
   Per-client and per-query-row costs are looked up from the domain's CURRENT snapshot value
   (`SiteExplorerUsageController::realCostByDomain()`) — real captured numbers, but an
   under-count if a domain regenerated more than once within the period (only the latest
   generation's cost is stored per domain, no per-event cost history). The old flat
   `services.report.generation_cost_usd` estimate is gone from this page.

This is a separate page from `/admin/usage` (KE/Serper/LLM) because Site Explorer isn't
metered per-unit — cost is flat-per-generation, not per-row/per-token, so it doesn't fit the
`UsageMeter`/rates pipeline.

## Public tools — signup/login gate (all 4 tools)

Every public marketing tool (SEO Audit, PageSpeed, Rank Checker, Keyword Volume) now
requires an account to see any result — same gate as Site Explorer. Anonymous submit runs
**nothing** (no API): each controller's `store()` short-circuits at the top with
`if (auth()->guest()) return json(['require'=>'signup'])` (`GuestAuditController`,
`GuestPageSpeedController`, `GuestRankCheckController`, `GuestKeywordVolumeController` — 4
independent copies of one pattern). Run-path reCAPTCHA is guarded with `&& auth()->guest()`
so authed auto-runs don't need a captcha.

**The old "progressive friction" funnel is fully removed (2026-07-13):** the 4 guest
controllers no longer count runs via cookie, never email results, and never return
`require:'email'`/`require:'signup'` for authed users — a signed-in user always gets the
on-screen result (per-IP rate limits remain). The email modals + "Check your inbox" cards
were deleted from the 4 tool views + landing (view JS is null-guarded, so no breakage).

**Teaser opens on its own page showing the REAL report blurred:** anon submit returns
`results_url = /tools/preview/{tool}?<inputs>` (202); the tool JS's existing `results_url`
branch navigates there. `ToolPreviewController` (`app/Http/Controllers/ToolPreviewController.php`)
renders each tool's **real result view** (`guest-audit.show` etc.) with a **fabricated
unsaved model** (`status='completed'` + a sample `result` array — see the per-tool sample
shapes in the controller), overlaid with `partials/report-teaser-modal.blade.php` (a
`backdrop-blur-lg` full-viewport overlay + `partials/auth-modal.blade.php`). Each show view
got a one-line `@if ($teaser ?? false) @include('partials.report-teaser-modal', …) @endif`.

Shared UI: `partials/auth-modal.blade.php` (signup/signin toggle + phone dropdown + `_form` +
safe-local `redirect` hidden fields, honored by `Register`/`AuthenticatedSession`).
`partials/tool-gate.blade.php` is still `@include`d on each tool page for **post-auth
auto-run** (`?autorun=1` → refill form + re-submit → now authed → the tool runs for real →
its result page).

**Sample-shape gotchas (ToolPreviewController):** audit `content.keyword_density` must be an
array of `{term,count,density}` (not a float); pagespeed `opportunities`/`diagnostics` each
need a `rating` key; audit keeps `keywords.available=false`, `benchmark=null`,
`core_web_vitals=null` so the shared audit partial never hits DB/route/`->website`.
`auth-modal` guards `$errors` with `isset()` (it renders where `$errors` may be unshared).

## Signup phone (added)

`users.phone` (nullable column, additive migration); required in registration
(`RegisteredUserController::store` rule `phone` + `register.blade.php` input +
the analyze-hero modal) and `User::$fillable`. Email-verification flow unchanged.

## Gotchas

- **`domain_pages` nests metrics under `page_summary`, not top-level.** Fixed 2026-07-13 —
  `ClientReportService::topPages()` was reading `$r['referring_domains']`/`$r['backlinks']`
  off the top level, which DataForSEO never populates there (only `page`, `status_code`,
  crawl metadata live at top level); the real counts are under `$r['page_summary']`. Prior
  behavior: "Top content" showed real URLs with permanently-null counts, unsorted. Now reads
  `page_summary` with a top-level fallback, and explicitly sorts by `referring_domains` desc
  before slicing to 15 — same fix pattern applied to `competitorRows()` (sorts by
  `shared_keywords` desc before slicing to 10) since neither should trust API row order.
- **Moz free tier = 50 rows/mo** — call only on the own domain (1 row/report); use
  DataForSEO `rank` for competitor/referring-domain scores. Past 50 rows Moz throttles
  (no charge) → upgrade to $20 Starter.
- **Report is a fixed light "paper"** in both web and PDF by design — do not switch
  it to theme CSS vars, or the PDF (dompdf) breaks.
- **Gauges use arc `<path>`, not `stroke-dasharray`** — dompdf renders paths, not dasharrays.
- **`analyze_domain` in session** is the funnel handoff; don't clear it before the
  post-signup redirect.

## Key files

- Clients: `app/Services/{DataForSeoBacklinkClient,MozLinksClient}.php`
- Cache/gate/job: `app/Models/WebsiteReportSnapshot.php`, `app/Services/ReportFreshnessGate.php`,
  `app/Jobs/GenerateWebsiteReport.php`, `app/Console/Commands/RefreshPaidReports.php`
- Assembly + render: `app/Services/Reports/{ClientReportService,ClientReportPdfRenderer}.php`,
  `resources/views/reports/**`, `resources/views/pdf/client-report.blade.php`
- Surfaces: `app/Http/Controllers/{WebsiteAnalyzeController,ReportViewController,PublicReportController,ClientReportExportController,ReportShareController}.php`,
  `resources/views/partials/analyze-hero.blade.php`
- Model: `app/Models/ReportShare.php`
- Tests: `tests/Feature/ClientReportTest.php`
