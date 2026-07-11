# Admin panel

Operator-facing back office at `/admin/*`. Plain controllers + Blade (plus two
Livewire panels), gated by `is_admin`. Covers client management, impersonation,
the marketing crawl-report send, leads, API-usage accounting, proxy management,
the keyword-API fleet, platform settings, the Artisan reference, and the activity
log. **Documented elsewhere** (listed in the index, not re-documented here):
Crawler panel → [infra/crawler](../crawler/README.md); Plugin releases/adoption +
feature toggles, Plans, Billing → their own areas.

## Auth / gating

| Mechanism | File:line |
|---|---|
| `admin` middleware (alias) | `app/Http/Middleware/EnsureAdmin.php:14` — `abort(403)` unless `user->is_admin`. |
| Route group | `routes/web.php:264` — `middleware(['auth','admin'])->prefix('admin')->name('admin.')`. |
| Livewire panels | Re-check `Auth::user()?->is_admin` in `mount()` **and every action** (the route guard doesn't cover Livewire message endpoints). |
| Impersonation stop | `routes/web.php:350` — `/admin/impersonation/stop` sits under plain `auth` (the impersonated session isn't admin). |

`is_admin` is a boolean column on `users` (set via the Clients editor). All
audit writes go through `ClientActivityLogger` (`app/Services/ClientActivityLogger.php`),
which prefers the real admin id from `session('impersonator_id')` as the **actor**
during impersonation so the client's id never pollutes the actor column.

## Panels

| Panel | Controller / Component | What it does |
|---|---|---|
| Clients | `app/Http/Controllers/Admin/ClientController.php:17` | Paginated user list w/ per-user website count, last activity, KE/SERP spend MTD (correlated sub-selects). Create/update, bulk enable/disable, **force-apply (comp) a plan**, admin recrawl. |
| Impersonation | `app/Http/Controllers/Admin/ClientImpersonationController.php:14` | `start`: stash `impersonator_id` + return URL, `Auth::login(client)`, regenerate session. `stop`: `loginUsingId` back, restore URL. |
| Marketing | `app/Http/Controllers/Admin/MarketingController.php:27` | Lists sites with a completed crawl + open findings; emails the owner a numbers + top-3-examples crawl summary; records every send in `crawl_report_sends`. |
| Leads | `app/Http/Controllers/Admin/LeadController.php:10` | Read-only list of marketing `leads` (converted/pending filter, links to guest page audits). |
| Usage | `app/Http/Controllers/Admin/UsageController.php:19` | Aggregates `client_activities` for `keywords_everywhere` / `serp_api` / `mistral` over a window; per-client + per-website spend, recent feed. Rates from `config/services.php`. |
| Proxies | `app/Livewire/Admin/ProxyManager.php:18` | Crawler anti-block proxy pool: bulk add (any format), toggle/delete, live exit-IP test via ipify. **"Retest all"** (client-driven Alpine loop, concurrency 5) sweeps every row and **deletes on the spot** any that fail (`test($id, deleteOnFail: true)`) — the single-row "Test" button never deletes. **"Import now"** dispatches `RunProxyListRefreshJob` (free-list import, queued) — the equivalent scheduled import is OFF by default (`CRAWLER_PROXY_AUTO_IMPORT`); a separate always-on `ebq:proxy-pool-prune` (every 15min) keeps the pool clean regardless of that flag. See `infra/crawler/known-issues.md`. |
| Keyword API servers | `app/Http/Controllers/Admin/KeywordApiServerController.php:25` | CRUD for the self-hosted keyword fleet; encrypted secrets; connectivity + sample-lookup test probes. |
| Platform settings | `app/Http/Controllers/Admin/PlatformSettingsController.php:27` | One page: default AI model, rank-tracker re-check interval, keyword volume provider, KE competitor toggle, plugin banner. |
| Artisan commands | `app/Http/Controllers/Admin/ArtisanCommandsController.php:21` | Read-only reference: curated `CATALOG` + live signatures from `Artisan::all()` (can't drift from `php artisan list`). |
| Activities | `app/Http/Controllers/Admin/ActivityController.php:11` | Filterable audit feed over `client_activities` (user/type/provider). |
| Crawler (xref) | `app/Http/Controllers/Admin/CrawlerController.php` + `app/Livewire/Admin/CrawlerProgress.php:18` | Fleet-wide crawl progress + queue depth. See [infra/crawler](../crawler/README.md). |
| Ops | `app/Http/Controllers/Admin/OpsController.php` | Queue/worker health (added 2026-07-06 after the silent worker-failure incident): failed jobs last-7d grouped by job+exception with Retry-all/Forget (`queue:retry`/`queue:forget`), crawl_sites with subscribers stuck `pending` >24h with a Start-crawl button (frozen sites labeled), live queue depths + undelivered alert-buffer count. Companion to the `ebq:failed-jobs-alert` mailed digest (every 15 min — `FailedJobAlertBuffer`, fed by `Queue::failing()` on every box). Tests: `AdminOpsDashboardTest`, `FailedJobsAlertTest`. |

**Documented elsewhere (index only):** `PluginReleaseController`,
`PluginAdoptionController`, `WebsiteFeatureController` (plugin area);
`PlanController` (plans); `BillingController` (billing).

## Data model touchpoints

- **`client_activities`** — the audit + usage spine. Columns the panels read:
  `type`, `user_id` (**billed** account), `actor_user_id` (who triggered),
  `website_id`, `provider`, `units_consumed`, `meta` (JSON), `created_at`.
  Written **only** through `ClientActivityLogger::log()`.
- **`users`** — `is_admin`, `is_disabled`, `current_plan_slug` (comp target).
- **`crawl_report_sends`** — one row per marketing send: recipient, sender,
  `to_email`, `subject`, `summary` (JSON snapshot), `status` (`sent`/`failed`).
- **`leads`** — marketing leads; `Lead::markConvertedFor()` tags on signup.
- **`proxies`** — `url`, `url_hash` (dedup), `active`, `fail_count`, `last_ok_at`.
- **`keyword_api_servers` / `keyword_api_requests`** — fleet + per-request log.
- **`settings`** — KV store (`Setting::get/set`) for platform settings + banner.

## Flows

**Force-apply a plan (comp).** `ClientController::update` writes the chosen slug
to `users.current_plan_slug` (null for Free) — the same column the Stripe webhook
writes and `User::effectivePlan()` reads — so the client gets paid entitlements
with **no charge**. Logged distinctly as `admin.client_plan_forced`.

**Admin recrawl.** `ClientController::crawl` picks the website (picker if >1),
**refuses frozen sites** (over plan limit — `CrawlWebsitePagesJob` would no-op
silently), force-releases the `ShouldBeUnique` lock (both key spellings), then
dispatches `CrawlWebsitePagesJob(..., TRIGGER_MANUAL, force=true)`.

**Impersonation.** `start` stores `impersonator_id` + `impersonator_return_url`
and logs in as the client (blocked for disabled users); the app then behaves as
that user, but `ClientActivityLogger` keeps logging the admin as actor. `stop`
(plain-`auth` route) restores the admin and returns to the saved URL. The
"Return to admin" banner lives in the app layout
(`resources/views/components/layouts/app.blade.php:267`) **and** on the
onboarding page (`resources/views/onboarding/index.blade.php`) — onboarding uses
the guest layout, so impersonating a not-yet-onboarded client would otherwise
strand the admin with no exit. Both banners also state when the impersonated
client is trial-locked (`TrialStatus::isLockedOut`) — impersonation bypasses the
billing lockout, so without the note the admin sees pages (e.g. onboarding) the
client themselves never would. Onboarding additionally swaps the add-site wizard
for a trial-expired panel for expired users (see
`infra/billing/plans-and-gating.md`). Stop with a STALE CSRF token (old
tab / back-nav after logout rotated the token) no longer dead-ends on the
419 page: a render hook in `bootstrap/app.php` redirects to dashboard (or
login) with an `impersonation_notice` flash — hook the 419 `HttpException`,
NOT `TokenMismatchException`, because `Handler::prepareException()` converts
it before render callbacks run. Verified live via a minted DB-session +
curl (the session recipe that works: seed `login_web_…`, `_token`, AND
`password_hash_web` into a `sessions` row, cookie =
`Crypt::encryptString(CookieValuePrefix + id)`).

**Bug reports (2026-07-10).** In-app "Report a bug" (portal top bar → `BugReportModal`
Livewire) lands rows in `bug_reports` (central, ULID; optional screenshot on the private
local disk under `bug-reports/`). Admin surface `/admin/bug-reports`
(`Admin\BugReportController`): status filter, screenshot served admin-only via
`response()->file`, resolve toggle. Every `is_admin` user is mailed synchronously on
submit (`BugReportSubmitted`, local Postal). Resolving REQUIRES a
customer-facing `resolution_note` (emailed verbatim to the reporter via
`BugReportResolved`, recipient-locale aware); reopening keeps the note,
sends nothing. Screenshot capture is client-side
(`resources/js/bug-report-capture.js`, lazy chunk + `modern-screenshot`); Alpine/Livewire
attributes must be stripped from the clone (invalid XML names → transparent captures).

**Marketing send.** `MarketingController::send` builds a crawl summary
(`CrawlReportService::summary` + `reportBreakdown`) plus optional 28-day GSC/GA
traffic (degrades to null on any error), **queues** `CrawlReportMail`, and writes
a `crawl_report_sends` row (`status=failed` if the queue call throws).

## Gotchas / known issues

- **Self-lockout guards.** `ClientController::bulk` filters the current admin's
  own id server-side (the checkbox is only hidden client-side). The Clients editor
  also surfaces `is_admin`/`is_disabled` — don't disable yourself.
- **Livewire panels must re-auth per action.** `ProxyManager` calls
  `abort_unless(is_admin)` in *every* method, not just `mount()`. The route guard
  does **not** protect Livewire's message endpoint — follow this pattern for any
  new admin Livewire component.
- **Proxy cache.** Every proxy mutation `Cache::forget('crawler:proxypool:urls')`
  so the worker box re-reads the DB; the live test hits ipify with a 15s timeout
  and bumps `fail_count` on error.
- **Usage attribution = billed owner, not actor.** Spend rolls up by `user_id`
  (forced to the website owner when `website_id` is set), so "top spenders" reflect
  who pays, not who clicked. Rates live in `config/services.php` and are tunable
  without redeploy.
- **Artisan reference is curated.** Signatures come live from the framework, but
  category/schedule/destructive/notes are hand-maintained in `CATALOG` — add a row
  when a new `ebq:*` command lands or it won't appear with notes.
- **Keyword-server secrets** (`api_key`, `webhook_secret`) are encrypted at the
  model layer and left untouched on edit unless the admin types a replacement.
- **Comp plan is silent to Stripe.** A forced plan never touches the subscription,
  so a later real subscription/webhook can overwrite `current_plan_slug`.

## Key files

- `app/Http/Controllers/Admin/{Client,ClientImpersonation,Marketing,Lead,Usage,KeywordApiServer,PlatformSettings,ArtisanCommands,Activity,Crawler}Controller.php`
- `app/Livewire/Admin/ProxyManager.php`, `app/Livewire/Admin/CrawlerProgress.php`
- `app/Http/Middleware/EnsureAdmin.php`
- `app/Services/ClientActivityLogger.php`
- `app/Models/{ClientActivity,Lead,Proxy,CrawlReportSend,KeywordApiServer,Setting}.php`
- Routes: `routes/web.php:264` (admin group), `:350` (impersonation stop)
