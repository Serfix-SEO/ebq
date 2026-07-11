# WordPress plugin — feature surface

> The on-page / schema / sitemap / redirects / migration / AI feature classes of
> the **client-side** `ebq-seo` plugin. Architecture, connection, build, and the
> flag/cache/transport layer are in [`plugin-source.md`](plugin-source.md). The
> **server** API these features call is in [`hq-api.md`](hq-api.md). Paths are
> relative to `ebq-wordpress-plugin/`.
>
> **Separate repo — read only, never commit into the main app** (see
> [`plugin-source.md`](plugin-source.md)).

## Core principle: offline-first, value-add gated

On-page output (meta, social cards, JSON-LD, sitemap, breadcrumbs, redirects,
offline scoring) works **without an EBQ connection and even when frozen** — these
classes are never in `EBQ_Feature_Flags::KNOWN_FEATURES`, so a workspace admin
can't switch them off (silently de-indexing a user's site). Live data + AI are
gated by per-website feature flags and tier.

## Canonical post-meta — `EBQ_Meta_Fields`

`class-ebq-meta-fields.php` is the single registry of `_ebq_*` post-meta keys,
all `register_post_meta`'d on `init` with `show_in_rest` + sanitize callbacks so
both Gutenberg and the classic form save through the same contract.

| Group | Keys |
|---|---|
| Core SEO | `_ebq_title`, `_ebq_description`, `_ebq_canonical`, `_ebq_robots_noindex`, `_ebq_robots_nofollow`, `_ebq_robots_advanced`, `_ebq_focus_keyword`, `_ebq_additional_keywords` (JSON) |
| Social | `_ebq_og_{title,description,image}`, `_ebq_twitter_{title,description,image,card}` |
| Schema | `_ebq_schema_type`, `_ebq_schema_disabled`, `_ebq_schemas` (JSON) |
| Breadcrumbs | `_ebq_breadcrumbs` (JSON `{mode, items}`) |
| Cached scores | `_ebq_seo_score`, `_ebq_readability_score` (default **-1** = "never analyzed") |

**Gotchas:** scores default to `-1` (not 0) so the list column distinguishes
"unanalyzed" from "bad"; `_ebq_additional_keywords` is a JSON **string** (rides
the `register_post_meta` string contract); schema/breadcrumb sanitizers cap size
(20 schemas, 2 KB/string, 2 levels deep) to stop pathological pastes bloating the
DB.

## On-page output (`wp_head`)

| Class | Emits | Hook(s) |
|---|---|---|
| `EBQ_Meta_Output` | `<title>`, meta description, canonical, robots | `pre_get_document_title`/`document_title_parts`/`wp_head@1`; removes WP's `rel_canonical` |
| `EBQ_Social_Output` | Open Graph + Twitter card tags | `wp_head@3` |
| `EBQ_Schema_Output` | the JSON-LD `@graph` `<script>` | `wp_head@5` |
| `EBQ_Title_Template` | resolves `%%title%%` / `%%sep%%` / `%%sitename%%` / `%%page%%` | (utility) |

**Coexistence guard — the key gotcha:** all three output classes early-return in
`register()` when another SEO plugin is active. `EBQ_Meta_Output::another_seo_plugin_is_active()`
(`class-ebq-meta-output.php:38`) checks `WPSEO_VERSION` (Yoast),
`RANK_MATH_VERSION`, `AIOSEO_VERSION`, and `The_SEO_Framework\Load` — if any is
present, EBQ stands down entirely to avoid duplicate tags. The setup wizard's
migration path is the way to fully switch over.

**Fallback chains:** description → `_ebq_description` → excerpt → first 160 chars
→ tagline. OG title → `_ebq_og_title` → resolved `_ebq_title` → post title →
site name. Twitter fields default to their OG equivalents; card auto-selects
`summary_large_image` when an image is present.

## Schema (JSON-LD)

`EBQ_Schema_Output::build_document()` (`class-ebq-schema-output.php:76`) assembles
**one `@graph` per page**: auto nodes (WebSite, Organization, WebPage,
ImageObject, BreadcrumbList, Person, primary Article/BlogPosting/NewsArticle/
Product/…) plus user-configured `_ebq_schemas`. **User @types suppress the
matching auto node** (checked first). FAQ/HowTo are parsed from block content with
an exact-name allowlist (`yoast/`, `rank-math/`, `ugb/`, `ebq/` blocks — not
substring, to avoid `faq-button` false positives). `_ebq_schema_disabled` ⇒ no
schema for that post.

- `EBQ_Schema_Templates` — 19 builders (article, product, event, faq, recipe,
  local_business, book, course, job_posting, video, software, service, person,
  music_album, movie, review, website, organization, webpage, custom). Repeater
  fields arrive as `{value:…}` rows from the JS UI.
- `EBQ_Schema_Variables` — resolves `%title%`, `%excerpt%`, `%url%`,
  `%featured_image%`, `%author%`, `%date%`, `%sitename%`, `%post_meta(key)%`
  recursively through arrays at emit time.
- `EBQ_Schema_Shortcode` — `[ebq_schema]` renders a **human-visible** card
  (recipe summary / review stars / `<dl>`) from the same stored data; CSS emitted
  once per page.

## Sitemap & breadcrumbs

`EBQ_Sitemap` (`class-ebq-sitemap.php`) serves `/ebq-sitemap.xml` (index) +
`/ebq-sitemap-{type}-{page}.xml` + `/ebq-sitemap-tax-{tax}-{page}.xml`, 200 URLs/
page, `lastmod` from `post_modified_gmt`, featured-image `<image:image>` entries.
Disables WP's core `/wp-sitemap.xml` and appends a `Sitemap:` line to robots.txt.

**Gotchas:** it excludes `_ebq_robots_noindex` posts, builder/system CPTs, query-
string-bloated URLs, and posts whose custom canonical ≠ permalink. **Defense in
depth** for serving: a `parse_request@1` direct-URI interceptor runs *before*
rewrite rules, so the sitemap works even with Plain permalinks, stale rules, or a
read-only `.htaccess`; plus a throttled (1 h) `init` self-heal that re-flushes if
its rewrite rules go missing. `EBQ_Breadcrumbs` is front-end-visible output only
(the JSON-LD BreadcrumbList comes from `EBQ_Schema_Output`); `[ebq_breadcrumbs]`
shortcode + `ebq_get_breadcrumbs_html()` template helper.

## Redirects & 404 tracking

| Class | Role | Key hooks |
|---|---|---|
| `EBQ_Redirects` | store (`ebq_redirect` CPT + `_ebq_r_*` meta) + serve 301/302/307/410 | `init` (CPT), `template_redirect@9` |
| `EBQ_Redirects_Auto` | auto-301 on slug change; stash last URL on trash/delete | `post_updated`, `transition_post_status`, `before_delete_post` |
| `EBQ_Redirects_Admin` | list / add / bulk / CSV import+export UI | `admin_post_ebq_redirect_*` |
| `EBQ_Redirects_Importer` | one-click import from Yoast Premium / Rank Math redirect stores | `admin_post_ebq_redirect_import_{yoast,rankmath}` |
| `EBQ_404_Tracker` | dedup 404s → hourly cron ships batch to EBQ for AI redirect matching | `template_redirect@99`, cron `ebq_send_404_batch` |

**Gotchas:** redirect serving deliberately bypasses `wp_safe_redirect` (users
redirect to any external host) and uses `suppress_filters` for predictable rule
resolution; invalid regex is silently skipped. `EBQ_404_Tracker` buffers in a
**non-autoloaded** option `ebq_404_buffer` (≤200 paths), filters bots/admin/REST/
query-strings, only tracks when connected, and only clears the buffer on a
successful `report_404s` API response (`POST /api/v1/posts/report-404s`) — so 404s
survive an EBQ outage. The `redirects` flag gates the admin UI + 404 cron, not the
core serving. Auto-redirect recomputes the pre-trash permalink because
`get_permalink()` already carries the `__trashed` suffix when the transition fires.

## Migration from Yoast / RankMath

`EBQ_Migration` (`class-ebq-migration.php`) is a batched orchestrator: an abstract
`EBQ_Migration_Source` with `EBQ_Migration_Yoast` / `EBQ_Migration_RankMath`
subclasses, driven by a self-rescheduling cron (`ebq_migration_run_batch`, **25
posts/batch**) with progress in a 12 h transient — so a 50k-post site finishes
over time. Started from Settings → EBQ SEO; `EBQ_Migration_Banner` nudges via
`admin_notices` when source data is detected, hiding once every available source
completes.

- **Conflict policy = skip:** every per-meta write is gated `write_if_empty()` —
  posts the user already edited in EBQ are untouched.
- **Maps:** titles/descriptions/canonical/OG/Twitter map 1:1; focus keywords are
  split (first → `_ebq_focus_keyword`, rest → `_ebq_additional_keywords`);
  robots/schema formats are normalized (Yoast noindex uses value `'2'`; Rank Math
  robots is a serialized directive array; both Pro/Free/block schema formats fold
  into `_ebq_schemas`). Redirects are reported but imported separately via
  `EBQ_Redirects_Importer`. Both importers are idempotent (`find_by_source`).

## Editor & admin surfaces (React enqueuers)

These PHP classes mostly enqueue a `build/*` bundle and `wp_localize_script` a
config blob; all data flows through `/wp-json/ebq/v1/*` (see
[`plugin-source.md`](plugin-source.md) → REST proxy).

| Class | Bundle | Where | Flag | Notes |
|---|---|---|---|---|
| `EBQ_Gutenberg_Sidebar` + `EBQ_Block_Editor_Metabox` | `sidebar` | block editor metabox | — (core) | main SEO editor UI; localizes tier/frozen/promo/features |
| `EBQ_Seo_Fields_Meta_Box` | `classic-editor` | classic editor | — | sets `__EBQ_CLASSIC__` before eval; hidden-input no-JS save fallback |
| `EBQ_Meta_Box` | `meta-box-hydrate` | post edit | — | lazy insights metabox (GSC perf, rank, cannibalization) via REST |
| `EBQ_Chatbot` | `chatbot` | post.php/post-new.php | `chatbot` | Rank Assist FAB; localizes `savedMeta` context for the LLM |
| `EBQ_Block_Editor_Ai` | `block-editor-ai` | block editor | `ai_inline` | inline toolbar/slash AI on an allowlist of text/media/button blocks |
| `EBQ_Post_Bulk_Actions` | `post-bulk-ai` | `edit.php` | — | 7 sync robots/schema actions + 4 async AI/index actions via a JS modal |
| `EBQ_Post_Column` | `post-column-hydrate` | `edit.php` | `post_column` | one bulk REST call hydrates the EBQ column; "+ Track keyphrase" row action |
| `EBQ_Dashboard_Widget` | `dashboard-hydrate` | `index.php` | `dashboard_widget` | skeleton → `/ebq/v1/dashboard-html`; card clicks fetch a signed embed URL via `/ebq/v1/hq/iframe-url` (pins the website id — a plain `/reports` link opened the session's last-selected website; fixed 2.0.8), plain href kept as 403/network fallback |
| `EBQ_Hq_Page` | `hq` | top-level "EBQ HQ" menu | `hq` | React dashboard; renders friendly locked panels (not-connected/frozen/disabled) instead of WP's permission error |
| `EBQ_AiWriter_Page` | `hq` (reused) | "AI Studio" submenu | `ai_writer` | same bundle, `#ebq-aiwriter-root`; also `wp_enqueue_editor`+media |
| `EBQ_Setup_Wizard` | `setup` | hidden page, activation redirect | — | Welcome → Connect (skippable) → Pricing (`/api/v1/plans`) → Done |

**HQ structure (v2.0.0):** `EBQ_Hq_Page` registers a position-3 admin menu with
flat sub-sections (`SECTIONS`, `class-ebq-hq-page.php`) — SEO Performance, **Site
Audit**, SEO Analysis, Content opportunities, Keywords, **Keyword Finder**, Rank
Tracker, Pages, Index Status, Redirects (AI), SERP Features. The dead Prospects
tab (hard-disabled pre-release) was deleted in 2.0.0 (tab JSX, proxy routes,
client methods, `HQ_PROSPECTS_ENABLED` plumbing). Legacy slugs redirect to
current sections. The menu interleaves "AI Studio (Beta)", Redirects admin,
General settings, and Plugin update as siblings. Every locked panel reassures
the user that offline features keep working.

**Site Audit tab** (`src/hq/tabs/SiteAuditTab.jsx`, v2.0.0): native crawler
report over `/hq/site-audit/*` (see [`hq-api.md`](hq-api.md)). Sub-views:
Overview (health ring + severity KPIs + crawl status/blocked/never-crawled
states), Issues (category group cards → drill-down with type chips, severity
filter, URL search, load-more, per-type fix guidance; since 2.0.10 also a
"Search-data issues" block — GSC-derived counts via `/hq/insight-counts`,
cards deep-link to the SEO Performance insights panel / Index Status so the
screen's totals match the portal action queue), Pages (inventory with
all/orphans/broken/noindex/deep filters, Inspect hand-off), Link Explorer
(inbound/outbound/suggested links + path-from-home for any crawled URL).

**Keyword Finder tab** (`src/hq/tabs/KeywordFinderTab.jsx`, v2.0.0): async
discovery (seeds ≤20 or URL mode) + volume check (≤100) over
`/hq/keyword-finder/*`; dispatch → 5s polling (60 tries then a soft timeout
message), instant path when the server answers from cache. Raw Google Ads bid
range shown — never $ projections. "Track" hand-off deep-links into the Rank
Tracker's AddKeywordModal via `?ebq_track=`.

**⚠ Admin-hook gotcha (fixed in 2.0.0, was broken in shipped 1.0.5):** WP
submenu hooks are `{sanitize_title(parent menu TITLE)}_page_{slug}` — the EBQ
HQ → Serfix HQ menu-title rebrand changed the prefix from `ebq-hq_page_…` to
`serfix-hq_page_…`, so every exact-match `$hook` comparison silently failed and
HQ *section* pages / AI Studio / Settings never enqueued their bundles (stuck
on the boot skeleton). All checks now match on the `_page_{slug}` suffix
(`EBQ_Hq_Page::is_hq_screen_hook`, `EBQ_AiWriter_Page::enqueue`,
`EBQ_Settings::enqueue_assets`, `EBQ_Migration_Banner`). Never compare a
submenu `$hook` against a hardcoded parent prefix.

## Tier & freeze gating

Tier helpers live in `EBQ_Plugin`: `tier_at_least('startup')`
(`class-ebq-plugin.php:180`) over the ordering `free < pro < startup < agency`,
with `is_free_promo()` opening every gate during a FREE=true promo, and
`is_frozen()` driving the read-only banner + AI lockout. These mirror the server's
`InjectFeatureFlags` freeze/tier signals ([`releases.md`](releases.md)); the React
side reads the same `tier`/`isFrozen`/`isFreePromo`/`features` localized blob.
