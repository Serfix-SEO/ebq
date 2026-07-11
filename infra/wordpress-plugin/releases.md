# Plugin releases, adoption & flags

> How a new `ebq-seo` plugin build ships to installs, the admin controls that gate
> it, and the per-website / global feature-flag signals broadcast on every API call.

## Overview

The plugin binary is tracked in a DB table (`plugin_releases`) but **served from a
single public file** (`public/downloads/ebq-seo.zip`). The DB row is the source of
truth for *which* version is "published"; publishing copies/packages the actual ZIP to
that public path. Installs poll `GET /wordpress/plugin/version` (public, unauthenticated)
to learn the latest version + download URL, and the WP native update flow pulls the ZIP
from `GET /wordpress/plugin.zip`.

## Current state (2026-07-11)

**v2.0.9 is the published `stable` release.** 2.0.9: closed the wrong-website
residue 2.0.8 left open — ALL remaining raw portal links now carry an
`?ebq_site=<domain>` hint consumed server-side by the new `ApplyWebsiteHint`
web middleware (switches `current_website_id` only among the user's accessible
websites — unsigned by design, safe for any WP role, works for teammates with
their own Serfix logins; see
[../reference/http-and-auth.md](../reference/http-and-auth.md)). Plugin side:
`src/sidebar/utils/portalUrl.js` builds every sidebar portal link
(rank-tracking / custom-audit / page-audits/{id} / settings CTAs in
`InsightsTab`/`SeoTab`/`BriefTab`/`dependencyMessages`; hint =
`cfg.workspaceDomain` from the `ebq_website_domain` option set at connect,
fallback `homeUrl` host), and the dashboard-widget fallback hrefs append the
hint too. 2.0.8 (owner QA on a multi-website
account): WP dashboard widget insight cards (Striking distance etc.) linked plain
`{base}/reports?insight=…` — no website identity — so Serfix opened whatever
website the session last had selected. Cards now carry `data-ebq-insight`;
`dashboard-hydrate.js` intercepts the click, fetches a signed embed URL via
`/ebq/v1/hq/iframe-url` (same path the HQ tab uses; `WordPressEmbedController::reports`
sets `current_website_id` from the signed `website` param) and opens it — tab
opened synchronously pre-fetch so popup blockers don't eat it; plain href kept
as fallback (non-admins get 403 from the `manage_options`-gated proxy route —
intentional: signed embed links log in as the website OWNER, never hand them to
mere editors). The editor-sidebar raw links 2.0.8 left open were closed in
2.0.9 via the `ebq_site` hint (above). 2.0.7: Site-issues digest on the
HQ Overview (SiteIssuesCard → `?ebq_subtab=issues` deep link; subtab whitelist
in `current_subtab_id()` now includes issues/pages/links) and in the WP
dashboard widget (`dashboard_html` proxy also fetches `hq_site_audit_summary`,
best-effort; severity pills + "Fix N issues" CTA). 2.0.6: menu icon served as an SVG
FILE — **WP core's svg-painter.js repaints base64 data-URI menu icons to the
admin color scheme (every `fill` becomes grey/white)**, which turned the brand
tile into a white square; file URLs render as a plain `<img>` and are left
alone. Never register a colored base64 SVG menu icon. 2.0.5: GSC Pages report
gained a per-page Site Audit CTA ("Fix N issues →" / "Healthy") deep-linking
into Link Explorer via `?ebq_link_url=`; backed by
`CrawlReportService::findingCountsForUrls()` (batch URL→count, tolerates
www/slash variants, absent = not crawled). 2.0.4: filemtime cache-buster on
the inline-AI toolbar icon — the file was swapped in-place and browsers kept
the old logo cached; HQ Pages report merges www/non-www host variants via
`REPLACE(page, '://www.', '://')` grouping — long ranges showed both hosts of
the same path as apparent duplicates. Prior 2.0.3: Pages issue counts +
Links/Audit row actions, Serfix icon everywhere — admin menu, HQ header,
editor sidebar, inline-AI toolbar; also regenerated the PLATFORM's stale EBQ
favicons: `public/favicon.{svg,ico}`, `favicon-32.png`, `apple-touch-icon*.png`,
`logo*.png` now all carry the Serfix zigzag mark.)
Prior: **v2.0.2** (2.0.0 was the first `plugin_releases`
row ever — before it the table was empty and `public/downloads/ebq-seo.zip` was a
stale 1.0.5 static file; 2.0.1 added the Overview "Where to start" top-issues list
and per-page issues in Link Explorer; 2.0.2 fixed spacing — the tabs' `.ebq-hq-stack`
wrapper class had NO css definition, so every section butted flush; both from owner
QA on pubgnamegenerator.net).
`zip_path=@public`; ZIPs are built with `npm run dist` in the plugin repo and
copied over the public file. `GET /wordpress/plugin/version` serves the published
version; **the ZIP download still 404s while `WP_PLUGIN_COMING_SOON=true`** —
the update *offer* shows on older installs but the download only works after
the flag flips. The owner's test install (see README § Live test install) is
updated by scp'ing the ZIP directly.
Flip procedure: `.env` both boxes → `WP_PLUGIN_COMING_SOON=false`,
`php artisan config:clear`, FPM restart (web) + container restart (worker).

## Release model

`app/Models/PluginRelease.php`. Key fields: `slug` (always `ebq-seo`), `version`,
`channel` (`stable` | `beta`), `status`, `zip_path`, `publish_at`, `published_at`,
`rolled_back_at`, `rollback_of_id`, `release_notes`, `created_by`.

| Constant | Value | Meaning |
|---|---|---|
| `STATUS_DRAFT` | `draft` | saved, not live |
| `STATUS_SCHEDULED` | `scheduled` | will publish when `publish_at` ≤ now |
| `STATUS_PUBLISHED` | `published` | the live release for its channel |
| `STATUS_ROLLED_BACK` | `rolled_back` | demoted (superseded or rolled back) |
| `ZIP_PUBLIC_BUILD` | `@public` | sentinel: ZIP lives at `public/downloads/ebq-seo.zip`, not in storage |

`zip_path` is either `@public` (the live public file) or a `plugin-releases/...` path
under `storage/app/local` (an operator-uploaded ZIP stashed so it survives draft →
publish).

## Resolver — `PluginReleaseResolver`

`app/Services/PluginReleaseResolver.php`:

- **`latestPublished($channel)`** (`:16`) — newest `published` row with
  `published_at <= now`, ordered by `published_at desc, id desc`. Wrapped in a
  `QueryException` catch → returns `null` so a missing table never 500s the public
  version/download endpoints.
- **`publishScheduled()`** (`:33`) — picks up due `scheduled` rows, packages each
  (`syncVersionAndPackage`), flips `zip_path` to `@public`, calls `markPublished`.
  Build failures are logged and **skipped**, not fatal.
- **`markPublished($release)`** (`:67`) — **invariant: one published release per
  (slug, channel)**. Demotes every other published row in the channel to `rolled_back`,
  then promotes this one and clears its `rolled_back_at`.

## Packaging — `WordPressPluginSourceService`

`app/Services/WordPressPluginSourceService.php`. `syncVersionAndPackage($version)`
(`:40`):

1. `setVersionInSource` rewrites **both** the `Version:` header line and the
   `EBQ_SEO_VERSION` define in `ebq-seo-wp/ebq-seo.php` (regex, validated against
   `\d+\.\d+\.\d+(-suffix)?`).
2. Runs `php artisan ebq:package-plugin --output public/downloads/ebq-seo.zip`.

**Gotcha — writable source tree.** Publishing/scheduling that goes through this path
*mutates the deployed source tree*. If the FPM user can't write `ebq-seo.php`, it throws
with a `chown`/ACL hint and points to the SSH escape hatch
`php artisan ebq:apply-plugin-version <version> --package` (`:100`,
`ApplyPluginVersionCommand.php`). The UI **disables source-tree packaging in production**
— `PluginReleaseController::store` *requires* a ZIP upload for `now`/`schedule`
(`PluginReleaseController.php:61`), so the normal path is upload → promote, not rewrite.

## Admin release flow — `PluginReleaseController`

`app/Http/Controllers/Admin/PluginReleaseController.php`, routes `routes/web.php:286`.

| Action | Route | Effect |
|---|---|---|
| `index` | `GET /admin/plugin-releases` | paginated list + source version + update-switch state |
| `store` | `POST .../` | create draft / publish-now / schedule. Uploaded ZIP stashed under `plugin-releases/`; `now` promotes it to public + `markPublished` |
| `publish` | `POST .../{r}/publish` | promote a stored ZIP (or package from source) → `@public`, `markPublished` |
| `uploadZip` | `POST .../{r}/zip` | attach/replace a ZIP on a draft/scheduled release |
| `rollback` | `POST .../{r}/rollback` | demote current; **restore the prior release** (re-package its version, re-promote) if one exists (`:161`) |
| `destroy` | `DELETE .../{r}` | delete a non-published release + its stored ZIP |
| `toggleUpdates` | `POST .../toggle-updates` | flip the global update kill-switch (`plugin.updates_enabled`) |

- **publish-now requires a ZIP** in production (source packaging blocked from UI).
- **Can't delete a published release** — roll it back first.
- **Promote = `copy()` to `public/downloads/ebq-seo.zip`** (`promoteUploadedZipToPublic`,
  `:246`). The public folder is gitignored — the live ZIP lives outside source control
  by design.
- Every action writes a `ClientActivityLogger` audit entry.

## Scheduled publish (cron)

`routes/console.php:18` schedules `ebq:publish-scheduled-plugin-releases`
**every minute**. The command (`PublishScheduledPluginReleases.php`) just calls
`resolver->publishScheduled()` and logs `plugin_release.scheduled_published` when
count > 0. So a `schedule`d release goes live within ~1 min of its `publish_at`.

## Version & download endpoints (public)

| Endpoint | Controller | Returns / serves |
|---|---|---|
| `GET /wordpress/plugin/version` | `WordPressPluginVersionController` | JSON: `version`, `channel`, `download_url`, `packaged_at`, `requires.{wp,php}`, `tested`, `updates_enabled`, `release_notes`, **`global_features`**, **`banner`**. `Cache-Control: no-store` |
| `GET /wordpress/plugin.zip` | `WordPressPluginDownloadController` | streams the published ZIP (or the public fallback) with aggressive no-cache headers; filename embeds version or mtime so each re-package is a distinct artifact |

Both accept `?channel=stable|beta` (anything else → `stable`). Both are **anonymous** —
no install identifier is captured server-side (matches the public pricing endpoint).
Version falls back to parsing the source-file header when no published row exists.

## Kill-switches & feature flags

Two independent broadcast channels, both reaching even **unconnected** installs via the
version endpoint:

1. **Global update switch** — `Setting('plugin.updates_enabled')`. When `0`, the version
   endpoint sets `updates_enabled:false` and every install's `EBQ_Updater` suppresses the
   "update available" offer (`WordPressPluginVersionController.php:52`). Absent ⇒ enabled
   (back-compat).
2. **Per-feature flags** — `Website::FEATURE_KEYS` (8: chatbot, ai_writer, ai_inline,
   live_audit, hq, redirects, dashboard_widget, post_column). Two layers:
   - **Global** map in `Setting('global_feature_flags')`, edited via
     `WebsiteFeatureController::globalUpdate` — broadcast as `global_features` on the
     version endpoint. A global `false` always wins (AND'd against per-site).
   - **Per-website** overrides in `websites.feature_flags` JSON, edited via
     `WebsiteFeatureController::update`. **Stored as the full TRUE/FALSE map**, not just
     falses — because per-key defaults differ (chatbot/ai_writer default-off), storing
     only falses would silently drop an explicit ON (`WebsiteFeatureController.php:96`).

   Connected installs pick up flag changes within **seconds** of any API round-trip
   (`InjectFeatureFlags` stamps `features` on every response); unconnected installs pick
   them up on the next ~6 h version poll. Core SEO output is **never** gated server-side.

## Freeze signal

`InjectFeatureFlags` also stamps `frozen` / `frozen_reason` / `tier` / `free_promo`
(`InjectFeatureFlags.php:77`). `frozen` is derived live from the user's plan limit +
ordered websites — no DB write — so plan-limit freezes/unfreezes propagate on the next
API round-trip. `tier` is overwritten with the **freeze-aware** value so an over-limit
site sees `free` even on a Pro account.

## Adoption tracking — `PluginAdoptionController`

`app/Http/Controllers/Admin/PluginAdoptionController.php`, `GET /admin/plugin-adoption`.
Lists websites (filterable by domain) with their `pluginInstall` relation and a
`PersonalAccessToken` aggregate (token count + `MAX(last_used_at)`) per Website — so an
admin can see which sites are connected and last active. Read-only.

## Gotchas

- **One published per channel** — never hand-flip a row to `published` without
  `markPublished` or you'll have two live releases.
- **`@public` is a single shared file** — promoting any release overwrites the same
  `public/downloads/ebq-seo.zip`; concurrent publishes race on that copy.
- **Scheduled builds can silently no-op** — `publishScheduled` logs + skips on build
  failure; check `plugin_release.scheduled_published` count / logs after a scheduled
  release.
- **Source-tree publish needs a writable FS + FPM restart** to actually serve new code
  (opcache, see `CLAUDE.local.md`). The ZIP-upload path avoids the writable-source
  requirement entirely.

## Key files

- Model — `app/Models/PluginRelease.php`
- Resolver / packaging — `app/Services/{PluginReleaseResolver,WordPressPluginSourceService}.php`
- Admin — `app/Http/Controllers/Admin/{PluginReleaseController,PluginAdoptionController,WebsiteFeatureController}.php`
- Public endpoints — `app/Http/Controllers/{WordPressPluginVersionController,WordPressPluginDownloadController}.php`
- Commands — `app/Console/Commands/{PublishScheduledPluginReleases,ApplyPluginVersionCommand,PackageWordPressPlugin}.php`,
  schedule `routes/console.php:18`
- Flag injection — `app/Http/Middleware/InjectFeatureFlags.php`; flags on `Website` (`FEATURE_KEYS`, `globalFeatureFlags`, `effectiveFeatureFlags`, `effectiveTier`, `isFrozen`)
