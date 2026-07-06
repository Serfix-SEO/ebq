# Page audit pipeline

The core fetch→parse→benchmark→CWV→recommend pipeline. One method,
`PageAuditService::buildAuditResult()` (`app/Services/PageAuditService.php:222`), backs every
flavour; the public entrypoints just toggle three booleans (`lite`, `skipSerp`, `skipCwv`)
and decide whether to persist.

## Entrypoints & flavours

| Caller | Method | `lite` | Serper | CWV | Persists |
|---|---|---|---|---|---|
| `audit()` full (manual / HQ / Page Detail) | `PageAuditService::audit()` `:43` | no | yes | yes | `PageAuditReport` |
| Live-score (WP editor) | `audit($lite=true)` | **yes** | no | yes | `PageAuditReport` |
| Guest (free, no signup) | `auditGuest()` `:154` | yes | **yes** | **no** | returns array inline |

Flag semantics (`buildAuditResult($lite, $skipSerp, $skipCwv)`):
- **`lite`** — skips the outbound-link checker (`HtmlAuditor::links()` results are kept but
  every link is reported `links_skipped`; broken-link HTTP HEADs are not run). Saves ~80s on
  link-heavy pages. `:265`
- **`skipSerp`** — skips the Serper competitor benchmark (3 competitor HTML fetches + audits,
  ~20–40s). Note the live-score path passes `skipSerp=$lite`, so it skips both; guest audits
  pass `skipSerp=false` so they still benchmark. `:348`
- **`skipCwv`** — skips the Lighthouse CWV call. Guest only. `:378`

Typical wall-time: full ≈ 60–120s, lite ≈ 15–30s, guest ≈ 15–30s.

## Flow (one full audit)

1. **Guard + ownership.** `SafeHttpGuard::check()`; for authenticated runs,
   `Website::isAuditUrlForThisSite()` enforces the URL is on the site's domain. `:52`,`:70`
2. **Fetch** (`fetch()` `:1081`) — Googlebot UA, 20s timeout, **manual redirect handling**
   (≤5 hops, each re-guarded via `on_redirect`), body capped at `MAX_BODY_BYTES = 5 MB`,
   captures status / TTFB / size / `Content-Encoding` / stack headers.
3. **Parse** — one `HtmlAuditor` instance produces `metadata`, `localeSignals`, `headings`,
   `content` (word count + keyword density + `body_text`), `images`, `links`, `schema`,
   `favicon`, `readability`, and `technology` (stack fingerprint). `body_text` is dropped
   from the stored blob but a 4000-char `body_excerpt` is kept for downstream consumers.
4. **Locale** — `PageLocaleResolver::resolve()` picks `gl/hl/bcp47` from hreflang→og:locale→
   html-lang; `SerpLocaleDefaults::forSerperRequest()` fills a missing `gl` from `hl`. A
   user-chosen `serp_gl` overrides when valid.
5. **Link check** (full only) — `checkLinks()` `:1150`: dedupe to `MAX_LINKS_CHECKED = 100`,
   guard each, HEAD in pools of `LINK_POOL_CONCURRENCY = 10` with `LINK_TIMEOUT = 8s`;
   403/405/**429**/501 fall back to a GET, and if that GET *also* still looks dead, one more
   GET retry through the crawler's `ProxyPool` (`crawler.proxy.*`) before trusting the result;
   `>=400` or null → broken. Mirrored in `App\Services\Crawler\LinkChecker` for the crawler
   pipeline — keep both in sync (fixed together 2026-06-20, see changelog).
6. **Keyword strategy** — `fetchTargetKeywords()` aggregates the page's GSC queries (last
   `effectiveGscKeywordLookbackDays`, top 50 by impressions); `KeywordStrategyAnalyzer::analyze()`
   scores placement of the primary query (or manual override) across title/meta/H1/headings/body.
   Guest/no-website runs have empty GSC and run off the manual keyword only.
7. **SERP benchmark** (`buildSerperReadabilityBenchmark()` `:464`, gated by `!skipSerp`) —
   Serper top-20 for the primary keyword; resolve the page's own SERP position by **host
   match** (`resolveYourSerpPosition()`); pick up to 3 competitor URLs (skip own host, guard
   each), fetch + audit each, build a `gap_table` (word count / Flesch / images / stack vs the
   competitor average). Flesch outliers <10 or >95 are excluded from the average.
8. **Core Web Vitals** (gated by `!skipCwv`) — `LighthouseClient::fetchMobileAndDesktop()`.
   Failure is swallowed (logged) — no `core_web_vitals` key.
9. **Recommendations** — `RecommendationEngine::analyze($result)` `:392`.
10. **Persist** — full/live-score `updateOrCreate` a `PageAuditReport` on
    `(website_id, page_hash)`; guest returns the array. `primary_keyword` + source are lifted
    out of the keyword block for list display.

## Data model

### `PageAuditReport` (`app/Models/PageAuditReport.php`)
One row per `(website_id, page_hash=sha256(url))`. Columns: `page`, `status`
(`completed`/`failed`), `audited_at`, `http_status`, `response_time_ms`, `page_size_bytes`,
`error_message`, `primary_keyword` + `primary_keyword_source`, and **`result`** (JSON cast to
array) — the entire audit blob. `result` top-level keys: `metadata`, `page_locale`, `content`
(+ `headings`, `body_excerpt`), `images`, `links`, `technical` (+ `stack`), `advanced`
(`schema_blocks`, `readability`, `favicon`), `keywords`, `benchmark`, `core_web_vitals`,
`recommendations`. Many downstream consumers (`LiveSeoScoreService`, `EntityCoverageService`,
`AuditPerformanceService`) read slices of this blob rather than re-fetching.

### `CustomPageAudit` (`app/Models/CustomPageAudit.php`)
A **job/queue row** wrapping an audit run for the authenticated portal & plugin. FK
`page_audit_report_id` → the report it produced. Lifecycle: `queued → running → completed/failed`
(`markRunning`/`markCompleted`/`markFailed`). Sources (`SOURCE_*`): `custom`, `page_detail`,
`live_score`, `hq_wp`, `keyword_fix`. `SOURCES_PORTAL_HISTORY` (the `scopePortalHistory` filter)
excludes `live_score` (editor background noise) and `keyword_fix` (playbook background noise).
`queue()` dedups paid spend via `findActiveFor()` (same website+url+user already queued/running).
`recordRun()` is the legacy *synchronous* path used by Page Detail's inline "Audit this page".

### `GuestPageAudit` (`app/Models/GuestPageAudit.php`)
Anonymous landing-page audit, route-bound by an unguessable `token` (not id). Stores the full
`result` blob inline (no `PageAuditReport`). `start()` creates it; `getPageAttribute()` adapts
`url`→`page` so the shared Blade report partial renders it unchanged. If the guest supplied an
email (2nd free audit), `RunGuestPageAudit` emails the report link.

## Triggers

- `app/Livewire/Pages/CustomAudit.php:172` — `CustomPageAudit::queue()` + `RunCustomPageAudit::dispatch()`.
- `app/Livewire/Pages/PageDetail.php:296` — inline `audit()` + `CustomPageAudit::recordRun()`.
- `app/Http/Controllers/Api/V1/PluginHqController.php:1370` — WP "SEO Analysis" tab (`hq_wp`).
- `app/Http/Controllers/GuestAuditController.php:136` — `GuestPageAudit::start()` + `RunGuestPageAudit`.
- `LiveSeoScoreService::resolveAuditState()` — auto-queues `live_score` audits.
- `PageAuditController` (`app/Http/Controllers/PageAuditController.php`) — `show()` (HTML detail)
  + `download()` (HTML export), both `canViewWebsiteId`-gated.

## Background jobs

| Job | timeout | Notes |
|---|---|---|
| `RunCustomPageAudit` | 300s | `ShouldBeUnique` (`custom-page-audit:{id}`, `uniqueFor=1800`), `tries=1`. After success, queues competitor-backlink fetches from the benchmark domains. |
| `RunGuestPageAudit` | 120s | Guest audits are lite; mails the report link if email present. |

Both implement `failed()` to flip a stuck `running` row to `failed` (covers timeouts) — the UI
poller never hangs.

## HtmlAuditor notes

- Loads HTML with `LIBXML_NONET` (no DTD/external-entity network fetches) and tolerant flags.
- Case-insensitive attribute matching via XPath `translate()` (handles `REL=Canonical` etc.).
- `technology()` returns `{label, type: modern|cms|static|unknown, signals}` from response
  headers → `<meta generator>` → DOM/asset fingerprints (Next.js, Nuxt, Astro, Shopify, Wix,
  Squarespace, Webflow, Drupal, WordPress, Laravel+Vite). Laravel needs **both** `/build/assets/`
  and `wire:*` to avoid false positives.
- `readability()` is English Flesch (syllable estimate is `[aeiouy]+`-based, ASCII only) — only
  meaningful for prose; the benchmark gap-table excludes out-of-range scores.

## Gotchas / known issues

- **Flesch is English-centric.** `estimateSyllables()` strips non-ASCII, so the Flesch number
  is unreliable for non-Latin pages; the SERP gap-table guards against skew (`fleschStatus`,
  10–95 band) but the raw `readability.flesch` in the blob is still computed.
- **Link check is best-effort.** Capped at 100 unique links; 403/405/429/501 hosts (bot-blocking
  or rate-limiting) fall back to GET, then to one proxied GET retry via `ProxyPool` if that
  also fails — but a host that blocks HEAD, GET, *and* every proxy IP still reads as broken
  (false positive). Fixed 2026-06-20: 429 (rate-limit) wasn't in the fallback list at all
  before, so e.g. `apps.apple.com` 429-ing the HEAD check got flagged broken instantly with no
  retry — see knowledge changelog.
- **Fixed 2026-07-06 — content-hash re-audit gate.** `buildAuditResult()` now stores
  `content_hash` (sha256 of extracted body text) on every completed `PageAuditReport`.
  `PageAuditService::currentContentHash()` + the scheduled `ebq:recheck-audit-content` command
  use it to independently detect real content drift and queue a fresh audit — see live-score
  doc §Gotchas for the full story (this was originally scoped as a live-score-path problem; the
  fix lives at the `PageAuditReport`/service level so it isn't tied to that one caller). Manual
  re-audit still always overwrites regardless.
- **`MAX_BODY_BYTES = 5 MB` truncation** can cut a huge page mid-tag; libxml tolerates it but
  trailing content (and its links/schema) is lost. Logged at info level.
- **SERP host-match is generous.** `organicHostMatchesAuditedSite()` matches parent/child
  subdomains, so an audited `blog.x.com/post` can match an organic `x.com/` listing as "your
  SERP position".

## Key files

- `app/Services/PageAuditService.php`
- `app/Support/Audit/HtmlAuditor.php`
- `app/Support/Audit/SafeHttpGuard.php`
- `app/Support/Audit/PageLocaleResolver.php`, `SerpLocaleDefaults.php`
- `app/Support/Audit/KeywordStrategyAnalyzer.php`, `RecommendationEngine.php`
- `app/Models/{PageAuditReport,CustomPageAudit,GuestPageAudit}.php`
- `app/Jobs/{RunCustomPageAudit,RunGuestPageAudit}.php`
- `app/Http/Controllers/PageAuditController.php`
