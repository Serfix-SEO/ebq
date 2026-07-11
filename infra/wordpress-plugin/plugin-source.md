# WordPress plugin source — architecture, connection & build

> The **client-side** plugin (`ebq-seo`) that customers install on their own
> WordPress sites. This doc covers its architecture, the PHP class map, how it
> connects + talks to the EBQ Laravel app, and the JS build. The on-page/schema/
> sitemap/redirect/AI/migration feature surface is split into
> [`plugin-features.md`](plugin-features.md). The **server** side of the API it
> calls is in [`hq-api.md`](hq-api.md); how a build is packaged/published is in
> [`releases.md`](releases.md).

## ⚠️ Separate repo — do not commit into the main app

The plugin lives in its **own git repo** —
`github.com/malihaider19967-web/ebq-wordpress-plugin` (currently **v2.0.0**),
checked out at `/var/www/ebq/ebq-wordpress-plugin/`. It has its **own release
cycle** and is packaged/distributed through the server's `PluginRelease` flow
(see [`releases.md`](releases.md): `WordPressPluginSourceService` rewrites the
`Version:` header + `EBQ_SEO_VERSION` define, then `ebq:package-plugin` zips it
to `public/downloads/ebq-seo.zip`). **Never commit the plugin tree into the main
`ebq` repo, and never run git in the main repo on its behalf.** All paths below
are relative to `ebq-wordpress-plugin/`.

## What it is

A "connected" SEO suite: core on-page output (meta, schema, sitemap,
breadcrumbs, redirects, offline SEO/Readability scoring) works **standalone**;
live data + AI features unlock once the site is linked to an EBQ workspace via a
one-click OAuth-style connect. Requires WP ≥ 6.0, PHP ≥ 8.1. Self-hosted EBQ:
`define('EBQ_API_BASE', 'https://your-host')` in `wp-config.php`.

## Bootstrap & lifecycle — `ebq-seo.php`

The main file (`ebq-seo.php`) defines `EBQ_SEO_VERSION` / `EBQ_SEO_PATH` /
`EBQ_SEO_URL`, `require_once`s all 42 `includes/class-ebq-*.php` files, then:

| Hook | What | Why |
|---|---|---|
| `register_activation_hook` (`:75`) | seeds `ebq_site_token`/`ebq_website_id`/`ebq_connect_state` options, schedules a rewrite flush, sets the wizard `PENDING_OPTION` | first-run setup runs once; sitemap rewrites need a flush |
| `register_deactivation_hook` (`:96`) | clears connect-state + flushes rewrites | leave no in-flight OAuth state |
| `init` (`:109`) | bumps `ebq_flush_rewrites_pending` if the stored plugin version changed | sitemap rules re-register after an upgrade |
| `plugins_loaded` (`:117`) | `EBQ_Plugin::instance()->boot()` — wires every subsystem | single entry point |
| `admin_notices` + `wp_ajax_ebq_dismiss_quota_notice` (`:127`,`:155`) | renders/dismisses the per-plan **quota-exceeded** banner stashed by the API client on a 402 | shows even on screens that don't render the failing call inline; auto-expires after 24 h |

`EBQ_Plugin::boot()` (`includes/class-ebq-plugin.php:19`) news up + `register()`s
~35 subsystem classes. It also hooks `admin_init → maybe_refresh_subscription_status`
(`:83`): a throttled (1×/5 min) `/api/v1/website-features` ping so a plan
upgrade/downgrade on ebq.io reaches the plugin within 5 min of opening any
wp-admin page — without it a *frozen* site never makes the call that would
un-freeze it.

## PHP class map (42 classes)

| Group | Classes | Role |
|---|---|---|
| **Core / bootstrap** | `EBQ_Plugin`, `EBQ_Settings`, `EBQ_Feature_Flags`, `EBQ_Banner`, `EBQ_Serp_Countries` | singleton wiring, settings page, flag gating, promo banner |
| **Connect / auth / transport** | `EBQ_Connect`, `EBQ_Api_Client`, `EBQ_Rest_Proxy` | OAuth handshake, bearer HTTP client, browser→server REST bridge |
| **Updater** | `EBQ_Updater`, `EBQ_Plugin_Update_Page` | version poll + in-place upgrade from EBQ HQ |
| **HQ dashboard** | `EBQ_Hq_Page`, `EBQ_AiWriter_Page`, `EBQ_Dashboard_Widget` | top-level admin menu + React mounts |
| **Editor surfaces** | `EBQ_Gutenberg_Sidebar`, `EBQ_Block_Editor_Metabox`, `EBQ_Block_Editor_Ai`, `EBQ_Meta_Box`, `EBQ_Seo_Fields_Meta_Box`, `EBQ_Chatbot`, `EBQ_Post_Column`, `EBQ_Post_Bulk_Actions` | sidebar, inline AI, chatbot, classic metabox, list column/bulk |
| **On-page SEO** | `EBQ_Meta_Fields`, `EBQ_Meta_Output`, `EBQ_Social_Output`, `EBQ_Title_Template`, `EBQ_Analysis_Cache` | meta-key registry, `wp_head` emission, title vars, on-save cache |
| **Schema** | `EBQ_Schema_Output`, `EBQ_Schema_Templates`, `EBQ_Schema_Variables`, `EBQ_Schema_Shortcode` | JSON-LD `@graph` builder + 19 templates |
| **Sitemap / breadcrumbs** | `EBQ_Sitemap`, `EBQ_Breadcrumbs` | `/ebq-sitemap.xml`, breadcrumb trail |
| **Redirects / 404** | `EBQ_Redirects`, `EBQ_Redirects_Auto`, `EBQ_Redirects_Admin`, `EBQ_Redirects_Importer`, `EBQ_404_Tracker` | 301/302 store + serve, slug-change auto, 404 → AI suggest |
| **Migration** | `EBQ_Migration`, `EBQ_Migration_Yoast`, `EBQ_Migration_RankMath`, `EBQ_Migration_Banner` | import from Yoast/RankMath, batched cron |
| **Onboarding** | `EBQ_Setup_Wizard` | first-run React wizard |

See [`plugin-features.md`](plugin-features.md) for the per-feature detail.

## Connection — the connect/token handshake

`EBQ_Connect` (`includes/class-ebq-connect.php`) implements a one-click,
OAuth-*style* link (no codes, no token pasting):

1. Settings "Connect to EBQ" → `admin-post.php?action=ebq_start_connect` (WP-nonce
   protected).
2. `start_connect()` (`:137`) mints a random 32-char `state`, stores it in option
   `ebq_connect_state`, and **`wp_redirect`** (not `wp_safe_redirect` — external by
   design, `:159`) to `{base}/wordpress/connect?site_url=…&redirect=…&state=…`.
3. The user authenticates on ebq.io and picks a website.
4. EBQ bounces back to `admin.php?page=ebq-seo` with
   `ebq_token`, `website_id`, `state`, `ebq_domain`, `ebq_tier`.
5. `maybe_catch_callback()` (`admin_init`, `:165`) validates `state` with
   `hash_equals` against the stored value, then persists `ebq_site_token`,
   `ebq_website_id`, `ebq_website_domain`, `ebq_site_tier` and primes the
   feature-flag cache.

**Gotchas / WHY:**
- **State *is* the CSRF defence** for the callback — a WP nonce can't apply
  because the entry point is a redirect from a third-party host (`:50`).
- **Callback is detected by `ebq_token` presence**, not a bare flag like
  `ebq_cb=1` — WAF/CDN rules commonly strip unknown bare flags (`:20`,`:180`).
- State is minted **at click time**, not on every settings render, so reloading
  the page mid-flow can't invalidate an in-flight connect (`:16`).
- `process_callback_inline()` (`:45`) is a redirect-less fallback the settings
  view calls when a caching plugin/security filter ate the `admin_init` pass.
- **Disconnect** (`ebq_disconnect=1`) clears local credentials only — the token
  isn't revoked server-side.

**Stored options** (the plugin's entire connection state):
`ebq_site_token` (bearer), `ebq_website_id`, `ebq_website_domain`,
`ebq_site_tier`, `ebq_site_frozen` (+`_reason`), `ebq_app_free_promo`,
`ebq_api_base_override`, `ebq_connect_state`, `ebq_last_connect_error`.

## HTTP client — `EBQ_Api_Client`

`includes/class-ebq-api-client.php` — a thin wrapper over `wp_remote_request`
(`EBQ_Plugin::api_client()` constructs one per call from the stored token).

- **Base URL** (`base_url()`): `EBQ_API_BASE` constant wins → per-site
  `ebq_api_base_override` option → default `https://serfix.io` (since 2.0.0;
  `EBQ_Plugin::maybe_upgrade()` deletes a stale ebq.io override on upgrade and
  an admin notice flags a stale `EBQ_API_BASE` constant; ebq.io 308-redirects
  `/api/*` + `/wordpress/*` so pre-2.0 installs' POSTs still work).
- **Auth header**: `Authorization: Bearer {token}` on every request, plus
  `User-Agent: EBQ-SEO-WP/{version}; {home_url}` and JSON Accept/Content-Type
  (`:566`,`:602`). This is the **Sanctum personal-access token** whose tokenable
  is a `Website` with ability `read:insights` (server side: [`hq-api.md`](hq-api.md)).
- **One method per server endpoint** — `get_*` for reads, `request()` for
  POST/PATCH/DELETE/PUT. Per-call timeouts (floor 5s; GET cap 120s, write cap
  300s) because AI endpoints chain Serper + LLM calls that run 30s–4min cold.
- **No token ⇒ `{ok:false, error:'not_connected'}`** without hitting the network
  (`:521`,`:588`).

### Response handling & passive state sync — `handle_response()` (`:624`)

This is the channel by which **server state reaches the plugin on every call**:

- **402 `quota_exceeded`** → stashed in `ebq_last_quota_notice` for the global
  banner; returned structured (`:641`).
- **Structured 4xx failures** (`{ok:false, error:'tier_required'|'website_frozen'|…}`)
  are passed through verbatim (not collapsed to `http_4xx`) so the UI renders the
  right upgrade/locked CTA (`:669`). Execution continues so the sync blocks below
  still run on a failure body.
- **Auto-sync** from any response carrying these keys: `tier` →`ebq_site_tier`
  (whitelisted to free/pro/startup/agency), `frozen`/`frozen_reason`,
  `free_promo`, and `features` → `EBQ_Feature_Flags::store()`. This is why a plan
  change or a workspace admin feature-toggle reaches active editor sessions
  **one API round-trip later**, no reconnect (matches the server's
  `InjectFeatureFlags` middleware — see [`releases.md`](releases.md)).

### Client-side caching (WP transients)

- GET responses cache for **5 min** under
  `ebq_api_v{N}_{md5(url)}` where `N = ebq_api_cache_v` (`:519`–`:557`).
- **Every write `bump_cache_version()`s** (`:777`) — increments `N` so all prior
  GET keys orphan at once. Backend-agnostic (db transients / Redis / Memcache) —
  it changes the namespace, never enumerates keys.
- **Skip-cache list** (`:543`): everything under `/api/v1/hq/`, plus `/seo-score`,
  `/topical-gaps`, `/entity-coverage`, `/website-features` — these are live
  dashboards or carry queue-state / freshness-critical flags that caching would
  pin stale (the historical "stuck re-auditing" bug lived in WP transients, not
  LSCache).
- `handle_response()` refuses to cache `ok:false`, `diagnostic`, or empty
  `suggestions` payloads so retries aren't poisoned (`:743`).

> `EBQ_Analysis_Cache` (`class-ebq-analysis-cache.php`) is unrelated to the API
> cache — it persists derived per-post metrics (word count, h2/h3 counts,
> content hash) to post-meta `_ebq_analysis_cache` on `save_post` (`:18`) for
> the offline scoring UI.

## REST proxy — keeping the token out of browser JS

`EBQ_Rest_Proxy` (`includes/class-ebq-rest-proxy.php`, ~2.2k lines) registers
`/wp-json/ebq/v1/*` routes. The React surfaces (sidebar, HQ, chatbot, bulk-AI)
call **these** routes, authenticated by the WP cookie/nonce; each handler then
forwards to EBQ through `EBQ_Plugin::api_client()` using the **site token stored
server-side**. The token is never exposed to the browser.

- **Pattern**: ~80 routes mirror the `EBQ_Api_Client` method surface 1:1.
  Handlers are thin — validate params, call the matching client method, wrap the
  array in `WP_REST_Response` (almost always **status 200** even on
  `{ok:false,…}` bodies, so `@wordpress/api-fetch` doesn't throw and collapse the
  structured `message`; `:2143`).
- **Permission callbacks** (`:656`): `can_edit` = `edit_posts` (editor surfaces);
  `can_view_hq` = `manage_options` (HQ/admin routes). The server enforces the
  same gate, so a lower-priv user can't fish analytics by hitting routes directly.
- **Cache-busting for dynamic routes** is belt-and-braces (`NEVER_CACHE_ROUTE_PREFIXES`,
  `:30`): `rest_pre_dispatch` sets `DONOTCACHEPAGE` + LiteSpeed control **before**
  any cache layer decides (`:1058`); `rest_post_dispatch` attaches
  `Cache-Control: no-store…` + `Pragma` + `Expires:0` + `X-LiteSpeed-Cache-Control`
  (`:1092`). `seo-score` is registered as **POST** (also accepts GET for old
  builds) because switching the method is the only bulletproof way to dodge
  LiteSpeed page-cache (`:130`).
- Route families: post-insights / focus-keyword / serp-preview / related-keywords
  / seo-score / topical-gaps / rewrite-snippet / content-brief / ai-writer(+plan)
  / chat / ai-block / schemas-on-page / redirect-suggestions / research /
  entity-coverage / post-meta(-summary), all the `hq/*` dashboard routes,
  `writer-projects/*`, `ai-writer-prompts/*`, `ai/tools/*`, `ai/brand-voice`,
  `track-keyword`, `migration/status`, plus server-rendered HTML helpers
  (`*-html`, `bulk-post-insights`).

## Feature flags — `EBQ_Feature_Flags`

`includes/class-ebq-feature-flags.php`. 8 toggleable value-add keys
(`KNOWN_FEATURES`, `:55`): `chatbot`, `ai_writer`, `ai_inline`, `live_audit`,
`hq`, `redirects`, `dashboard_widget`, `post_column`. Each gated class calls
`EBQ_Feature_Flags::is_enabled('<key>')` first thing in `register()` and bails
(no hooks/enqueues/routes) when off.

- **Two layers** (`is_enabled()`, `:83`): a **global** kill-switch map reaches
  *every* install (connected or not) via the public version endpoint
  (`store_global()`, primed by `EBQ_Updater`); a **per-website** map syncs from
  any authenticated response. Precedence: global `false` wins → per-site `false`
  → else enabled.
- **Default-ON safety**: empty map / fetch failure / fresh install / unconnected
  ⇒ enabled. EBQ admin can only ever *disable*; absence reads as on. Core SEO
  output is never in this list, so it can't be switched off (de-index risk).
- **Storage**: per-site transient `ebq_feature_flags` (12 h) backed by a
  persisted option so a transient eviction doesn't flip everything on between
  fetches. Server values are sanitised against `KNOWN_FEATURES` before storing.

## Updater — `EBQ_Updater` + `EBQ_Plugin_Update_Page`

Updates are **not** offered through WP's native Updates screen. `EBQ_Updater`
(`class-ebq-updater.php`) polls `GET /wordpress/plugin/version` (cached **6 h**,
`:23`) for `version`/`download_url`/`updates_enabled`/`banner`/`global_features`,
fails closed to "no update" on any error, and **piggybacks** the
`global_features` kill-switch into `EBQ_Feature_Flags::store_global()` (`:133`)
so one fetch serves both update-check and global gating. `EBQ_Plugin_Update_Page`
(EBQ HQ → Plugin update) shows installed vs latest and drives the in-place
upgrade via WP's `Plugin_Upgrader` on an admin-post action. `EBQ_Banner` renders
the platform-driven promo card on HQ screens from the same cached payload (view-
only dismiss). Server side of these endpoints: [`releases.md`](releases.md).

## React frontend & build

Plain `@wordpress/scripts` (wp-scripts) over webpack — **no custom framework**.
`package.json`: `npm run build` (`wp-scripts build --webpack-copy-php`) →
`build/`; `npm run dist` builds + zips via `scripts/make-dist.cjs`;
`scripts/make-pot.cjs` extracts translations.

**Entrypoints** (`webpack.config.cjs`, sources under `src/`):

| Entry | Source | Mounted by |
|---|---|---|
| `sidebar` | `src/sidebar/` | block-editor SEO metabox (the main editor UI) |
| `classic-editor` | `src/classic/` | `EBQ_Seo_Fields_Meta_Box` (classic editor) |
| `hq` | `src/hq/` | `EBQ_Hq_Page` (`#ebq-hq-root`) **and** `EBQ_AiWriter_Page` (`#ebq-aiwriter-root`) — one bundle, two roots |
| `block-editor-ai` | `src/block-editor-ai/` | inline AI block toolbar / slash command |
| `chatbot` | `src/chatbot/` | Rank Assist FAB |
| `setup` | `src/setup/` | first-run wizard |
| `post-bulk-ai` | `src/post-bulk-ai/` | post-list bulk-AI modal |
| `admin`, `post-column-hydrate`, `dashboard-hydrate`, `meta-box-hydrate` | `src/admin/` | settings styles + lazy hydration of column/widget/metabox skeletons |

- Each PHP enqueuer reads `build/<entry>.asset.php` for deps/version (falls back
  to `filemtime`), localizes a `ebq*Config` blob (`restUrl`, `nonce`,
  `isConnected`, `tier`, `isFrozen`, `isFreePromo`, `features`, URLs), and bails
  early if the bundle file is missing — bundles are treated as **optional**, so
  the PHP-only features still work.
- **Classic-editor gotcha**: `window.__EBQ_CLASSIC__ = true` is set **before** the
  bundle evaluates so React picks a DOM data-store instead of `@wordpress/data`
  (`core/editor` isn't registered on classic screens).
- **Lazy hydration**: post column, dashboard widget, and the insights metabox
  render a skeleton server-side, then one bulk REST call replaces it — keeps
  `edit.php` / dashboard snappy at login with zero blocking calls.

## Distribution

`.distignore` strips `src/`, `node_modules/`, `tests/`, `scripts/`, dotfiles,
`.wordpress-org/`, `*.md` from the WordPress.org zip — only `build/`, PHP,
`icons/`, `languages/`, and top-level metadata ship. The packaged zip is what the
server's `PackageWordPressPlugin` command produces and serves
([`releases.md`](releases.md)).

## Gotchas summary

- **Separate repo, separate release cycle** — packaged via the server's
  `PluginRelease` flow; never commit into the main app.
- **The bearer token never reaches the browser** — all JS goes through
  `/wp-json/ebq/v1/*`, which forwards server-side with the stored token.
- **State is the connect CSRF defence**; callback detection keys off `ebq_token`
  to survive WAF flag-stripping.
- **All server state (tier/frozen/promo/features) syncs passively** through
  `handle_response()` on *any* call — no dedicated poll except the 5-min
  `website-features` heartbeat for frozen sites.
- **Cache versioning, not key deletion** — every write bumps `ebq_api_cache_v`;
  `/hq/*` + score/audit routes skip cache entirely.
- **PHP-FPM opcache**: after editing plugin PHP that the web layer serves, a full
  `php8.3-fpm` restart is required (see main-repo `CLAUDE.local.md`); CLI/tinker
  always compiles fresh.
