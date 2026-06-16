# Audits, Performance & Topical-Authority subsystem

Documentation for the three SEO-analysis subsystems built on top of one fetch+parse
pipeline:

- **Page audits** — fetch a URL, parse the HTML (`HtmlAuditor`), benchmark against the
  SERP (Serper), pull Core Web Vitals (self-hosted Lighthouse), and emit a prioritized
  recommendation list. Persisted as a `PageAuditReport` blob.
- **Performance / Lighthouse** — the standalone PageSpeed-Insights-style tool and the
  CWV enrichment that feeds the audit, both via the `ebq-intelegence` Lighthouse service.
- **Topical authority / content strategy** — GSC-co-occurrence clustering
  (`TopicalAuthorityService`) plus two LLM-backed gap analyzers (`TopicalGapService`,
  `EntityCoverageService`).
- **Live SEO score** — composes a 0–100 score for one URL from every data source we
  already have (GSC + audit blob + indexing + backlinks), and gates the audit run.

## Read in this order

| Doc | What it covers |
|---|---|
| [page-audit.md](./page-audit.md) | **Start here.** The fetch→parse→benchmark→CWV→recommend pipeline, `lite`/guest variants, `CustomPageAudit`/`GuestPageAudit`/`PageAuditReport` data model, the SSRF guard, locale resolution. |
| [lighthouse-and-performance.md](./lighthouse-and-performance.md) | `LighthouseClient` (CWV + full-report shapes), the async per-strategy `RunPageSpeedStrategy` job + `PageSpeed` Livewire poller, `AuditPerformanceService`. |
| [live-score-and-language.md](./live-score-and-language.md) | `LiveSeoScoreService` 13-factor composite + audit gating, `LanguageDetectorService`. |
| [topical-authority.md](./topical-authority.md) | `TopicalAuthorityService` (GSC clustering, no embeddings), `TopicalGapService` + `EntityCoverageService` (Serper + LLM). |

## One paragraph

A single pipeline — `PageAuditService::buildAuditResult()` — backs everything. It does one
SSRF-guarded HTTP fetch, runs the body through `HtmlAuditor` (a `DOMXPath`-based parser that
extracts metadata, headings, content, links, images, schema, tech-stack and readability),
optionally checks outbound links, optionally fetches the top-3 SERP competitors via Serper
and benchmarks length/readability/stack against them, optionally pulls mobile+desktop Core
Web Vitals from the self-hosted Lighthouse service, then runs `RecommendationEngine` over the
assembled blob. The authenticated flow persists a `PageAuditReport`; the guest flow returns
the blob inline. Two `lite`/`skip*` flags trim the slow paid stages for latency-sensitive
callers (the WP editor live-score, the free guest tool). On top of the stored audit blob,
`LiveSeoScoreService` projects factor slices and `EntityCoverageService` does entity diffs;
`TopicalAuthorityService`/`TopicalGapService` work off GSC + Serper directly.

## Key components

| Component | File | Role |
|---|---|---|
| `PageAuditService` | `app/Services/PageAuditService.php` | The pipeline. `audit()` (auth, persists), `auditGuest()` (anon, inline), shared `buildAuditResult()`. |
| `HtmlAuditor` | `app/Support/Audit/HtmlAuditor.php` | Stateless DOM parser — metadata, headings, content, links, images, schema, readability, robots, **tech-stack fingerprinting**. Reused by the crawler. |
| `SafeHttpGuard` | `app/Support/Audit/SafeHttpGuard.php` | SSRF guard — public-IP-only, re-checked on every redirect hop. |
| `PageLocaleResolver` / `SerpLocaleDefaults` | `app/Support/Audit/` | Resolve `gl`/`hl`/`bcp47` from hreflang/og:locale/html-lang for the Serper request. |
| `KeywordStrategyAnalyzer` | `app/Support/Audit/KeywordStrategyAnalyzer.php` | GSC-query / manual-keyword placement analysis (title/H1/body buckets). |
| `RecommendationEngine` | `app/Support/Audit/RecommendationEngine.php` | Prioritized rec list across metadata/structure/images/technical/links/keywords/CWV/SERP. |
| `LighthouseClient` | `app/Services/LighthouseClient.php` | Thin client for the `ebq-intelegence` Lighthouse service. Never throws. |
| `RunCustomPageAudit` / `RunGuestPageAudit` / `RunPageSpeedStrategy` | `app/Jobs/` | Background runners (`tries=1`, unique). |
| `LiveSeoScoreService` | `app/Services/LiveSeoScoreService.php` | 13-factor 0–100 composite; gates audit runs. |
| `LanguageDetectorService` | `app/Services/LanguageDetectorService.php` | n-gram + word-vote language detection, cached. |
| `TopicalAuthorityService` / `TopicalGapService` / `EntityCoverageService` | `app/Services/` | Topical clustering + LLM gap analysis. |
| `PageAnalyzer` | `app/Support/Crawler/PageAnalyzer.php` | Crawl adapter over `HtmlAuditor` (no network/DB) — documented in `infra/crawler/`. |

## Invariants / cross-cutting rules

1. **Every outbound fetch goes through `SafeHttpGuard`** — initial URL, every redirect hop,
   every checked link, every SERP competitor. No raw `Http::get()` to user-supplied URLs.
2. **External stages fail open, never throw the audit.** Serper down → benchmark `null`;
   Lighthouse down → no `core_web_vitals` key; the audit still completes.
3. **`tries=1` on every audit job** — Serper/Lighthouse/KE calls cost money; never auto-retry
   a paid external call without a human.
4. **`PageAuditReport` is keyed by `(website_id, page_hash)`** via `updateOrCreate` — one
   live report per URL per site; re-audits overwrite.
