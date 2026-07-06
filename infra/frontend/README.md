# Frontend / UI architecture

How the EBQ web app (`ebq.io`) renders: a **server-rendered Blade + Livewire 3**
monolith with **Alpine.js** for client interactivity and **Tailwind 4** (Vite) for
styling. There is **no SPA, no API-driven client app** — every page is a Blade view,
and the dynamic parts are Livewire components embedded inside those views.

Scope: 50 Livewire components (`app/Livewire/`), 173 Blade views (`resources/views/`),
assets in `resources/{js,css}`. Marketing + meet are separate surfaces (see
[Multi-frontend](#multi-frontend--ebqio-vs-marketing-vs-meet)).

## Stack (confirmed versions)

| Layer | Tech | Where | Notes |
|---|---|---|---|
| Server UI | Livewire **3** | `app/Livewire/`, `resources/views/livewire/` | bundles its own Alpine on portal pages |
| Client JS | **Alpine.js 3.15** (`package.json:20`) | inline `x-data` in Blade | only standalone-booted on marketing (see below) |
| CSS | **Tailwind 4.1** via `@tailwindcss/vite` (`package.json:11-16`) | `resources/css/app.css` | CSS-first config (`@theme`, `@plugin`, `@custom-variant`) — **no `tailwind.config.js`** |
| Bundler | **Vite 7** + `laravel-vite-plugin 2` | `vite.config.js` | 3 entrypoints; `refresh: true` for HMR |
| HTTP | axios (window global) | `resources/js/bootstrap.js` | sets `X-Requested-With` |
| Fonts | Inter via `fonts.bunny.net` | `<head>` of every layout | preconnected, not bundled |

### Bundling & build output

- **Entrypoints** (`vite.config.js:8`): `resources/css/app.css`,
  `resources/js/app.js`, `resources/js/marketing.js`.
- `npm run build` → **`public/build/`** (manifest + hashed assets). `npm run dev`
  runs the Vite dev server with HMR (`refresh: true` reloads on Blade/PHP change).
- Blade pulls assets with `@vite([...])`, **guarded by `! app()->environment('testing')`**
  (`layouts/app.blade.php:11`, `guest.blade.php:13`, `marketing/page.blade.php:78`) so
  the test suite never needs a manifest.
- Tailwind scans `@source` globs in `app.css:8-11` — includes
  `storage/framework/views/*.php` (compiled Blade) and all `**/*.blade.php`/`**/*.js`,
  so utility classes used **anywhere** (incl. Livewire placeholder HTML strings) are kept.

### CSS theming gotchas

- Dark mode is **class-based**: `@custom-variant dark (&:where(.dark, .dark *))`
  (`app.css:6`). The `.dark` class is toggled by Alpine on `<html>` in the app layout
  (`layouts/app.blade.php:1`), persisted in `localStorage('dark')`. Marketing/auth/guest
  layouts are **light-only** (no toggle).
- `[x-cloak]{display:none !important}` (`app.css:23`) hides Alpine-bound elements until
  Alpine boots — load-bearing for marketing tab/panel reveals (see `marketing.js` note).

### Brand tokens (SERFIX, `app.css:13-40`)

Per `serfix-branding/SERFIX Brand Guidelines.pdf`. **`orange` is overridden in `@theme`**
to the exact brand ramp (not Tailwind's stock orange) — `orange-600` = `#F26419` (primary
accent/CTA), `orange-700` = `#C44E0E` (hover), rest of the 50–950 scale interpolated at the
same hue/saturation. Every `bg/text/border/ring-orange-*` utility across the app resolves
to this ramp automatically. Also defined: `ink`/`ink-500`/`ink-300` (text scale),
`surface-warm`/`surface-cool`, and `success`/`warning`/`error` semantic colors. `font-arabic`
(Cairo) is registered for the guideline's bilingual-by-architecture direction but **no
Arabic/RTL UI exists yet** — Latin `font-sans` (Inter) is still the only font actually wired
into layouts.

The whole app was previously themed in **indigo** (`#4f46e5` = Tailwind `indigo-600`, the
old EBQ brand color). The 2026-07-06 rebrand mechanically renamed every `indigo-NNN` Tailwind
class to `orange-NNN` (1300+ occurrences, 103 view files) plus hardcoded indigo hex literals
in non-Tailwind contexts (emails, dompdf PDF templates, inline SVG chart strokes) to their
matching orange-ramp hex. **If you add new UI, use `orange-*` for brand accent — never
reintroduce indigo/violet/purple**, which have no place in the palette (a leftover
`from-orange-600 to-violet-600` two-tone CTA gradient from the old brand was caught and
flattened to solid `bg-orange-600` during the sweep; check for other stray non-brand hues
before shipping new gradients).

## JS entrypoints

| File | Loaded by | Purpose |
|---|---|---|
| `resources/js/app.js` | app + auth/guest layouts | one line: `import './bootstrap'`. Alpine here comes **from Livewire** (Livewire 3 ships Alpine), so app.js does NOT boot its own. |
| `resources/js/bootstrap.js` | both bundles | exposes `window.axios` with `X-Requested-With` header. |
| `resources/js/marketing.js` | marketing layout only | **standalone-boots Alpine** (`Alpine.start()`). Marketing/guest-tool pages are **not** Livewire pages, so they don't get Livewire's injected Alpine; the shared report partials use `x-data`/`x-show`/`x-cloak` and would stay hidden without it (`marketing.js:4-13`). Loaded only here → never collides with Livewire's Alpine on portal pages. |

## Layouts (the view shells)

Two Blade **components** under `resources/views/components/layouts/` + one marketing
component. Pages render as `<x-layouts.app>…</x-layouts.app>` wrappers — there are
**no full-page Livewire route registrations**; routes resolve to Blade views
(`routes/web.php`, e.g. `Route::view('/dashboard', 'dashboard')` line 147) that embed
Livewire components via `<livewire:…>` tags.

| Layout | File | Used by | Shell |
|---|---|---|---|
| App (portal) | `components/layouts/app.blade.php` | authenticated app + admin | sidebar nav, top bar (`<livewire:website-selector>`, dark toggle, user menu), impersonation/quota/connect-source banners, `<livewire:connect-sources-modal>`. Dark-mode capable. |
| Guest (auth) | `components/layouts/guest.blade.php` | login / register / password | split-screen brand panel + form `{{ $slot }}`. Light-only. |
| Marketing | `components/marketing/page.blade.php` | public pages + guest tools | header/footer nav, full SEO meta + JSON-LD (Organization/WebSite schema), `@vite([…/marketing.js])`. |

### Sidebar nav (app layout) — the one place to edit nav

`layouts/app.blade.php:26-180` builds nav from two PHP arrays:

- **`$navItems`** (`:30-51`) — the product nav (Dashboard, Keywords, Rank Tracking,
  Pages, Sitemaps, Link Structure, Audits, Backlinks, Reports, AI Studio, Websites,
  Team, Settings, Billing). Each item carries a `feature` key; an item is hidden unless
  `$authUser->hasFeatureAccess($feature, $currentWebsiteId)` (`:129-131`) — **per-website
  feature gating drives nav visibility**, matching the route-level `feature:` middleware.
- **`$adminItems`** (`:69-120`) — rendered only for `$authUser->is_admin` (`:149`):
  Clients, Activities, Crawler, Marketing, Leads, API Usage, Proxies, Settings,
  WordPress Plugin, Plans, Keyword Servers, Commands, Crawler Docs. Active-state uses
  `match_routes` prefixes (`:157-165`) so one nav entry can light up across several
  routes (e.g. WordPress Plugin spans `admin.plugin-releases.` / `admin.plugin-adoption.`
  / `admin.website-features.` / `admin.billing.`).

Active highlighting compares `request()->route()->getName()` prefixes (`:27`, `:134`).
**Gotcha:** the product-nav active test is `str_starts_with($current, explode('.', route)[0])`
— a coarse first-segment match.

## Major view areas (`resources/views/`, 173 files)

| Area | Dirs | Layout | Notes |
|---|---|---|---|
| Authenticated app | `dashboard.blade.php`, `keywords/`, `rank-tracking/`, `pages/`, `sitemaps/`, `link-structure/`, `backlinks/`, `competitive/`, `reports/`, `settings/`, `ai-studio/`, `websites/`, `team/`, `billing/`, `issues/`, `statistics.blade.php` | `x-layouts.app` | thin Blade wrappers that embed Livewire components |
| Admin panel | `admin/{clients,activities,crawler,marketing,leads,plans,commands,keyword-servers,billing,docs,…}` | `x-layouts.app` | gated by `is_admin`; admin nav block in app layout |
| Onboarding | `onboarding/` | own | `<livewire:onboarding.connect-google>` |
| Guest tools (public) | `tools/{audit,page-speed,rank-tracker,keyword-volume}.blade.php`, `guest-audit/`, `guest-pagespeed/`, plus keyword-* and `guide-*` views | `x-marketing.page` | shareable report pages, Alpine-driven |
| Marketing | `landing`, `features`, `pricing`, `contact`, `guide`, `wordpress-plugin`, `website-revamp`, `legal/` | `x-marketing.page` | static `Route::view` pages |
| Emails | `emails/`, `mail/` | mailable layouts | growth reports, notifications |
| Livewire views | `resources/views/livewire/**` | (rendered into a parent) | one Blade per component |
| Reusable components | `components/{admin,audit,insights,marketing,layouts}` + `components/*.blade.php` (`sort-header`, `connect-source-prompt`, `keyword-language`, `guide-link`) | — | `<x-…>` components |
| Partials | `partials/` | — | banners (`quota-banner`, `connect-source-banner`), `favicon-links`, `google-analytics`, and the shared report partials (`page-speed-report`, `rank-check-report`, `keyword-volume-report`, `serp-vs-audited-snippet`) reused by both the portal and the public guest tools |

## Livewire patterns

See **[livewire-patterns.md](./livewire-patterns.md)** for the full component
inventory and conventions. The essentials:

- **All components are embedded, never full-page.** Routes → Blade view → `<livewire:…>`
  tags (e.g. `dashboard.blade.php:123-141`). No `#[Layout]` attributes, no
  `Route::get(Component::class)`.
- **Active website is session state.** `current_website_id` lives in the session; the
  `WebsiteSelector` (`app/Livewire/WebsiteSelector.php`, in the top bar) writes it on
  change and dispatches **`website-changed`** (`:44`). ~35 components read it via
  `(int) session('current_website_id', 0)` in `mount()` and re-fetch on the event:
  ```php
  #[On('website-changed')]
  public function switchWebsite(int $websiteId): void { $this->websiteId = $websiteId; }
  ```
  (`Dashboard/KpiCards.php:48-52`). Every read is authz-checked
  (`Auth::user()->canViewWebsiteId(...)`).
- **`#[Lazy]`** — the whole Dashboard board (KpiCards, TrafficChart, InsightCards,
  QuickWinsCard, SeasonalityCard, TopCountriesCard, PriorityActionQueue, SiteHealthStats,
  SyncAndReportPanel) defers its expensive GA/GSC queries and renders a skeleton
  `placeholder()` first (`Dashboard/KpiCards.php:14,19-41`).
- **`wire:poll`** — used for long-running/async work:
  - `admin/crawler-progress.blade.php:1` — `wire:poll.5s`, fleet crawl monitor at
    `/admin/crawler` (`Admin/CrawlerProgress.php`).
  - `crawl-banner.blade.php:1` — `wire:poll.10s` (crawl-in-progress banner).
  - **Conditional polling** — components poll only while work is pending, gating the
    directive on a computed flag so a finished job stops hitting the server:
    `@if($this->isPolling()) wire:poll.2000ms="poll" @endif`
    (`keywords/keyword-idea-finder.blade.php:1`, `keyword-volume-finder.blade.php:8`,
    `competitive/competitor-discovery.blade.php:1`, `keyword-gap-analysis.blade.php:17`,
    `pages/custom-audit.blade.php:5`, `pages/page-speed.blade.php:61`,
    `keywords/keyword-fix-playbook.blade.php:36`).
- **App-wide singletons** live once in the layout: `<livewire:connect-sources-modal>`
  (`app.blade.php:260`) opens from anywhere on the `open-connect-sources` event and only
  hits the Google list APIs when actually opened (`ConnectSourcesModal.php` docblock).
- **Cross-component events** beyond `website-changed`: `country-changed`
  (`Dashboard/CountryFilter` → tables), `open-connect-sources`.

## Multi-frontend — ebq.io vs marketing vs meet

This repo serves **only the EBQ app + its own marketing/guest pages** (`ebq.io` →
`/var/www/ebq/public`, PHP-FPM). The **separate** marketing site and **meet.ebq.io**
video/booking surface are a different codebase under **`/var/www/marketing`**, served by
**nginx `127.0.0.1:8000` + a Node service on `:3001`** (and Jitsi/Prosody for video) —
they do **not** use this repo's Vite build. Cross-ref:
[../server-deployment.md](../server-deployment.md) (lines 48-50) and the `meet-video-bookings`
project memory. Inside this repo, the "marketing" views (`x-marketing.page`) are the app's
own public landing/pricing/tool pages, distinct from `/var/www/marketing`.

## Asset build & view-cache in deploy (the opcache trap)

Frontend changes need **two** cache busts on the web box — Vite output and Blade/opcache:

1. **Assets:** `npm run build` → `public/build/`. (`public/build/` is **excluded from the
   worker-box rsync** — `deployment-and-queues.md:76`; only the web box serves assets.)
2. **PHP/opcache:** `sudo systemctl restart php8.3-fpm` — opcache runs
   `validate_timestamps=0`, so a graceful reload is **not enough**; a full restart rebuilds
   the SHM (master PID changes). See `CLAUDE.local.md` and
   [../deployment-and-queues.md](../deployment-and-queues.md) (lines 66-93).
3. **Compiled Blade views:** re-checked by mtime, but the safe combo for view changes is
   `php artisan view:clear` **+** the FPM restart (`deployment-and-queues.md:92`).

**Gotcha:** editing a `.blade.php` and seeing it work in `tinker` but **not** in the
browser/plugin = forgot the FPM restart (opcache serves stale bytecode for any PHP the
Blade compiles to). A cached `bootstrap/cache/config.php` from `artisan optimize` can also
silently override config — relevant to the DB-safety rule in the root `CLAUDE.md`.

## Key files

- Layouts — `resources/views/components/layouts/{app,guest}.blade.php`,
  `resources/views/components/marketing/page.blade.php`
- Website context — `app/Livewire/WebsiteSelector.php`, session key `current_website_id`,
  event `website-changed`
- Lazy board — `app/Livewire/Dashboard/*`
- Polling — `app/Livewire/Admin/CrawlerProgress.php`, `app/Livewire/CrawlBanner.php`,
  `app/Livewire/{Keywords,Competitive,Pages}/*`
- Assets — `vite.config.js`, `resources/css/app.css`, `resources/js/{app,bootstrap,marketing}.js`
- Build output — `public/build/` (manifest)
