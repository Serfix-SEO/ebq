# i18n & RTL (English / Arabic)

Complete as of 2026-07-07. Full English + Arabic (RTL) translation across the marketing
site, auth/onboarding, the entire customer dashboard, guest lead-gen tools, transactional
emails, and PDF exports. **Admin panel (`/admin/*`) is deliberately English-only** — internal
staff tool, excluded at the middleware level.

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
  `$this->locale($user->locale ?? app()->getLocale())` in its constructor (Laravel's built-in
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
