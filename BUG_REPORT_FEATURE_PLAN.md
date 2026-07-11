# Report-a-Bug feature (top nav + snipping-tool screenshot)

## Context

Users have no in-app way to report problems; feedback arrives ad-hoc. Product owner wants a **"Report a bug" button in the authenticated app's top nav bar** opening a small form — description, page link (prefilled), and an optional **snipping-tool-style screenshot**: user drag-selects a region of the page with the mouse, the capture is attached to the report. Reports go to admins (email + admin list page).

## Key technical decision — screenshot library

**`modern-screenshot`** (MIT, ~30KB, `domToCanvas`), NOT html2canvas: html2canvas 1.4.x has its own CSS parser and **crashes on Tailwind v4's `oklch()` colors** (this app is on Tailwind 4.1 — it would throw on nearly every element). modern-screenshot uses SVG `foreignObject`, letting the browser rasterize, so oklch/color-mix render natively. Loaded via **dynamic import** only when the user clicks "Capture area" (Vite code-splits; main bundle unchanged). Fallback if fidelity problems appear in dev: swap to `@zumer/snapdom` inside one file.

Known best-effort caveats (accepted for v1, not user-visible): cross-origin images may be blank, iframes not captured, 1–3s capture on heavy pages (spinner shown), sticky header captures at its document position.

## Implementation

### 1. DB + model (additive only — prod safety)
- Migration `create_bug_reports_table`: `ulid('id')->primary()`, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `foreignUlid('website_id')->nullable()` (soft ref), `text url`, `text description`, `string screenshot_path nullable`, `string user_agent(500) nullable`, `string viewport(50) nullable` ("1920x1080@2"), `string status(20) default 'new'`, timestamps, index `(status, created_at)`. Central connection — no sharding entanglement.
- `app/Models/BugReport.php`: `HasUlids`, `belongsTo(User)`, `belongsTo(Website)` nullable, `$fillable`.

### 2. Livewire modal — `app/Livewire/BugReportModal.php` + `resources/views/livewire/bug-report-modal.blade.php`
Clone the **ConnectSourcesModal** recipe (`app/Livewire/ConnectSourcesModal.php` + blade): Alpine `x-data="{ show:false }"`, opened by window event `open-bug-report` (detail carries `window.location.href`), fixed `inset-0 z-50` overlay + panel, Esc/backdrop close, no teleport.

- Props: `url`, `description`, `screenshotDataUrl`, `viewport`, `submitted`.
- `submit()`:
  1. `RateLimiter::attempt('bug-report:'.Auth::id(), 5, …, 3600)` — 5/user/hour (Livewire actions bypass route throttle, so limit in-method).
  2. Validate: description `required|max:5000`, url `max:2000`, screenshotDataUrl optional — regex `^data:image/(jpeg|png);base64,`, string cap ~6MB, decoded cap 4MB, `getimagesizefromstring()` mime sniff.
  3. Store `Storage::disk('local')->put('bug-reports/{ulid}.jpg', $binary)` (deliberately not WithFileUploads — the snip produces a data URL).
  4. Create row (`website_id` from `session('current_website_id')`, user_agent, viewport).
  5. Mail admins in try/catch (mail failure never breaks submit): `User::where('is_admin', true)->pluck('email')` (the SendFailedJobsAlert pattern) + synchronous `->send()` via local Postal (no queue — avoids new-model serialization on worker + it's a local relay).
  6. Success panel — neutral client-facing copy: "Thanks — we've received your report." (per client-facing-copy rule: no internal wording).
- Blade: textarea, editable URL input, screenshot block with 3 states (Capture area → capturing spinner → thumbnail + Retake/Remove), `wire:loading` on submit. All strings `__()`-wrapped, en+ar keys hand-written.

### 3. Snip overlay JS — new `resources/js/bug-report-capture.js`
- One added line in `resources/js/app.js`: `window.ebqSnip = () => import('./bug-report-capture.js').then(m => m.snip())` (keeps the heavy chunk lazy; inline Alpine can't dynamic-import through Vite's graph).
- `snip()`: plain-DOM fullscreen overlay (inline styles, no Tailwind classes — excluded from capture and needs no build), crosshair + drag marquee via `pointerdown/move/up` (mouse + touch), Esc cancels → resolves `null`.
- On release: remove overlay, wait 2×rAF, `domToCanvas(document.body, { scale: min(devicePixelRatio, 2) })`, crop to `(rect + scroll) × scale` on a second canvas, downscale to ≤1600px wide, `toDataURL('image/jpeg', 0.85)` → `$wire.set('screenshotDataUrl', …)` + viewport string.
- Modal flow: "Capture area" hides modal (`show=false`), awaits `window.ebqSnip()`, restores modal with preview.

### 4. Top bar button — modify `resources/views/components/layouts/app.blade.php`
- Icon button (bug Heroicon, `title="{{ __('Report a bug') }}"`) in the right cluster (line ~219), before the dark-mode toggle (copy its exact class recipe, lines 220–223); `@click` dispatches `open-bug-report` with `location.href`.
- Mount `<livewire:bug-report-modal />` next to the existing `@auth <livewire:connect-sources-modal />` (~line 289).

### 5. Admin surface (English-only, matches Leads pattern)
- `app/Http/Controllers/Admin/BugReportController.php` cloned from `LeadController`: `index()` (status filter, `with('user:id,name,email')`, `paginate(30)`), `screenshot(BugReport)` — private-file serve via `Storage::disk('local')->path()` + `response()->file()` (WordPressPluginDownloadController pattern, admin-gated by group), `resolve(BugReport)` POST toggling new/resolved.
- Routes inside the existing `['auth','admin']` prefix group (routes/web.php:269, near Leads at :317): `GET /bug-reports`, `GET /bug-reports/{bugReport}/screenshot`, `POST /bug-reports/{bugReport}/resolve`.
- View `resources/views/admin/bug-reports/index.blade.php` cloned from `admin/leads/index.blade.php`: filter pills (All/New/Resolved), table (date, user, URL link, truncated description w/ full text on expand, viewport/UA, screenshot thumbnail → full size), resolve button, pagination.
- Nav: add `['route' => 'admin.bug-reports.index', 'label' => 'Bug Reports']` to `$adminItems` in app.blade.php. No count badge (email is the notification channel).

### 6. Mail — `app/Mail/BugReportSubmitted.php`
TrialExpiryMail shape (`Content(htmlString:)`, hardcoded English — admin-facing): reporter, linked URL, description (`nl2br(e())`), viewport/UA, links to admin list + screenshot route. No attachment (link instead).

### 7. Tests — `tests/Feature/BugReportTest.php`
sqlite-safe, `Mail::fake()`, `Storage::fake('local')`, `Livewire::actingAs(...)->test(BugReportModal::class)`:
submit basic → row + mail to admins only; valid base64 JPEG → file stored + path set; validation failures (empty description, non-image base64, oversize); rate limit (6th in hour errors, `RateLimiter::clear` between); mail-throw still creates row; admin index auth (guest redirect / non-admin 403 / admin 200); screenshot route auth + missing-file 404; resolve toggle.

## Deploy
1. `npm i modern-screenshot` → `npm run build` (new blade classes + lazy chunk).
2. `php artisan migrate --force` (additive).
3. `view:clear` + **FPM restart** (opcache).
4. Worker rsync + container restart (cheap insurance so `BugReport` class exists there).
5. Confirm PHP `post_max_size`/Apache limits comfortably exceed ~8MB for the Livewire update payload (screenshot rides as base64).

## Verification (E2E on prod)
Minted-DB-session recipe (documented in memory — reCAPTCHA blocks browser login) + puppeteer:
1. Bug icon in top bar → modal opens, URL prefilled.
2. "Capture area" → overlay, drag over a chart → thumbnail preview; **oklch acceptance check**: orange buttons must not render black/blank.
3. Submit → success message; row via tinker; admin email arrives with working links.
4. `/admin/bug-reports` as admin: list, thumbnail, resolve toggle; screenshot URL as non-admin → blocked.
5. Network tab: modern-screenshot chunk loads only on first "Capture area" click.
6. Spot-check dark mode + Arabic/RTL (overlay math is viewport-geometric, should be direction-agnostic).

## Risks
- Capture fidelity is best-effort (cross-origin images blank, no iframes) — acceptable for bug context; lib swap is a one-file change if needed.
- Large-DOM capture latency (1–3s) — mitigated by spinner.
- Livewire payload size — mitigated by JPEG q0.85 + 1600px downscale + server-side caps.
