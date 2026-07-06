# Database — master schema map

The authoritative index of EBQ's relational schema: **83 tables**, **49 models**. Use this
to find which table owns what, how rows cascade, and where the deep docs live.

> **Three subsystems are documented in depth elsewhere** — this doc gives them a one-line
> entry + cross-link, and spends its depth on everything else:
> - **Crawl** (`crawl_sites`, `website_pages`, `website_internal_links`, `crawl_runs`,
>   `crawl_findings`, `website_finding_states`) → [crawler/data-model.md](../crawler/data-model.md)
> - **Google/source data** (`google_accounts`, `microsoft_accounts`, `search_console_data`,
>   `analytics_data`, `page_indexing_statuses`) → [data-sources/data-model.md](../data-sources/data-model.md)
> - **Billing/usage** (`plans`, `subscriptions`, `subscription_items`, `client_activities`) →
>   [billing/plans-and-gating.md](../billing/plans-and-gating.md), [billing/usage.md](../billing/usage.md)

## Engine & conventions

- **Driver `mysql` on MariaDB 10.11** (`10.11.14-MariaDB`, Ubuntu 24.04). Laravel's `mysql`
  grammar targets it; a few migrations use MySQL-online DDL (`ALGORITHM=INPLACE, LOCK=NONE`)
  and are written re-entry-safe + no-op on sqlite (dev/test). **Production has no DB backups**
  — see root `CLAUDE.md`. Tests run on **sqlite `:memory:`** (`phpunit.xml`), guarded by
  `tests/TestCase`.
- **Migration naming**: `0001_01_01_*` for the four framework tables (users/cache/jobs), then
  real-time `YYYY_MM_DD_HHMMSS_<verb>_<table>` from `2026_04_14` onward. `create_*_table` =
  new table (78 files); `add_*` / `*_to_*_table` = ALTER (55 files). Many domain tables are
  built up across several ALTERs — always grep all migrations touching a table, not just the
  `create_`.
- **FK delete semantics** are load-bearing and consistent: **fact/child tables `cascadeOnDelete`
  on their owning `website_id`/`user_id`**; **cross-references `nullOnDelete`** (deleting a
  Google account / actor / parent degrades, doesn't cascade away the website). Pivots cascade
  both sides.
- **Hash columns**: long URLs/paths are indexed via a `char(64)` sha256 sidecar
  (`url_hash`, `page_hash`, `query_hash`, `source_path_hash`, `referring_page_hash`) because the
  raw `varchar(700)`/`2048` is too long for a utf8mb4 key. Three *different* keyword-hash
  schemes coexist — see gotcha below.
- **Encrypted-at-rest columns** (model `encrypted` cast, stored as `text` ciphertext): OAuth
  tokens (`google_accounts`, `microsoft_accounts`), `keyword_api_servers.{api_key,webhook_secret}`,
  `mail_transports.smtp_password`. Never log or query them raw.

## The crawl re-key (why crawl tables don't have a `website_id` FK)

`website_pages` / `website_internal_links` were originally **website-keyed**
(`2026_05_06_1012/1014`), crawl was added in `2026_06_12_010*`. On **`2026_06_17`** the whole
crawl store was **re-keyed to a shared `crawl_site_id`** (one crawl per normalized domain,
N subscribers): `…000000 create_crawl_sites`, `…000100 add_crawl_site_links` (adds
`crawl_site_id` + `normalized_domain` to `websites`), `…000200 backfill_crawl_sites`,
`…000300 create_website_finding_states` (the per-user overlay). The four crawl tables still
carry a **nullable, FK-dropped, unused `website_id`** (safe-rollback leftover; nothing
reads it). Full story: [crawler/known-issues.md](../crawler/known-issues.md),
[crawler/data-model.md](../crawler/data-model.md).

---

# Domains

Legend: **→cascade** = `cascadeOnDelete`, **→null** = `nullOnDelete`. "no model" = accessed via
query builder only (JSON columns are **not** auto-cast — callers decode manually).

## 1. Users / auth

| Table | Purpose | Key cols / index | Relations |
|---|---|---|---|
| `users` | The human account; owns websites; **billing lives here** (since the user-keying). `User.php`, `0001_01_01_000000` + ALTERs. | `email` **unique**; Cashier snapshot `stripe_id`(idx), `current_plan_slug`(32, idx), `pm_*`, `trial_ends_at`; `is_admin`/`is_disabled`; `timezone`; `last_growth_report_sent_at`. | hasMany `websites`, `googleAccounts`, `customPageAudits`, `aiWriterPrompts`; team via `website_user`. |
| `password_reset_tokens` | Framework. `email` PK, `token`. | — | — |
| `sessions` | Framework DB sessions. `user_id`(idx), `last_activity`(idx). | — | — |
| `personal_access_tokens` | **Sanctum** — backs `Website`'s `HasApiTokens` (websites are the token-bearing entity for the WP plugin, **not** users). `2026_04_21_110000`. `tokenable` morphs, `token`(64) **unique**. | — | — |

`User.php` traits: **`Billable`** (Cashier), `Notifiable`, implements `MustVerifyEmail`. `booted::created`
→ `Lead::markConvertedFor()`. Tier ladder `TIER_ORDER` (free<pro<startup<agency); plan resolved
by `effectivePlan()`/`effectiveTier()` (honors `FREE=true` promo → everyone to Pro).

## 2. Websites (the central table)

One audited site. `Website.php`, `2026_04_14_200813_create_websites_table.php` + **~12 ALTERs**.
Tier/billing are **derived from the owning user**, not stored here.

- **Identity**: `user_id` **→cascade**, `domain`, `normalized_domain`(idx) — synced from `domain`
  on every save (`saving` hook).
- **Google source cols** (deep doc: data-sources): `gsc_site_url`, `ga_property_id`,
  `gsc_google_account_id`/`ga_google_account_id` (nullable FK→`google_accounts` **→null**;
  presence = `hasGsc()`/`hasGa()`), `gsc_keyword_lookback_days`, `last_*_sync_at`.
- **Crawl cols** (deep doc: crawler): `crawl_site_id` (FK→`crawl_sites` **→null**),
  `crawl_protection`/`_at`, `sitemap_lastmod_true`/`_false`.
- **Reporting/alerts**: `report_recipients` (json, array), `last_traffic_drop_alert_at`,
  `last_rank_drop_alert_at`.
- **Feature flags**: `feature_flags` (json, array) — per-site override that can only **narrow**
  the plan ceiling; 8 keys mirror `Website::FEATURE_KEYS`. Resolved by `effectiveFeatureFlags()`.

**Index gotcha**: the original unique `(user_id, ga_property_id, gsc_site_url)` was **dropped and
replaced** with unique `(user_id, domain)` (`2026_06_14_110000`) — sourceless sites store GA/GSC
as `''` and collided.

**Billing-cols-removed gotcha**: `tier`, `stripe_*`, `pm_*`, `trial_ends_at` were once **on
`websites`** then **dropped** (`2026_05_02_100000`) when billing moved to `users`; only
`feature_flags` was restored (`…120000`). They do **not** exist on `websites` today.

`Website.php` traits: **`HasApiTokens`** (Sanctum, for the plugin). `booted` hooks:
`saving`→normalized_domain, `saved`→link/create CrawlSite, `deleted`→GC orphan crawl site,
`created`→dispatch 365-day GA/GSC backfill, `updated`→`ReprocessCompetitiveData` on GSC connect.

### Website-scoped supporting tables

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `website_user` | Team-membership **pivot** — carries `id`, timestamps, `role`(32), `permissions`(json). Logic in `app/Support/TeamPermissions.php`. `2026_04_15_140000` (+ role/perms `2026_04_20_110000`). | unique `(website_id, user_id)`. | both **→cascade**. |
| `website_invitations` | Pending team email invites. `WebsiteInvitation.php`. | unique `(website_id, email)`; `token`(128) sha256; `role`/`permissions`(json). | `website_id`/`invited_by_user_id` **→cascade**. |
| `website_sitemaps` | Sitemaps per site (GSC or manual), with submitted/indexed counts. `WebsiteSitemap.php`, `2026_06_12_000000`. | unique `(website_id, path)`; `source`(gsc/manual), `type`. | `website_id` **→cascade**. |
| `website_plugin_installs` | WP-plugin install heartbeat (one row/site). `WebsitePluginInstall.php`. | `website_id` **unique**; `channel`, `installed_version`, `last_seen_at`. | `website_id` **→cascade**. |

## 3. Crawl  *(deep doc: [crawler/data-model.md](../crawler/data-model.md))*

| Table | One-liner |
|---|---|
| `crawl_sites` | One row per normalized domain — the shared-crawl entity. `effective_cap`, `health_score`, `status`, lastmod-trust. `CrawlSite.php`. |
| `website_pages` | Shared page inventory + `value_rank`; unique `(crawl_site_id, url_hash)`. `WebsitePage.php`. |
| `website_internal_links` | Shared internal link graph (`from_page_id`/`to_page_id`). `WebsiteInternalLink.php`. |
| `crawl_runs` | One crawl execution; counters + `health_score`. `CrawlRun.php`. |
| `crawl_findings` | Shared finding catalog; unique `(crawl_site_id, type, affected_url_hash)`. `CrawlFinding.php`. |
| `website_finding_states` | **Per-user** open/ignored/resolved overlay on a shared finding; unique `(website_id, finding_id)`, both **→cascade**. `WebsiteFindingState.php`. |

## 4. Google / source data  *(deep doc: [data-sources/data-model.md](../data-sources/data-model.md))*

| Table | One-liner |
|---|---|
| `google_accounts` | Connected Google login; tokens **encrypted**; unique `(user_id, google_id)`. `GoogleAccount.php`. |
| `microsoft_accounts` | Connected Outlook login (mail send only); same shape. `MicrosoftAccount.php`. |
| `search_console_data` | The **big** GSC fact table (query×page×country×device×date); unique `sc_unique (website_id,date,query,page,country,device)`; covering indexes: `(website_id,date)`, `(website_id,date,query)`, `(website_id,page,date)` ← PageDetail, `(website_id,country)` ← CountryFilter/top-countries, `(website_id,query,clicks,impressions,ctr,position)`, `(website_id,date,position)`. Use `->where('date','>=',…)` not `->whereDate(…)` — the latter wraps in `date()` and kills the index. `SearchConsoleData.php`. |
| `analytics_data` | GA4 daily traffic-by-source; unique `(website_id, date, source)`. `AnalyticsData.php`. |
| `page_indexing_statuses` | GSC URL-Inspection verdict per page; unique `(website_id, page)`. `PageIndexingStatus.php`. |

## 5. Keywords (catalog, cache, gap, finder fleet)

`keywords` is the **global, un-scoped source of truth for keyword text** — every client asking
the same query shares one row, and ~10 tables FK to it.

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `keywords` | Global keyword text catalog. *No model.* `2026_05_06_100000`. | unique `(query_hash, country, language)`; `embedding`(binary, unused phase-1). country defaults **`global`**. | — |
| `keyword_metrics` | KE/GKP volume+CPC cache, 30-day TTL, no website scope. `KeywordMetric.php`, `2026_04_22_120000` (+bid-range `2026_06_13`). | unique `(keyword_hash, country, data_source)`; `expires_at`(idx); `trend_12m`(json), `low_/high_top_of_page_bid`. | — |
| `keyword_intelligence` | Derived per-keyword difficulty/SERP-strength/intent/volatility. *No model.* | unique `keyword_id` (one/keyword). | `keyword_id`**→cascade**. |
| `keyword_alerts` | Per-website research signals (ranking_drop, opportunity…). *No model.* | `(website_id, type, created_at)`(idx); `payload`(json). | `website_id`**→cascade**, `keyword_id`**→null**. |
| `keyword_clusters` | Hierarchical keyword clusters (self-parent + centroid). *No model.* | — | self **→null**, `centroid_keyword_id`**→null**. |
| `keyword_cluster_map` | keyword↔cluster pivot + confidence. *No model.* | **composite PK** `(keyword_id, cluster_id)`. | both **→cascade**. |
| `keyword_api_servers` | Fleet of self-hosted Keyword-Planner API servers (load balancer pool). `KeywordApiServer.php`, `2026_06_13_100000`. | `(is_active, is_healthy)`(idx); `api_key`/`webhook_secret` **encrypted**. | — |
| `keyword_api_requests` | Async correlation/result store (request_id → webhook). `KeywordApiRequest.php`. | `request_id`(uuid) **unique** (route key); `payload`/`result`(json), `status`. | `keyword_api_server_id`/`user_id`/`website_id` **→null**. |
| `keyword_gap_analyses` | Header for one gap-analysis run; tracks in-flight `request_ids`. `KeywordGapAnalysis.php`, `2026_06_14_100020` (+verify ALTER). | `(website_id, created_at)`; `competitor_urls`/`request_ids`/`summary`(json); 30-day TTL; `verify_*`, `reprocessed_at`. | `website_id`**→cascade**, `user_id`**→null**. |
| `keyword_gap_rows` | Diffed output, one row/keyword, bucketed missing/weak/strength/shared. `KeywordGapRow.php`. | `(keyword_gap_analysis_id, bucket)`; `opportunity_score`, `competitor_position`. | analysis **→cascade**. |
| `content_briefs` | Persisted content briefs (outline, PAA, target word count). **No model, zero `app/` refs — currently orphaned.** `2026_05_06_101600`. | `(website_id, created_at)`; `payload`(json). | `website_id`/`keyword_id`**→cascade**, `created_by`**→null**. |

## 6. Rank tracking

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `rank_tracking_keywords` | A user-configured keyword to track per site/engine/geo/device. `RankTrackingKeyword.php`, `2026_04_20_100000`. | **8-col unique `rtk_unique`** (website, keyword_hash, engine, type, country, language, device, location); `(next_check_at,is_active)`; `competitors`/`tags`(json). defaults `us`/`en`. | `website_id`/`user_id` **→cascade**. |
| `rank_tracking_snapshots` | Point-in-time ranking-check result. `RankTrackingSnapshot.php`. | `(rank_tracking_keyword_id, checked_at)`; 5 json cols (serp_features, competitor_positions…); **no unique** (dup snapshots possible). | keyword **→cascade**. |

## 7. SERP  *(three unrelated families)*

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `serp_cache` | Global cross-client live-SERP cache, 7-day TTL. `SerpCacheEntry.php` (`$table='serp_cache'`), `2026_06_14_100050`. | unique `(query_hash, gl)`; hash = sha256(`keyword\|gl`). | — |
| `serp_snapshots` | One row per SERP provider fetch (feature tracker), FK'd to **`keywords`**. *No model.* | daily-unique `(keyword_id, device, country, location, fetched_on)`. | `keyword_id`**→cascade**. |
| `serp_results` | Top-N organic/universal rows per snapshot. *No model.* | `(snapshot_id, rank)`; `is_low_quality`. | snapshot **→cascade**. |
| `serp_features` | Non-organic enrichments (PAA, knowledge panel…). *No model — `payload` JSON not auto-cast.* | `(snapshot_id, feature_type)`. | snapshot **→cascade**. |

> `rank_tracking_snapshots` (above) is the **third**, separate SERP lineage — keyed to a tracked
> keyword, not the global `keywords` catalog.

## 8. Backlinks & competitive

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `backlinks` | The client's **own** inbound link profile. `Backlink.php`, `2026_04_15_120000` (+audit ALTER). | `(website_id, tracked_date)`, `(website_id, type)`; `type` = `BacklinkType` enum cast; `audit_result`(json). | `website_id`**→cascade**. |
| `competitor_backlinks` | Universal (NOT site-scoped) cache of competitor inbound links. `CompetitorBacklink.php`. | unique `(competitor_domain, referring_page_hash)`; `expires_at`. **No FK** (pure cache). | — |
| `competitor_discovery_runs` | Lifecycle + SERP-cost ledger for one auto-discovery run. `CompetitorDiscoveryRun.php`, `2026_06_14_100000`. | `run_id`(uuid) **unique** (route key). | `website_id`**→cascade**, `user_id`**→null**. |
| `discovered_competitors` | Ranked competitor list per site (upserted/pruned per run). `DiscoveredCompetitor.php`. | unique `(website_id, competitor_domain)`; `sample_keywords`(json); `run_id`(plain uuid, **no FK**). | `website_id`**→cascade**. |
| `competitor_scans` | One admin-triggered competitor scrape (Python worker writes progress/heartbeat). *No model.* | `(status, created_at)`, `(seed_domain, status)`; `progress`/`caps`(json). | `website_id`/`triggered_by_user_id` **→null**. |
| `competitor_scan_keywords` | Per-(scan, keyword) ranking + top-N pages cache. *No model.* | unique `(competitor_scan_id, keyword_id)`. | both **→cascade**. |
| `competitor_pages` | Pages crawled in a scan — **deliberately separate from `website_pages`** (privacy). *No model.* | unique `(competitor_scan_id, url_hash)`; `body_text`(longText), `headings_json`(json). | scan **→cascade**. |
| `competitor_outlinks` | Outbound links from scan pages. *No model.* | `(competitor_scan_id, is_external)`, `to_domain`. | scan + `from_page_id` **→cascade**. |
| `competitor_topics` | Clustered topics from a scan's pages. *No model.* | `top_keyword_ids`(json). | scan **→cascade**, `centroid_keyword_id`**→null**. |
| `competitor_topic_pages` | topic↔page **pivot**. *No model.* | **composite PK** `(competitor_topic_id, competitor_page_id)`. | both **→cascade**. |

## 9. Outreach / research

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `research_targets` | Continuous-research queue of domains to scrape (`ebq:research-scan-next`). *No model.* `2026_05_07_110000`. | `domain` **unique**; `(status, priority, next_scan_at)`. | `attached_website_id`/`last_scan_id` **→null**. |
| `outreach_prospects` | Backlink-prospecting rows, deduped per (website × referring_domain). `OutreachProspect.php`, `2026_04_28_100000`. | unique `(website_id, referring_domain)`; `latest_draft`/`anchor_examples`(json), `status`. | `website_id`**→cascade**. |
| `redirect_suggestions` | AI-suggested 301s from WP-plugin 404 logs, awaiting review. `RedirectSuggestion.php`, `2026_04_27_120000`. | unique `(website_id, source_path_hash)`; `confidence`, `status`, `hits_30d`; paths `varchar(700)`. | `website_id`**→cascade**. |

## 10. Niches (taxonomy & cross-client aggregates)  *(all no-model)*

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `niches` | Hierarchical niche taxonomy (curated + auto-discovered, gated by `is_approved`). `2026_05_06_100700`. | `slug` **unique**; `is_dynamic`/`is_approved`; `embedding`(binary). | self `parent_id`**→null**. |
| `niche_aggregates` | **Anonymised** cross-client aggregates per niche (optionally keyword-scoped); no `website_id`. | unique `(niche_id, keyword_id)` (keyword nullable); `avg_ctr_by_position`(json), `sample_site_count`. | both **→cascade**. |
| `niche_keyword_map` | niche↔keyword pivot + relevance. | **composite PK** `(niche_id, keyword_id)`. | both **→cascade**. |
| `niche_topic_clusters` | Pre-computed niche × keyword-cluster for the Topic Explorer (has surrogate `id`). | unique `(niche_id, cluster_id)`; FK to **`keyword_clusters`**. | both **→cascade**. |
| `website_niche_map` | Multi-label weighted niche assignment per site + primary flag/provenance. | **composite PK** `(website_id, niche_id)`; `is_primary`, `source`. | both **→cascade**. |
| `website_page_keyword_map` | Page↔keyword association + denormalised 30-day GSC metrics (avoids joins). | unique `(page_id, keyword_id, source)` — once **per source**; FK to **`website_pages`**. | both **→cascade**. |

## 11. AI / writer

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `ai_insights` | Cached per-page AI insight payloads by date. `AiInsight.php`, `2026_04_14_200815`. | `payload`(json). | `website_id`**→cascade**. |
| `ai_writer_prompts` | User-saved reusable writer prompts; **softDeletes**. `AiWriterPrompt.php`, `2026_05_20_120000`. | `external_id`(uuid) **unique** (route key); `(user_id, updated_at)`. | `user_id`**→cascade**. |
| `brand_voice_profiles` | One brand-voice fingerprint per website, injected into AI prompts. `BrandVoiceProfile.php`. | `website_id` **unique** (overwrites in place); `fingerprint`(json). | `website_id`**→cascade**. |
| `writer_projects` | Persisted AI-Writer wizard state (topic→…→completed), credit-tracked, **softDeletes**, many ALTERs. `WriterProject.php`, `2026_05_03_120000`. | `external_id`(uuid) **unique** (route key); `(website_id, step)`; ~12 json cols + `generated_html`(longText), `wp_post_id`, `credits_used`. | `website_id`**→cascade**, `user_id`**→null**. |

## 12. Reports

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `report_brandings` | White-label branding — per-user default **OR** per-website override. `ReportBranding.php`, `2026_05_20_000001`. | **two single-col uniques** `user_id` / `website_id` (exactly one non-null/row, by convention); `logo_path` is a **public-disk path, not a URL**. | both **→cascade** (nullable). |
| `crawl_report_sends` | Audit log of crawl-issue summary emails (snapshot of numbers at send time). `CrawlReportSend.php`, `2026_06_16_120000`. | `(website_id, created_at)`, `to_email`; `summary`(json), `status`. | `website_id`/`recipient_user_id`/`sent_by_user_id` **→null** (preserve log). |

## 13. Audits

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `page_audit_reports` | **Cached** audit result per (website, page) — reused across requests. `PageAuditReport.php`, `2026_04_17_120000` (+ALTERs). | unique migrated `(website_id, page)` → **`(website_id, page_hash)`** (page 700 chars too long); `result`(json). | `website_id`**→cascade**. |
| `custom_page_audits` | A queued per-page on-demand audit request, links to a `PageAuditReport`. `CustomPageAudit.php`, `2026_04_17_160000` (+ALTERs). | `(website_id, page_url_hash)`, `cpa_website_status_hash_idx`; `source` enum-by-const, queue cols. | `website_id`/`user_id`**→cascade**, `page_audit_report_id`**→null**. |

(`page_indexing_statuses` is audit/Google-related → see §4 / data-sources.)

## 14. Guest tools  *(anonymous lead-gen — no FKs, route key = `token`)*

| Table | Purpose | Key cols | Model / migration |
|---|---|---|---|
| `guest_page_audits` | Anonymous public-site page SEO audit. | `token`(36) **unique**, `result`(json), `ip`/`email`/`name`, `serp_gl`. | `GuestPageAudit.php`, `2026_06_06_120000`. |
| `guest_page_speeds` | Anonymous PageSpeed test. | same shape. | `GuestPageSpeed.php`, `2026_06_08_110000`. |
| `guest_rank_checks` | Anonymous keyword rank check. | + `keyword`, `domain`, `country`. | `GuestRankCheck.php`, `2026_06_09_120000`. |
| `guest_keyword_volumes` | Anonymous keyword-volume check (data from shared `keyword_metrics`). | + `keyword`, `country`. | `GuestKeywordVolume.php`, `2026_06_09_130000`. |

These feed marketing **`leads`** (§15) — `ip`/`email`/`name` stored for abuse-tracking + capture.

## 15. Admin / activities / leads / settings / plugin / proxies

| Table | Purpose | Key cols / uniques | FK |
|---|---|---|---|
| `client_activities` | **Usage/activity ledger** (billed `user_id`, `actor_user_id`, `provider`, `units_consumed`). *Deep doc: [billing/usage.md](../billing/usage.md).* `ClientActivity.php`, `2026_04_25_091000`. | `(provider, created_at, user_id)`, `(…, website_id)`; `meta`(json). | all three **→null**. |
| `leads` | Marketing lead from guest tools. `Lead.php`, `2026_06_06_150000`. | `email` **unique**; `source`, `converted_at`. `markConvertedFor()` on `User::created`. | `guest_page_audit_id`/`user_id` **→null**. |
| `settings` | System-wide key/value (e.g. global feature kill-switch); cache-through `get/set`. `Setting.php`, `2026_04_30_150000`. | `key`(191) **PK** (non-incrementing); `value`(json). **Gotcha**: direct Eloquent CRUD bypasses the `rememberForever` cache. | — |
| `mail_transports` | Per-tenant outbound mail (branded reports). `MailTransport.php`, `2026_05_20_000003`. | unique `(user_id, website_id)`; `smtp_password` **encrypted**. **`oauth_account_id` is NOT a real FK** — `provider` selects google/microsoft table. | `user_id`/`website_id`**→cascade**. |
| `proxies` | Admin-managed crawler proxy pool. `Proxy.php`, `2026_06_15_180000`. | `url_hash`(64) **unique**; `fail_count`/`success_count`. | — |
| `plugin_releases` | Versioned WP-plugin release/rollback records. `PluginRelease.php`, `2026_04_25_091500`. | unique `(slug, version, channel)`; `rollback_of_id` self **→null**. | `created_by`**→null**. |

(`website_plugin_installs` is in §2; `microsoft_accounts` in §4.)

## 16. Billing / plans  *(deep doc: [billing/plans-and-gating.md](../billing/plans-and-gating.md))*

| Table | One-liner |
|---|---|
| `plans` | The four tiers — caps, the 9-key `plan_features` entitlement matrix, `api_limits`. Operator-tuned live. `Plan.php`. |
| `subscriptions` | Cashier subscription, **re-keyed website→user** (`2026_05_02_100200`); `stripe_id` unique. No model (Cashier). |
| `subscription_items` | Cashier line items; unique `(subscription_id, stripe_price)`. No model. |

## 17. Framework (no models)

`migrations`, `cache` + `cache_locks`, `jobs`, `job_batches`, `failed_jobs` — standard Laravel
queue/cache/migrator tables (`0001_01_01_000001/000002`). Queues run on **Redis** in production
(these DB tables back the migrator + failed-job log); see
[deployment-and-queues.md](../deployment-and-queues.md).

---

# Models → tables index (49 models)

Tables without a model are accessed via the query builder (no auto-casts). 34 of the 83 tables
have no Eloquent model (most `competitor_*`/`niche*`/`serp_*` and all framework tables).

| Model | Table | Notable casts / scopes / traits |
|---|---|---|
| `User` | users | **`Billable`**, `Notifiable`, `MustVerifyEmail`; `password` hashed, `is_admin`/`is_disabled` bool; tier ladder. |
| `Website` | websites | **`HasApiTokens`**; `report_recipients`/`feature_flags` array; 5 lifecycle hooks; tier/freeze derived. |
| `GoogleAccount` | google_accounts | tokens **encrypted**. |
| `MicrosoftAccount` | microsoft_accounts | tokens **encrypted**. |
| `SearchConsoleData` | search_console_data | `scopeForDateRange`. |
| `AnalyticsData` | analytics_data | `scopeForDateRange`. |
| `PageIndexingStatus` | page_indexing_statuses | `google_status_payload` array. |
| `CrawlSite` | crawl_sites | `normalizeDomain()`, cap/health helpers. |
| `CrawlRun` | crawl_runs | status helpers, `isBlocked()`. |
| `WebsitePage` | website_pages | scopes `indexable/orphans/due`; `hashUrl()`. |
| `WebsiteInternalLink` | website_internal_links | `fromPage`/`toPage`. |
| `CrawlFinding` | crawl_findings | `impact` stored 0; `hashUrl()`. |
| `WebsiteFindingState` | website_finding_states | per-user overlay status. |
| `KeywordMetric` | keyword_metrics | `trend_12m` array; `scopeFresh`/`isFresh`; `hashKeyword()` (keyword-only). |
| `KeywordApiServer` | keyword_api_servers | `api_key`/`webhook_secret` **encrypted**; `scopeRoutable`. |
| `KeywordApiRequest` | keyword_api_requests | `payload`/`result` array; route key `request_id`. |
| `KeywordGapAnalysis` | keyword_gap_analyses | json cols; 30-day `isFresh`. |
| `KeywordGapRow` | keyword_gap_rows | bucketed; json cols. |
| `GuestKeywordVolume` | guest_keyword_volumes | `result` array; route key `token`. |
| `RankTrackingKeyword` | rank_tracking_keywords | `competitors`/`tags` array; `hashKeyword()`. |
| `RankTrackingSnapshot` | rank_tracking_snapshots | 5 json cols, `search_time` float. |
| `SerpCacheEntry` | **serp_cache** | `$table` override; `payload` array; hash = keyword\|gl. |
| `Backlink` | backlinks | `type`→`BacklinkType` enum; `audit_result` array. |
| `CompetitorBacklink` | competitor_backlinks | `scopeFresh`; no FK. |
| `CompetitorDiscoveryRun` | competitor_discovery_runs | route key `run_id`. |
| `DiscoveredCompetitor` | discovered_competitors | `sample_keywords` array. |
| `OutreachProspect` | outreach_prospects | `latest_draft`/json; dedup per domain. |
| `RedirectSuggestion` | redirect_suggestions | hashed source path. |
| `AiInsight` | ai_insights | `payload`/`date` cast. |
| `AiWriterPrompt` | ai_writer_prompts | **softDeletes**; uuid route key. |
| `BrandVoiceProfile` | brand_voice_profiles | `fingerprint` array; one/website. |
| `WriterProject` | writer_projects | **softDeletes**; ~12 json casts; uuid route key. |
| `ReportBranding` | report_brandings | two single-col uniques; logo = disk path. |
| `CrawlReportSend` | crawl_report_sends | `summary` array; `recipient`/`sentBy`. |
| `PageAuditReport` | page_audit_reports | `result` array; `hasMany CustomPageAudit`. |
| `CustomPageAudit` | custom_page_audits | `source` enum-by-const; queue casts. |
| `GuestPageAudit` | guest_page_audits | `result` array; route key `token`. |
| `GuestPageSpeed` | guest_page_speeds | `result` array; route key `token`. |
| `GuestRankCheck` | guest_rank_checks | `result` array; route key `token`. |
| `WebsiteSitemap` | website_sitemaps | bool/int/datetime casts; `isFromGsc()`. |
| `WebsiteInvitation` | website_invitations | `permissions` array; `isValid()`. |
| `WebsitePluginInstall` | website_plugin_installs | one/website. |
| `Lead` | leads | `converted()`/`pending()` scopes; `capture()`. |
| `Setting` | settings | string PK; cache-through. |
| `MailTransport` | mail_transports | `smtp_password` encrypted; `oauthAccount()` resolves provider. |
| `Proxy` | proxies | `hashUrl()`. |
| `PluginRelease` | plugin_releases | status consts; `creator`/`rollbackOf`. |
| `Plan` | plans | `plan_features`/`api_limits` array; `featureMap()`/`apiLimit()`. |

---

# Cross-cutting gotchas

1. **Three keyword-hash schemes** — `KeywordMetric`/`RankTrackingKeyword::hashKeyword()` =
   `sha256(keyword)`; `SerpCacheEntry` = `sha256(keyword|gl)`; `keywords.query_hash` is keyed
   with country+language in its unique. Don't assume they're interchangeable.
2. **No-FK universal caches** (intentionally global, no website scoping): `keyword_metrics`,
   `serp_cache`, `competitor_backlinks`, plus the `keywords`/`niche_aggregates` catalogs.
3. **Loose UUID references with no FK**: `discovered_competitors.run_id`,
   `keyword_gap_analyses.request_ids` (json array of `keyword_api_requests.request_id`).
4. **Composite-PK pivots (no `id`)**: `keyword_cluster_map`, `competitor_topic_pages`,
   `niche_keyword_map`, `website_niche_map`. Pivots that **keep** an `id`: `website_user`,
   `niche_topic_clusters`, `website_page_keyword_map`.
5. **Re-keyings / unique churn**: crawl tables (website→`crawl_site_id`),
   `subscriptions`/`subscription_items` (website→user), `websites` unique
   (`ga/gsc`→`domain`), `page_audit_reports` unique (`page`→`page_hash`). The dropped/leftover
   `website_id` on crawl tables is unused.
6. **`content_briefs` is orphaned** — table exists, no model, zero `app/` references.
7. **`mail_transports.oauth_account_id` is not a real FK** — `provider` chooses the target
   table (google vs microsoft).
8. JSON columns on **no-model tables are not auto-cast** — decode manually.
9. **Encrypted columns return ciphertext in raw SQL** — always go through the model; never log.

## Key files

- Migrations — `database/migrations/*.php` (133 files; framework `0001_01_01_*`, app
  `2026_04_14`→`2026_06_17`).
- Models — `app/Models/*.php` (49).
- Team-permission logic (the `website_user`/invitation `permissions` JSON) —
  `app/Support/TeamPermissions.php`.
- Cross-links: [crawler/data-model.md](../crawler/data-model.md),
  [data-sources/data-model.md](../data-sources/data-model.md),
  [billing/plans-and-gating.md](../billing/plans-and-gating.md), [billing/usage.md](../billing/usage.md).
