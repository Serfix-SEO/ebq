# Findings catalog & health scoring

How the crawler turns a crawled page inventory + link graph into the issues users see,
and how the 0–100 health score is computed.

Source: `app/Services/Crawler/SiteIssueDetector.php`,
`app/Jobs/AnalyzeSiteJob.php::scorePages`.

## Shared catalog, per-user view

Findings are a **shared catalog** on the crawl_site, upserted by
`(crawl_site_id, type, affected_url_hash)`. Each row stores:

- a **click-independent base `severity`** (critical / high / medium / low), and
- `impact` = **0**, always.

Per-user **impact** (clicks-at-risk) and any **click-conditional severity escalation** are
computed **read-time** from the requesting website's own `SearchConsoleData` — see
[read-path.md](./read-path.md). Clicks are aggregated across **all** subscribers (28-day
window) only to decide *whether* a click-conditional finding/severity is emitted; the
stored row never carries any user's traffic. This is the core privacy invariant
(see [architecture.md](./architecture.md)).

## Categories → types

| Category | Types | Severity rule |
|---|---|---|
| `broken_link` | `broken_page` (known URL → 4xx, excl. 429) | critical if trafficked, else high |
| | `broken_internal` (internal link → 4xx target, excl. 429) | critical if trafficked, else high |
| | `broken_external` (external link → 4xx/5xx) | medium — **sampled** ≤25 ext links/page, ≤500 checks total |
| `redirect` | `redirecting_url` (page itself 3xx) | medium if trafficked, else low |
| | `external_redirect` (outbound link redirects) | low |
| `internal_links` | `orphan_page` (0 inbound, not in sitemap, not homepage, no query string) | high if trafficked, else medium |
| | `deep_page` (`click_depth ≥ deep_page_threshold`, default 3) | medium if trafficked, else low |
| `indexability` | `noindex_important` (noindex + 200 + trafficked) | high |
| | `canonical_mismatch` (canonical points away + trafficked) | high |
| `onpage` | `missing_title` | high if trafficked, else medium |
| | `title_too_long` (>60), `title_too_short` (<15) | low |
| | `missing_meta_description` | medium if trafficked, else low |
| | `meta_description_too_long` (>160) | low |
| | `missing_h1` | medium if trafficked, else low |
| | `multiple_h1`, `broken_heading_order` | low |
| | `thin_content` (0 < words < 200) | medium if trafficked, else low |
| | `missing_image_alt`, `missing_open_graph` | low |
| `schema` | `missing_structured_data` | low |
| `sitemap` | `indexed_not_in_sitemap` (GSC + indexable, not in sitemap) | low |
| `crawlability` | `crawl_blocked` (site wholesale-blocked) | critical — `page_id` null → always in-window for every user |

"Trafficked" = the aggregate 28-day click query crosses the importance threshold for that
URL. At read time it is re-evaluated against the *individual* user's clicks.

**5xx/429 excluded from `broken_page`/`broken_internal`** (2026-07-03): both are transient
(rate-limit, bot-protection, temp outage), not proof the page is gone. Only hard 4xx
client-errors (404/410/400/403/…) mean genuinely dead/forbidden. A block response also no
longer overwrites `http_status` on the page row (`PageCrawlProcessor`), so a captcha/429 hit
can't masquerade as the page itself being broken.

**`broken_external` requires positive evidence, never a failed-to-verify** (2026-07-13):
`detectBrokenExternalLinks()` raises the finding only on a CONFIRMED HTTP error
(`status >= 400`) or a DETERMINISTIC `SafeHttpGuard` rejection (malformed/unsafe URL —
`LinkChecker` row `guard_blocked = true`). A **null** status (HEAD+GET+proxy all timed out /
reset / TLS-failed) is inconclusive and is logged (`crawler.broken_external.unverifiable_skip`),
not flagged — slow-but-alive government/enterprise hosts were being reported dead purely on
8s HEAD timeouts. `LinkChecker` also now runs its GET+proxy fallback (15s) for transport
errors, not just for 403/405/429/501. See known-issues § "Live-but-slow external links".

**Duplicate-title/meta/content skip if same `canonical_url`** (2026-07-03): pages that all
canonicalize to one URL are intentional dedup (pagination, filters, print views), not a bug —
`detectDuplicateField`/`detectDuplicateContent` skip the group when `canonical_url` is
identical across all members.

## Referrer attachment (404 provenance)

For every broken internal target, `SiteIssueDetector::loadBrokenReferrers` streams the
inbound edges and attaches up to **5 sampled referrers** (`url` + `anchor_text`) to the
finding's `detail.referrers` (global scan cap ~50k edges). This is the "which page links
to this dead URL" data surfaced for broken-link findings.

## Stale resolution

After detection, findings whose `last_seen_at` predates the run's `started_at` are marked
`resolved` — i.e. an issue fixed since the last crawl auto-closes.

## Health score (`scorePages`)

1. Every crawled **indexable** page (`is_indexable`, not removed) starts at `page_score = 100`.
2. For each **open** finding attached to that page (`page_id` set, page indexable), subtract a
   severity penalty: **critical 40 / high 25 / medium 12 / low 5**.
3. `page_score = max(0, 100 − Σ penalties)`.
4. `health_score = avg(page_score)` over all crawled indexable pages.
5. Persisted to **both** `crawl_runs.health_score` (per-run) and `crawl_sites.health_score`
   (latest).

Note the health score is computed from the **shared** catalog (base severities), so all
subscribers see the same site-level health number; only the per-user *issue lists* and
*impact ordering* differ by cap + GSC.
