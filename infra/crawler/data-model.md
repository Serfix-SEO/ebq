# Data model & schema

The crawl subsystem is **5 tables + 1 per-user overlay**, all keyed by `crawl_site_id`
(see [architecture.md](./architecture.md) for the why). This doc is the concrete schema +
model reference.

## Entity map

```
                       ┌──────────────┐
 many users' websites  │  crawl_sites │  one row per normalized domain
  websites.crawl_site_id ─▶│ effective_cap, health, status,        │
                       │   protection, lastmod-trust            │
                       └──────┬───────┘
       ┌───────────────┬──────┴─────────┬────────────────┐
       ▼               ▼                ▼                ▼
 website_pages   website_internal   crawl_runs       crawl_findings
 (+ value_rank)  _links             (per crawl)      (shared catalog, impact=0)
       ▲               ▲ from/to_page_id                  │ page_id
       └───────────────┴──────────────────────────────────┘

 per-user overlay:  website_finding_states (website_id, finding_id, status)
```

## Models

| Model | Table | Crawl-relevant role |
|---|---|---|
| `CrawlSite` | `crawl_sites` | Domain entity. `normalizeDomain()`, `recomputeEffectiveCap()`, `runningCrawl()/isCrawling()/isCrawlProtected()`, `homepageUrl()`, lastmod-trust helpers. Relations: `websites/pages/internalLinks/crawlRuns/crawlFindings`. |
| `Website` | `websites` | Subscriber. `crawl_site_id` + `normalized_domain`; `saving/saved/deleted` hooks; `crawlPageCap()`; crawl helpers delegate to `crawlSite`. |
| `CrawlRun` | `crawl_runs` | One crawl. Statuses `running/finalizing/completed/failed/aborted`; counters; `health_score`; `isBlocked()`. |
| `WebsitePage` | `website_pages` | Shared page inventory + `value_rank`. Scopes `indexable/orphans/due`; `hashUrl()`. |
| `WebsiteInternalLink` | `website_internal_links` | Shared link graph (`status` = `discovered`/`suggested`); `fromPage/toPage`. |
| `CrawlFinding` | `crawl_findings` | Shared finding catalog. `impact` stored 0; `hashUrl()`. |
| `WebsiteFindingState` | `website_finding_states` | **Per-user** overlay (`open/ignored/resolved`) on a shared finding. No row = open. |

## Schema (key columns / indexes)

### `crawl_sites`
`normalized_domain` **unique**, `effective_cap`, `health_score`, `status`
(`pending/crawling/ready/blocked`), `subscriber_count`, `crawl_protection` +
`crawl_protection_at`, `sitemap_lastmod_true/false`, `last_crawl_started_at/finished_at`.

### `websites` (crawl columns added)
`crawl_site_id` → `crawl_sites` (**nullOnDelete**), `normalized_domain` (+ index). Legacy
`crawl_protection*` / `sitemap_lastmod_*` remain but are superseded by the crawl_site copies.

### `website_pages`
- Keys: **unique** `(crawl_site_id, url_hash)`; indexes on `(crawl_site_id, last_crawled_at)`,
  `(…, next_crawl_at)`, `(…, is_indexable)`, `(…, inbound_link_count)`, `(…, value_rank)`.
- Identity/value: `url`, `url_hash`, **`value_rank`** (nullable), `source_gsc`, `source_sitemap`,
  `page_score`.
- Crawl state: `http_status`, `is_indexable`, `robots_directives`, `redirect_target`,
  `canonical_url`, `etag`, `last_modified_header`, `http_error`.
- Content: `title`, `meta_description`, `headings_json`, `seo_signals`, `word_count`,
  `content_hash` (sha1), `content_simhash` (16), `content_terms` (JSON), `body_text`.
- Graph: `internal_link_count`, `external_link_count`, `inbound_link_count`, `click_depth`.
- Recrawl: `last_crawled_at`, `discovered_at`, `last_changed_at`, `next_crawl_at`, `removed_at`,
  `consecutive_unchanged`, `sitemap_lastmod`.

### `website_internal_links`
`crawl_site_id`, `from_page_id`, `to_page_id`, `anchor_text`, `status`
(`discovered`/`suggested`), `discovered_at`. Index `(crawl_site_id, status)`.

### `crawl_runs`
`crawl_site_id`, `trigger` (`scheduled/on_create/manual/backfill/sitemap_delta/reused`),
`status`, `started_at/finished_at`, counters `pages_seen/fetched/304/changed/error`,
`findings_total`, `health_score`, `blocked_reason`, `notes`. Indexes
`(crawl_site_id, started_at)`, `(crawl_site_id, status)`.

### `crawl_findings`
- Keys: **unique** `(crawl_site_id, type, affected_url_hash)`; indexes
  `(crawl_site_id, category, status, impact)` and `(…, category, status, type, impact)`.
- `page_id` (nullOnDelete), `crawl_run_id` (nullOnDelete), `category`, `type`, `severity`,
  `impact` (double, **stored 0**), `affected_url` + `affected_url_hash`, `detail` (JSON),
  `status` (`open/resolved/ignored`), `first_seen_at/last_seen_at/resolved_at`.

### `website_finding_states`
`website_id` (**cascadeOnDelete**), `finding_id` (**cascadeOnDelete**), `status`,
`resolved_at`. **unique** `(website_id, finding_id)`.

> The four crawl tables also have a **nullable, FK-dropped, unused `website_id`** (rollback
> cruft from the re-key). Dropping the `website_id` cascade FK is what lets one subscriber be
> deleted without wiping the shared crawl. See [known-issues.md](./known-issues.md).

## `normalizeDomain()`

`CrawlSite::normalizeDomain($d)`: lowercase+trim → strip `https?://` → strip path/query/frag
→ strip leading `www.` → rtrim `.`. So `https://www.Basepaws.com/path?q=1` → `basepaws.com`.
Empty result → the `Website` saved hook skips linking (placeholder row).

## Lifecycle (Website hooks)

- **saving** — sync `normalized_domain` from `domain`.
- **saved** — if domain present and (unlinked or domain changed): `CrawlSite::firstOrCreate`
  by normalized_domain, link `crawl_site_id` (`saveQuietly`), then `recomputeEffectiveCap()`.
- **deleted** — if `crawl_site_id`: load the site; if **0 subscribers remain**, delete its
  `crawl_findings → website_internal_links → crawl_runs → website_pages` (by `crawl_site_id`)
  then the site (GC); else just `recomputeEffectiveCap()`.

`recomputeEffectiveCap()` = `max(crawlPageCap)` over all subscriber websites, and updates
`subscriber_count`.

**`effective_cap` is a stored aggregate, not computed live** — it's only refreshed by the
three triggers above (website create/link, website delete, first-ever crawl_site link in
`CrawlWebsitePagesJob::resolveCrawlSite`). It does **not** self-heal on recrawl once a
website is already linked. A plan upgrade/downgrade alone never saves a `Website` row, so
`ClientController::update()` (admin comp-plan) and `StripeWebhookController::syncPlanSlugFromStripeCustomer()`
(subscription webhook) both explicitly call `recomputeEffectiveCap()` for the user's
websites right after writing `current_plan_slug` (fixed 2026-07-03 — a legacy→trial plan
switch left `effective_cap` pinned at the old cap indefinitely, since no code path
recomputed it on plan change).

## Subscribe charge

`CrawlSiteBootstrapper::subscribeWebsite()` logs a `client_activities` row via
`ClientActivityLogger`: `type='crawl.subscribed'`, `provider='crawl_reuse'`,
`units_consumed = min(crawled_pages, cap)`, attributed to the website owner. `cap` here
**is** the enforced budget — `Website::crawlPageCap()` is the real enforcement point
(hard per-site ceiling + account-pooled remaining quota, see plans-and-gating.md). It then
dispatches a crawl **only if** no completed run exists or this user's cap exceeds what's
already crawled (otherwise instant reuse).
