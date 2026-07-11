# i18n & RTL (English / Arabic)

Complete as of 2026-07-07. Full English + Arabic (RTL) translation across the marketing
site, auth/onboarding, the entire customer dashboard, guest lead-gen tools, transactional
emails, and PDF exports. **Admin panel (`/admin/*`) is deliberately English-only** — internal
staff tool, excluded at the middleware level.

## Admin kill switch (default OFF)

Since 2026-07-09 the whole multilingual layer sits behind an admin toggle,
**Settings → Languages** (`admin/settings`), stored as setting
`locale.multilingual_enabled` and read via `app/Support/LocaleConfig.php`:

- `LocaleConfig::multilingualEnabled()` — the raw stored flag (defaults **false**:
  English-only). Only the admin settings form should read this directly.
- `LocaleConfig::active()` — the effective flag for the current request: the stored
  flag **force-overridden ON for a logged-in admin** (`is_admin`), so admins can
  preview the Arabic experience while it stays off for everyone else. Queue workers
  have no authenticated user, so mail is never affected by the override. Middleware,
  switcher blades, and `supported()` all go through this.
- `LocaleConfig::supported()` — `SetLocale::SUPPORTED` when active, `[config('app.locale')]` when not.
- `LocaleConfig::resolve(?string)` — clamps a stored locale to what's currently allowed;
  **every Mailable constructor must use this**, never the raw `users.locale` /
  guest-table `locale` column, or a disabled language leaks into queued mail.

When OFF (and not admin): `SetLocale` (`app/Http/Middleware/SetLocale.php:36`) forces
`config('app.locale')` and shares `showLocalePicker=false` (popup never renders);
the EN/AR switchers in `layouts/app.blade.php` and `marketing/page.blade.php` are
wrapped in `@if (LocaleConfig::active())`; `/locale/{locale}` 404s for
anything outside `LocaleConfig::supported()` (`LocaleController.php:18`). Stored
`users.locale` and `ebq_locale` cookies are left intact, so re-enabling restores
everyone's previous choice. Tests: `tests/Feature/LocaleTest.php` (setUp enables the
flag; four `test_multilingual_off_*` cases cover the off state + admin override).

Testing gotcha (cost an hour on 2026-07-09): `Application::setLocale()` **writes back
into `config('app.locale')`**, so after any in-process ar request,
`config('app.locale')` IS `'ar'` until something sets it back — in feature tests that
chain multiple requests, reset with a literal `app()->setLocale('en')`, never
`app()->setLocale(config('app.locale'))` (a no-op that keeps ar).

## How it works

- **Mechanism**: Laravel's JSON translation files, `lang/en.json` / `lang/ar.json` (+ 22
  other locale files that exist for the WordPress plugin's separate i18n and are
  incidentally touched by the same harvest script — out of scope for this rollout).
  `__('Exact English String')` — the literal English string IS the translation key, looked
  up at runtime, falling back to itself if untranslated. No parallel key-naming scheme.
- **Locale resolution**: `app/Http/Middleware/SetLocale.php` — `if ($request->is('admin*'))
  return $next($request);` first, otherwise: authenticated `user->locale` column →
  `ebq_locale` cookie (1yr) → `Accept-Language` sniff → `config('app.locale')` default.
  Also calls `Carbon::setLocale($locale)` — **Carbon's locale is a separate global from
  `app()->getLocale()`** and must be synced manually or every date/time render stays English
  regardless of locale.
- **Switching**: `app/Http/Controllers/LocaleController.php` + `GET /locale/{locale}` sets
  the cookie and, if authenticated, `users.locale`. First-visit popup:
  `resources/views/partials/locale-picker.blade.php`, included in all 3 customer root
  layouts (`layouts/app.blade.php`, `layouts/guest.blade.php`, `marketing/page.blade.php`).
- **RTL**: Tailwind v4 logical utilities — `ps-`/`pe-` (padding-inline), `ms-`/`me-`
  (margin-inline), `text-start`/`text-end`, `start-`/`end-` (inset), `border-s-`/`border-e-`
  — used everywhere instead of physical `pl-`/`pr-`/`ml-`/`mr-`/`text-left`/`text-right`/
  `left-`/`right-`/`border-l-`/`border-r-`. `dir="rtl"|"ltr"` set on `<html>` in each root
  layout (and on PDF/export templates, e.g. `pages/partials/audit-report-export.blade.php`,
  `pdf/site-audit.blade.php`) from `app()->getLocale()`.
- **Alpine/JS-context strings**: use `@js(__('...'))`, not plain `{{ __() }}` (which
  HTML-escapes and breaks JS string literals containing quotes). See
  `ai-studio/index.blade.php`, `ai-studio/wizard.blade.php`.
- **Mail locale** (queued Mailables have no HTTP request context — `app()->getLocale()` at
  render time is the worker's default, NOT the recipient's): every translated Mailable calls
  `$this->locale(LocaleConfig::resolve($user->locale))` in its constructor (Laravel's built-in
  per-mailable locale override, survives queue serialization). The 4 guest tools
  (`GuestPageAudit`/`GuestPageSpeed`/`GuestRankCheck`/`GuestKeywordVolume`) additionally
  needed a `locale` column (added `2026_07_07_000000_add_locale_to_guest_tables.php`),
  since the surrounding Job is ALSO fully worker-side — locale is captured at submission
  time in each model's `::start()` and read back in the Mailable.
- **Dynamically-built strings** (never a literal `__('...')` in source, so the harvest regex
  can't find them) need the OUTPUT wrapped in `__()` **and** the full known value set
  manually seeded into `lang/*.json`: `CrawlReportService::typeLabel()` (32 finding types),
  `BacklinkType::label()` (20 backlink types), pricing `$compareTable` group headers,
  `plans.features` DB column bullets.

## Pipeline for adding more translated surface

1. Wrap visible strings in `__()`, convert directional Tailwind classes to logical.
2. `python3 scripts/expand_laravel_locale_json.py` — harvests new `__()`/`@lang()`/`trans()`
   literal calls into all `lang/*.json` as identity placeholders. Not quote-aware — always
   use `__('text with an apostrophe\'')` (single-quote + `\'`), never double-quoted.
3. Machine-translate the new (`ar[key] == key`) placeholders via a scoped `deep-translator`
   (Google Translate) run — see `/root/.claude/projects/-var-www-ebq/memory/i18n-mt-pipeline-gotchas.md`
   for the full, actively-maintained list of MT failure modes (placeholder-token corruption,
   recurring mistranslations, acronym/brand-name reclobbering) and the fixes for each.
4. `npm run build` — Tailwind only ships classes it sees referenced at build time; a stale
   build silently no-ops any RTL class added since the last build.
5. `php artisan view:clear` + full `php8.3-fpm` **restart** (not reload — opcache
   `validate_timestamps=0`) + `horizon:terminate` + rsync-deploy to the worker box + restart
   its containers.

## Known remaining gaps

- Guest-facing controller JSON error messages (e.g. `GuestAuditController::store()`'s rate
  limit / validation messages) are plain English strings returned in JSON, not wrapped —
  out of scope so far (client-side Alpine consumes them, not server-rendered Blade).
- `TrialExpiryMail` content is hardcoded English HTML built via string concatenation in the
  Mailable class itself (not a Blade view) — not yet wrapped.

See also: [frontend/README.md](./README.md), the memory doc linked above for the MT
pipeline's full gotcha list (updated after every batch).
