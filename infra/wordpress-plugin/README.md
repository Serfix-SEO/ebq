# WordPress plugin & HQ API

The EBQ SEO WordPress plugin is a companion that runs **inside a customer's WP-admin**
and talks back to EBQ.io. The **core on-page SEO output** (meta, schema, sitemap,
breadcrumbs, redirects, offline scoring) works standalone; **live insights + AI** unlock
once the install is linked to an EBQ workspace, which is the source of truth for them.
This subsystem has **two halves**:
- **Server side (this Laravel repo):** the connect handshake, the per-website **HQ API**,
  and the **release/adoption** machinery that ships plugin builds.
- **Client side (the plugin codebase):** the WordPress plugin itself — a **separate git
  repo** checked out at `/var/www/ebq/ebq-wordpress-plugin/` (`github.com/.../ebq-wordpress-plugin`,
  v1.0.5). It is **gitignored here and must never be committed into this repo**; it has its
  own release cycle.

## Read in this order

**Server side (this repo):**
| Doc | What it covers |
|---|---|
| [hq-api.md](./hq-api.md) | The `PluginHqController` endpoint catalog — path → reads → auth → cache. The Sanctum auth model. What it does NOT read (no crawl tables). |
| [releases.md](./releases.md) | Versioning, packaging, the publish/schedule/rollback lifecycle, the global update + feature kill-switches, adoption tracking. |

**Client side (the plugin source — separate repo):**
| Doc | What it covers |
|---|---|
| [plugin-source.md](./plugin-source.md) | Plugin architecture: bootstrap, the 42-class map, the connect handshake + Sanctum token, the `EBQ_Rest_Proxy` pattern, caching, feature flags, updater, the React build. |
| [plugin-features.md](./plugin-features.md) | The feature surface: on-page (meta/title/social/canonical/robots), schema (JSON-LD), sitemap, breadcrumbs, redirects + 404 tracking, Yoast/RankMath migration, the AI surfaces. |

Infra-wide topology lives one level up ([../main.md](../main.md)); the crawler — which
the HQ API deliberately does **not** read — is in [../crawler/](../crawler/) (see
[../crawler/adjacent-systems.md](../crawler/adjacent-systems.md) §"Plugin API — safe").

## One paragraph

A WP install one-click-connects via `/wordpress/connect`
(`WordPressConnectController`), which mints a **per-Website Sanctum token** scoped to
`read:insights` and hands it back to the plugin. Every plugin → EBQ call carries that
bearer token; `WebsiteApiAuth` resolves it to a `Website` and stamps `api_website` on
the request. The plugin then drives two API surfaces: per-post insights
(`PluginInsightsController`, not in this doc's scope) and the **HQ admin dashboards**
(`PluginHqController` — overview, performance, keywords, pages, index status, insights,
growth reports, page audits, backlinks/outreach, AI writer). All of it reads the **same
service layer** as the EBQ.io Livewire dashboards (`ReportDataService`,
`SearchConsoleData`, `RankTrackingKeyword`, `PageIndexingStatus`) — never the raw crawl
tables — so the plugin inherits EBQ's per-website GSC scoping for free. The plugin
binary itself is shipped through a DB-backed release table with a public-download
mirror, plus two admin kill-switches (updates, per-feature flags) broadcast to every
install on the public version endpoint.

## Key components

| Concern | File | Role |
|---|---|---|
| Connect (mint token) | `app/Http/Controllers/WordPressConnectController.php` | OAuth-style consent screen; mints scoped Sanctum token, 302s back with `?ebq_token=` |
| Embed (deep-link in) | `app/Http/Controllers/WordPressEmbedController.php` | Signed entry points that log the Website owner in and redirect to EBQ.io reports / page-audit |
| Version metadata | `app/Http/Controllers/WordPressPluginVersionController.php` | Public JSON: latest version, download URL, `updates_enabled`, `global_features`, banner |
| Download | `app/Http/Controllers/WordPressPluginDownloadController.php` | Streams the latest packaged ZIP with no-cache headers |
| HQ API | `app/Http/Controllers/Api/V1/PluginHqController.php` | The whole HQ admin dashboard API (~40 endpoints) — see [hq-api.md](./hq-api.md) |
| Auth | `app/Http/Middleware/WebsiteApiAuth.php` | Bearer → Website resolver + ability gate (`website.api:read:insights`) |
| Flag injection | `app/Http/Middleware/InjectFeatureFlags.php` (alias `website.features`) | Stamps `features`/`frozen`/`tier`/`free_promo` onto every JSON response |
| Releases (admin) | `app/Http/Controllers/Admin/PluginReleaseController.php` | Create / publish / schedule / rollback / delete releases; toggle the global update switch |
| Feature flags (admin) | `app/Http/Controllers/Admin/WebsiteFeatureController.php` | Per-website + global feature-flag grid |
| Adoption (admin) | `app/Http/Controllers/Admin/PluginAdoptionController.php` | Per-website install + token-usage listing |
| Release resolver | `app/Services/PluginReleaseResolver.php` | `latestPublished()`, `publishScheduled()`, `markPublished()` (one-published-per-channel invariant) |
| Source/packaging | `app/Services/WordPressPluginSourceService.php` | Rewrites `Version:`/`EBQ_SEO_VERSION` in `ebq-seo-wp/ebq-seo.php`, runs `ebq:package-plugin` |
| Insight resolver | `app/Services/PluginInsightResolver.php` | Per-post insight payload (GSC totals, tracked-rank, cannibalization/striking flags) |

## Auth model (Sanctum token per Website)

- **Tokenable is a `Website`, not a `User`.** `Website` is Sanctum's `HasApiTokens`
  model; `$website->createToken('WordPress — host', ['read:insights'])` mints the token
  in `WordPressConnectController::approve` (`WordPressConnectController.php:77`).
- **Resolution** — `WebsiteApiAuth::handle` (`WebsiteApiAuth.php:23`) finds the token,
  rejects expired ones, asserts the tokenable is a `Website`, enforces the ability arg
  (`read:insights`), bumps `last_used_at`, and sets `api_website` + `api_token` request
  attributes. `PluginHqController::website()` (`PluginHqController.php:1657`) reads
  `api_website` back — it never re-resolves from a param, so a spoofed body/query can't
  cross-tenant.
- **Connect anti-exfiltration** — `site_url` and `redirect` must share a host
  (`WordPressConnectController.php:40`,`:73`); the token is bound to the Website the
  logged-in EBQ user *picks*, not derived from `site_url`.
- **Every call is logged** — `ClientActivityLogger` records `plugin.api_request` with
  path + ability per request (`WebsiteApiAuth.php:52`).

## Gotchas / known issues

- **`/wordpress/connect/version/plugin.zip` routes are public** (no auth) — the version
  endpoint and download intentionally broadcast to *unconnected* installs. No install
  identifier is captured server-side from them (see `WordPressPluginVersionController`
  comments). Don't add per-install logging there.
- **Opcache** — editing any plugin-serving PHP needs a full `php8.3-fpm` restart, not a
  reload (see `CLAUDE.local.md`). The HQ API runs under FPM.
- **Embed logs the Website *owner* in** (`WordPressEmbedController.php:25`) — any signed
  deep-link impersonates `$website->user`. Routes are `signed`-middleware protected;
  don't loosen that.
- **HQ API reads no crawl tables.** Findings/pages/links come via `ReportDataService`
  (GSC-scoped or routed through `CrawlReportService`), never `website_pages` /
  `crawl_findings` directly. Re-verify if a new endpoint starts touching them.
- **One published release per channel** — `markPublished()` rolls back the previous
  published row in the same channel before promoting the new one
  (`PluginReleaseResolver.php:67`). Publishing also mutates the **source tree** and
  re-packages, which can fail on a read-only FS — see [releases.md](./releases.md).

## Key files

- Connect/embed/version/download — `app/Http/Controllers/WordPress*Controller.php`
- HQ API — `app/Http/Controllers/Api/V1/PluginHqController.php`, routes `routes/api.php:91`
- Auth + flags — `app/Http/Middleware/{WebsiteApiAuth,InjectFeatureFlags}.php`,
  aliases `bootstrap/app.php:36`
- Releases — `app/Http/Controllers/Admin/{PluginReleaseController,PluginAdoptionController,WebsiteFeatureController}.php`,
  `app/Services/{PluginReleaseResolver,WordPressPluginSourceService}.php`,
  `app/Console/Commands/{PublishScheduledPluginReleases,ApplyPluginVersionCommand,PackageWordPressPlugin}.php`,
  model `app/Models/PluginRelease.php`
- Plugin source — `ebq-seo-wp/ebq-seo.php` (header `Version:`, `EBQ_SEO_VERSION` define)
