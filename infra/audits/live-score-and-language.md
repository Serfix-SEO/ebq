# Live SEO score & language detection

## LiveSeoScoreService

`app/Services/LiveSeoScoreService.php` (`score()` `:81`) composes a **0–100 "live SEO score"**
for one URL by projecting slices of *every data source we already have* — no LLM, no extra
fetch. It also **gates the audit run**: if the URL has never been audited it auto-queues a
`CustomPageAudit`/`RunCustomPageAudit` and returns `audit.status = "queued"` for the client to
poll. Primary consumer: the WP plugin editor / HQ "live score" widget
(`PluginInsightsController`, `PluginHqController`).

### Inputs → factors

13 factors, each `{key, label, score, weight, pending?, …}`. The composite is a weighted mean
of the non-`pending` factors (`:347`):

| Group | Factors | Source | Pending when… |
|---|---|---|---|
| GSC-only | `rank`, `ctr`, `coverage`, `cannibalization` | `SearchConsoleData` (30-day) | `<10` impressions in window (`MIN_GSC_IMPRESSIONS`) |
| Always-on | `indexing` | `PageIndexingStatus` (null-safe) | never |
| Always-on | `backlinks` | `Backlink` table + KE sync | never |
| Audit blob | `core_web_vitals`, `page_performance`, `on_page_seo`, `technical_health`, `content_quality`, `keyword_alignment`, `recommendations` | latest completed `PageAuditReport` | no audit ready |
| Nudge | `tracked` | `RankTrackingKeyword` | — |

A brand-new URL (no GSC yet) still scores from 9 of 13 factors; `partial: true` +
`partial_reason` drive an advisory banner.

### URL matching
GSC rows are matched via `PluginInsightResolver::__publicPageVariants()` /
`__publicApplyPageMatch()` — tolerant of trailing-slash, www/apex, scheme, and case drift, so
the score isn't blanked by URL normalization mismatches.

### Audit gating (`resolveAuditState()` `:445`)
- **`ready`** — a completed `PageAuditReport` exists and isn't stale → use it. **We never
  re-audit once a completed report exists** unless the post's `modified` time is newer than
  `audited_at` (`auditStale`).
- **`blocked`** — `postStatus` is non-public (`draft`/`pending`/`private`/`future`/
  `auto-draft`/`inherit`/`trash`): the external auditor would 404 / hit a login redirect, so we
  **don't queue a guaranteed-to-fail job** and return copy telling the user to publish.
- **`refreshing`** — a newer post-modified time → an in-flight re-audit over prior good data.
- **in-flight dedup** — returns the existing queued/running `CustomPageAudit` instead of
  queueing a duplicate, but **caps in-flight age at 15 min**: a zombie row (worker OOM/deploy
  mid-job) is skipped so a fresh audit can recover automatically.
- otherwise → `CustomPageAudit::queue(SOURCE_LIVE_SCORE)` + `RunCustomPageAudit::dispatch()`
  (lite mode — see page-audit doc). `:545`

### Score suppression
When the audit is `blocked` AND GSC is empty, the composite collapses to just
indexing+backlinks+tracked — too thin to grade — so `score` is nulled and the chip shows "—"
(`label` becomes `Awaiting publish`/`Awaiting data`) while the per-factor breakdown still
renders. `:368`

### Labels
`score >= 65 → Good`, `>= 45 → Needs work`, else `Bad`.

## LanguageDetectorService

`app/Services/LanguageDetectorService.php` — detects the language of short SEO strings
(queries, titles). Used to set `hl` for Serper/SERP locale and content-strategy calls.

Two-stage, tuned for **short, brand-heavy inputs** where the raw n-gram detector is unreliable:
1. **Fast-path word vote** (`fastPathLanguage()`) — counts per-language function/content-word
   hits (`FASTPATH_WORDS`, accent-folded) and returns the clear winner. Beats the n-gram
   detector on inputs like `generador de nombres de free fire` (es 0.494 vs da 0.493).
2. **n-gram detector** (patrickschur `Language`) restricted to `ALLOWED` (top ~44 web
   languages — the full ~400-model set lets obscure languages "win" short inputs by noise).
   Requires `MIN_SCORE = 0.45` and `MIN_MARGIN = 0.03` over #2, else returns `null`.

Guards: `MIN_CHARS = 10` (shorter → null). Results cached 30 days
(`lang-detect:v5:{sha1}`) + per-request memoized; bump `CACHE_VERSION` to invalidate.
Region subtags are stripped to a bare ISO-639-1 code (except `zh-Hans`/`zh-Hant`). Disable via
`services.language_detection.enabled = false`.

## Gotchas / known issues

- **Fixed 2026-07-06 — independent content-hash re-audit gate added.** The live score still
  only refreshes on a newer post-`modified` timestamp (unchanged — that path is "no extra
  fetch" by design and stays that way). But `PageAuditReport.content_hash` (sha256 of the
  audited page's extracted body text) is now stored on every completed audit, and a new
  scheduled command, `ebq:recheck-audit-content` (hourly, `--limit=200`, `routes/console.php`),
  independently re-fetches a bounded batch of the oldest completed audits and queues a fresh
  one only if the hash actually changed — catching the "WordPress didn't report `modified`
  correctly" case the timestamp check misses, without slowing the interactive live-score
  request path. See `PageAuditService::currentContentHash()` +
  `app/Console/Commands/RecheckAuditContent.php`. Covered by
  `tests/Feature/RecheckAuditContentTest.php`.
- **Sparse-GSC honesty.** `1–9` impressions is treated like "no data" (factors pending) — a
  deliberate choice so "avg position 69 across 2 queries" doesn't masquerade as signal.
- **Language detection is best-effort on tiny inputs.** Below 10 chars or on ties it returns
  null; callers must default `hl` (usually via `SerpLocaleDefaults`).

## Key files

- `app/Services/LiveSeoScoreService.php`
- `app/Services/LanguageDetectorService.php`
- `config/services.php` (`language_detection` block)
