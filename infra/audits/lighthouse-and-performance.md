# Lighthouse & performance

All Core Web Vitals / Lighthouse data comes from a **standalone self-hosted Node service**
(`ebq-intelegence`) that runs headless-Chrome Lighthouse. The Laravel side never runs Chrome;
`LighthouseClient` (`app/Services/LighthouseClient.php`) is a thin HTTP client that **never
throws** — an audit must complete without CWV if the service is down.

## Two consumption shapes

`LighthouseClient` produces two different shapes from the same service:

| Method | Endpoint | Used by | Returns |
|---|---|---|---|
| `fetchMobileAndDesktop()` `:36` | `POST /audit/batch` (one call, both strategies) | the page-audit pipeline (`result.core_web_vitals`) | trimmed CWV contract: per-strategy `performance_score`, `lcp_ms`, `cls`, `tbt_ms`, `fcp_ms`, `ttfb_ms`, `speed_index_ms` |
| `fetchStrategyReport()` `:157` | `POST /audit?raw=1` (one strategy) | the standalone PageSpeed tool | full PSI-style report: category gauges, lab metrics, opportunities (with savings), diagnostics, per-category failed audits, final screenshot |
| `fetchFullReport()` `:126` | two sequential `fetchStrategyReport()` | (synchronous full report; the async job uses `fetchStrategyReport` per strategy) | `{mobile, desktop, lighthouse_version, …}` |

`isConfigured()` requires both `services.lighthouse.url` and `services.lighthouse.key`.

### Full-report parsing (`parseFullLhr()` `:210`)
Collapses the ~1 MB Lighthouse Result object into a compact Livewire-safe structure:
- **Scores** — `performance / accessibility / best_practices / seo` (0–100).
- **Lab metrics** — the 6 PSI headline metrics (FCP, LCP, TBT, CLS, SI, TTI) with `display`
  string + `rating` (good/average/poor).
- **Opportunities** — audits with `details.type === 'opportunity'` and score `<1`, sorted by
  `overallSavingsMs`, capped at 12, each with an offending-resource table.
- **Diagnostics** — non-passing performance audits in the `diagnostics` group, capped 10.
- **Failed audits** — accessibility / best-practices / SEO audits scoring `<1`, capped 12 each.
- **Screenshot** — `final-screenshot` data URI.
- `extractDetailsTable()` `:343` handles Lighthouse's renamed heading fields (`key`/`valueType`
  new vs `itemType`/`text` old), caps 8 rows × 4 cols, formats bytes/ms/url cells.

## Async PageSpeed tool

The standalone PageSpeed Insights page is **fully asynchronous** because a full mobile+desktop
run over all 4 categories can exceed a minute on heavy sites — past the queue worker's
`--timeout=90` AND Cloudflare's ~100s proxy timeout (the 504 that prompted this design).

Flow:
1. `PageSpeed::runTest()` (`app/Livewire/Pages/PageSpeed.php:53`) validates the URL, checks
   `isConfigured()`, rate-limits (10 tests / 5 min / user), generates a `runId`, and dispatches
   **two** `RunPageSpeedStrategy` jobs (mobile + desktop) on the `INTERACTIVE` queue.
2. `RunPageSpeedStrategy` (`app/Jobs/RunPageSpeedStrategy.php`) runs ONE strategy
   (`timeout=88`, below the worker's 90 so a slow HTTP call returns null rather than the worker
   killing the job and losing the other strategy too) and stashes the parsed report (or
   `['error'=>true]`) in the cache under `pagespeed:{runId}:{strategy}` for 30 min.
3. `PageSpeed::pollResult()` polls the cache, surfaces per-strategy `progress`
   (`running/done/failed`), and assembles the final report once both land — or one fails, or
   `MAX_WAIT_SECONDS = 240` elapses. Cache slots are `forget()`-en after assembly so a re-run
   with a new `runId` can't collide.

The two short jobs let the **two ebq workers run mobile + desktop in parallel**, each well
under the worker timeout. Note `fetchStrategyReport()` caps its own HTTP timeout at
`min(services.lighthouse.timeout, maxSeconds ?? 80)` for the same reason.

## AuditPerformanceService

`app/Services/AuditPerformanceService.php` — `underperformingPages()` joins persisted
`PageAuditReport` blobs with GSC traffic to surface **"technical debt measurably costing
traffic"**: pages with `>=100` impressions in the window whose worst (min of mobile/desktop)
`core_web_vitals.*.performance_score` is `<70`. Sorted by impressions, capped at `limit`. Pure
read over already-stored data — no fetch, no Lighthouse call.

## External service / config

Non-secret env (`config/services.php:99`):
- `LIGHTHOUSE_API_URL` — base URL of the `ebq-intelegence` Lighthouse service.
- `LIGHTHOUSE_API_KEY` — sent as `X-Api-Key`.
- `LIGHTHOUSE_TIMEOUT_S` (default 90) — HTTP timeout ceiling.

The service exposes `/audit/batch` (multiple `{url, strategy}` items, returns trimmed results)
and `/audit?raw=1` (one strategy, returns the full LHR under `raw`). Both authed by `X-Api-Key`.

## Gotchas / known issues

- **CWV silently absent.** If the Lighthouse service is down/misconfigured the audit's
  `result.core_web_vitals` key is simply missing — UI must branch on its absence, not treat
  empty as zero. `fetchMobileAndDesktop()` returns null unless ≥1 strategy succeeds.
- **Worker-timeout coupling.** Job `timeout=88` and HTTP `maxSeconds=80` are tuned to the
  worker's `--timeout=90`. Raising the worker timeout without raising these wastes the margin;
  lowering it below 88 will start killing jobs mid-run.
- **No DB persistence for the PageSpeed tool** — results live only in the 30-min cache; a
  page reload after expiry loses the report (the tool is fire-and-view, not historical).
- **`fetchFullReport()` is synchronous** (two sequential calls) and is *not* used by the async
  tool — it exists for callers that can afford to block. Don't wire it into a web request.

## Key files

- `app/Services/LighthouseClient.php`
- `app/Jobs/RunPageSpeedStrategy.php`
- `app/Livewire/Pages/PageSpeed.php`
- `app/Services/AuditPerformanceService.php`
- `config/services.php` (`lighthouse` block)
