# WordPress Plugin v2.0.0 — full production update

## Context

The shipped plugin (v1.0.5) is stale and currently suppressed platform-wide behind `WP_PLUGIN_COMING_SOON` ("Coming soon" on pricing/landing/settings; ZIP download 404s). The platform has moved far past it: serfix.io domain migration (plugin still defaults to `https://ebq.io`, whose 301 breaks every POST — heartbeat, AI, keyword CRUD), Serfix rebrand + orange brand tokens (plugin accent is still violet `#5b3df5`, `src/tokens.css:34`), and — the biggest gap — **crawler reports have zero presence in the plugin**: no JSON API exists over the crawl subsystem at all (only signed Livewire iframe embeds). Goal: one production-ready release — new Site Audit (crawler reports) + Keyword Finder surfaces, domain/brand fixes, UI refresh — published through the existing PluginRelease pipeline, then hand over for user QA before flipping `WP_PLUGIN_COMING_SOON=false`.

**User decisions:** Keyword Finder INCLUDED in this release. User QAs before the coming-soon flag flips. QA on a throwaway local Docker WordPress.

Copy of this plan goes to `/var/www/ebq/WP_PLUGIN_V2_PLAN.md` (project-root plan convention) as step 0.

---

## Phase A — Platform: new JSON APIs (deploy first; purely additive, no migrations)

### A1. Crawl / Site Audit API — new `app/Http/Controllers/Api/V1/PluginCrawlController.php`

Constructor-injects `CrawlReportService` (`app/Services/Crawler/CrawlReportService.php` — all methods verified present and website-id-scoped; ULIDs everywhere: never int-cast, never `%d` keys). Copy the `website(Request)` helper from `PluginHqController.php:1658` (`api_website` request attribute only — no param tenancy). Routes go in `routes/api.php` inside the existing `website.api:read:insights` → `website.features` → `throttle:60,1` group, `hq` prefix:

| Route | Service calls | Returns |
|---|---|---|
| `GET /v1/hq/site-audit/summary` | `summary($id)` (uncached, cheap) | health score, run status/recency, blocked+reason, page counts, severity totals; Carbon → ISO8601 |
| `GET /v1/hq/site-audit/issues` | `actionGroups($id)` (24h version-keyed cache) | issue groups by category w/ counts, severity, impact, per-type chips |
| `GET /v1/hq/site-audit/issues/{category}` | `typeBreakdown()` + `issuesQuery()->simplePaginate(≤50)` + `fixGuidance()`/`auditAbout()` | type filters + paginated findings + guidance |
| `GET /v1/hq/site-audit/pages` | `inventory($id, $filter)` → simplePaginate ≤50 | crawled-page inventory |
| `GET /v1/hq/site-audit/page?url=` | `pageIntel()` + `pageFindings()` | per-URL detail; JSON 404 if not crawled |
| `GET /v1/hq/site-audit/links?url=` | `pageLinkStructure()`; empty url → `topInboundPages($id, 8)` suggestions | link-explorer data |

- Validate `{category}` with `Rule::in` against `CrawlFinding` category constants → 422 on unknown (never reaches cache-key construction).
- `simplePaginate` only (counts come from cached `typeCounts`); `per_page` default 25, cap 50.
- **Gate on the existing `hq` feature flag** (`Plan.php:38`, `Website.php:165`) — do NOT add a new `site_audit` key: `featureMap()` defaults missing keys to `false`, so a new key kills the feature for every existing plan row until a prod-data backfill. Mirror `PluginHqController`'s frozen-website handling.
- **Must work with zero GSC** (crawl-only rule): impact fields degrade to 0, all endpoints 200.

### A2. Keyword Finder API — new `app/Http/Controllers/Api/V1/PluginKeywordFinderController.php`

Wraps the async fleet (`app/Services/KeywordFinder/KeywordFinderPool.php` `dispatchIdeas()`/`dispatchVolume()`, `KeywordApiRequest` lifecycle, results via webhook → `keyword_metrics`). Reuse the orchestration patterns from `app/Livewire/Keywords/KeywordIdeaFinder.php` (`run()`/`poll()`, MAX_SEEDS=20) and `KeywordVolumeFinder.php` — extract shared logic into a small service only if it falls out naturally; do not rewrite the Livewire components.

- `POST /v1/hq/keyword-finder/ideas` — seeds (≤20) or url mode, location/language via `KeywordFinderLocations`; check `KeywordIdeasMonthlyCache` first (30-day cache → instant answer, no dispatch); else dispatch, return `{request_id, status:'running'}`. `user_id` null / `website_id` from token on the `KeywordApiRequest` row.
- `POST /v1/hq/keyword-finder/volume` — keywords ≤100; serve `keyword_metrics` rows already fresh (≤30d, `data_source='gkp'`) inline, dispatch only the misses.
- `GET /v1/hq/keyword-finder/requests/{requestId}` — poll; scope: request's `website_id` must equal token website (404 otherwise). Returns status + results when completed (same bucketed-volume shaping the Livewire views use — **no $ money projections**, per no-dollar-projections rule).
- **Extra rate limit** on the POST routes (fleet runs QUEUE_CONCURRENCY=1): `RateLimiter` in-controller, e.g. 10 dispatches/website/day + `throttle:10,1` on the route. Return a clear 429 JSON the plugin can render ("Try again later" neutral copy).
- Gate on `hq` flag as well.

### A3. Tests — `tests/Feature/Api/V1/PluginCrawlApiTest.php` + `PluginKeywordFinderApiTest.php`

Model on `tests/Feature/Api/V1/InsightsAuthTest.php` (**sqlite :memory: — run config:clear + verify per CLAUDE.md before any test run**). Cover: 401 no token / 403 wrong ability; tenancy (website A token never sees B's findings/requests); `hq` flag false → 403; summary shape incl. `has_crawl=false` never-crawled site; pagination cap + `has_more`; bogus category → 422; crawl-only (no GSC) → 200 with zero impact; keyword-finder dispatch caps (21 seeds → 422, 101 keywords → 422), cached-hit path (no dispatch), poll tenancy, daily rate limit.

### A4. Docs + deploy

- `infra/wordpress-plugin/hq-api.md` (+ `plugin-features.md`) — document all new endpoints/shapes/gating in same change; `infra/main.md` changelog line.
- Deploy: FPM restart both boxes (opcache). Worker rsync + docker restart (cheap insurance). Smoke-test with a real website token via curl before Phase B QA.

---

## Phase B — Plugin (repo `/var/www/ebq/ebq-wordpress-plugin`, ships as v2.0.0)

### B1. Domain migration (correctness, do first)

- `includes/class-ebq-api-client.php:15` `DEFAULT_BASE` → `https://serfix.io`.
- Versioned upgrade routine (option `ebq_plugin_version`, run on `plugins_loaded` when `version_compare < 2.0.0`): if `ebq_api_base_override` normalizes to an ebq.io host → `delete_option` (custom/self-hosted overrides untouched); flush the updater version transient; stamp new version.
- Admin notice if `EBQ_API_BASE` constant points at ebq.io (can't rewrite wp-config).
- **Platform-side**: change the ebq.io → serfix.io redirect to **308** for `/api/*` + `/wordpress/*` (preserves POST method) so pre-2.0 installs keep working during rollout. Apache vhost on this box (find the ebq.io vhost redirect rule; same landmine already documented in worker-box-env-domain-drift memory).
- Sweep hardcoded `https://ebq.io` in JS/PHP: `src/hq/App.jsx:88`, `class-ebq-hq-page.php:612`, setup wizard, dashboard widget, connect — use `EBQ_Api_Client::base_url()` (PHP) / a `baseUrl` field in the localized HQ config (JS). Never hardcode serfix.io in JS.

### B2. Site Audit tab set (native React)

- `includes/class-ebq-rest-proxy.php`: add `manage_options` proxy routes mirroring A1 (pass through `page/per_page/type/severity/q/url/filter`); 30s timeout on `/issues` (cold `actionGroups` cache).
- `includes/class-ebq-api-client.php`: matching client methods.
- `includes/class-ebq-hq-page.php`: add `site_audit` to `SECTIONS` (own submenu + `initialTab`, the existing pattern).
- New React: `src/hq/tabs/SiteAuditTab.jsx` (sub-views via existing `SubTabBar`): **Overview** (health dial, severity tiles, crawl recency/run-status/blocked banner), **Issues** (category group cards → drill-down: type/severity filters, URL search, load-more findings table), **Pages** (paginated inventory), **Link Explorer** (URL input + inbound/outbound, seeded by top-inbound suggestions). Components under `src/hq/components/siteaudit/` (IssueGroupCard, IssueDrilldown, PageDetailDrawer, SeverityBadge, HealthScore); reuse `DataTable.jsx`/`Modal.jsx`/`primitives.jsx`.
- Explicit states: never-crawled (CTA → open Serfix dashboard), `blocked` + reason, crawl running, API timeout/error.

### B3. Keyword Finder tab

- Proxy routes + client methods for A2 endpoints.
- `src/hq/tabs/KeywordFinderTab.jsx`: seeds/url input (≤20 seeds), location/language selects, dispatch → poll (reuse the Rank Tracker tab's polling pattern) → results table (keyword, bucketed volume, competition, bid range — no money projections), "track this keyword" hand-off to existing `POST /hq/keywords`, plus a Volume Checker sub-view (paste ≤100 keywords). Friendly 429/pending states ("Results take a minute or two" — neutral client copy).
- Add `keyword_finder` to `SECTIONS`.

### B4. Rebrand + UI refresh + removals

- `src/tokens.css:33-38`: accent block → `#F26419` / hover `#C44E0E` / soft `#FDE8DC` / foam `#FEF4EE` / ring `rgba(242,100,25,.22)`. Sweep `grep -i '5b3df5\|4c2bd0\|ede9fe\|f5f3ff\|indigo\|violet' src/` to zero (brand rule: never reintroduce indigo/violet).
- Topbar mark `E`→`S`; sweep user-facing "EBQ" strings → "Serfix". Keep slugs / option names / text domain `ebq-seo` / REST namespace `ebq/v1` (update continuity).
- **Remove ProspectsTab** (dead: `HQ_PROSPECTS_ENABLED=false`, `class-ebq-hq-page.php:26`): delete JSX + `ALL_TABS` entry + const/filter plumbing + its proxy routes (platform endpoints stay).
- AI Studio menu label → "AI Studio (Beta)" (matches platform badge).
- `src/hq/components/primitives.jsx`: shared `Skeleton`/`EmptyState`/`ErrorState`; mandatory on the two new tabs, retrofit the 3 worst existing tabs (Overview, Pages, Keywords) — timeboxed cosmetic pass, rest deferred.

### B5. Version, i18n, docs

- `ebq-seo.php` header + `EBQ_SEO_VERSION` define → **2.0.0**; `readme.txt` stable tag + changelog (Site Audit, Keyword Finder, serfix.io, rebrand, UI refresh, Prospects removal).
- New strings `__('…', 'ebq-seo')`; regenerate POT via repo's make-pot script; existing locales fall back to English (translations = v2.1 chore).
- Update `infra/wordpress-plugin/plugin-source.md`, `plugin-features.md`, `releases.md`.

---

## Phase C — Build, QA, release, handover

1. `npm run build` → `npm run dist` (`scripts/make-dist.cjs`) → ZIP.
2. **Local Docker WP QA**: throwaway `wordpress:latest` + db containers on this box (scratchpad compose file), install ZIP, connect to a real test website on serfix.io (mint via the connect flow using the DB-session recipe if browser login is needed), QA with headless Chrome/puppeteer: connect flow, every HQ tab incl. Site Audit (crawled site / never-crawled / crawl-only no-GSC) + Keyword Finder (dispatch→poll→results, cap errors), Gutenberg sidebar, dashboard widget. **Upgrade-path test**: install a 1.0.5 ZIP with `ebq_api_base_override=https://ebq.io`, upgrade to 2.0.0, confirm option cleared + traffic → serfix.io + updater works through the ebq.io 308.
3. Publish: upload ZIP via `/admin/plugin-releases` → publish; verify `GET /wordpress/plugin/version` returns 2.0.0 + serfix.io URL; verify auto-update offer appears on the QA install.
4. Tear down QA containers. Delete any QA bug rows/test data created.
5. **Hand over to user for manual QA. Do NOT flip `WP_PLUGIN_COMING_SOON` yet.** Provide flip instructions: `.env` on both boxes → `WP_PLUGIN_COMING_SOON=false`, `php artisan config:clear`, FPM restart web box, docker restart worker containers — all marketing/pricing/settings copy un-suppresses automatically (single config flag; download controller 404 lifts).

## Risks

- `actionGroups` cold cache after crawl finalize is slow on big sites → 30s proxy timeout + error state; warmed by `WarmDashboardCaches` in practice.
- Old 1.0.x installs POST to ebq.io until updated → mitigated by the 308 redirect change (do during Phase A deploy).
- Keyword fleet is 1-concurrency → strict dispatch caps + honest pending/429 UI; heavy plugin adoption could saturate it (monitor `keyword_api_requests` after launch).
- Issue labels arrive in English regardless of WP locale (API runs `en`) — accepted for 2.0.0; Accept-Language passthrough is a v2.1 item.
- Plugin git history is messy — commit this work cleanly (conventional messages) but no history rewrite.

## Deferred to v2.1

Per-plan `site_audit` flag (+ backfill), Accept-Language locale, .po translations of new strings, standalone PageSpeed tab, UI retrofit of the remaining tabs.

---

## STATUS (2026-07-10): COMPLETE — awaiting user QA + coming-soon flip

All phases shipped: platform APIs live + tested (15/15), plugin v2.0.0 built,
E2E-verified on a throwaway Docker WP (real falik.com crawl data + real fleet
keyword lookup), and published as the stable release. `GET /wordpress/plugin/version`
serves 2.0.0; the ZIP download 404s until `WP_PLUGIN_COMING_SOON=false` is set on
both boxes (config:clear + FPM/container restart). Bonus fix: shipped 1.0.5 had
broken bundle enqueue on all HQ section pages (WP submenu-hook prefix bug) — fixed.
