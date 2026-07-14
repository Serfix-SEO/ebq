# HTTP / auth layer — middleware, guards, authorization, lifecycle

> Cross-cutting reference for how requests are authenticated and authorized. For
> the endpoint inventory see [routing.md](./routing.md).

## Kernel config — `bootstrap/app.php`

Laravel 11 slim bootstrap (no `Kernel.php`). Everything is configured in
`bootstrap/app.php`:

- **Routing** (`:9`) — wires `web` / `api` / `channels` / `console`, `health: '/up'`,
  and adds `auth.php` under the `web` group via the `then:` closure (`:16`).
- **CSRF exemptions** (`:25`) — `auth/google/cap/events`, `stripe/webhook`,
  `webhooks/keyword-finder`. WHY: all three are server-to-server (no browser cookie),
  each verifies its own signature/HMAC.
- **Middleware aliases** (`:33-40`):

  | Alias | Class | Role |
  |---|---|---|
  | `onboarded` | `EnsureOnboarded` | force first-website onboarding |
  | `feature` | `EnsureFeatureAccess` | per-team-member feature gate |
  | `website.api` | `WebsiteApiAuth` | Sanctum per-Website bearer auth |
  | `website.features` | `InjectFeatureFlags` | stamp flag map onto JSON responses |
  | `admin` | `EnsureAdmin` | `is_admin` 403 gate |

  (The dangling `research.rollout` alias was removed in the 2026-07-06 sweep.)

- **Exceptions** (`:42`):
  - Sentry forwarding (no-op when `SENTRY_LARAVEL_DSN` empty).
  - `QuotaExceededException` render (`:53`): **402 JSON** for `expectsJson()` or
    `api/*` callers (WP plugin shows a banner); else flash `quota_notice` + redirect
    back for browser flows. See [billing/usage.md](../billing/usage.md).

The standard `web` group (session, cookies, CSRF, `VerifyCsrfToken`) and `api`
behaviour are Laravel defaults, plus four appended `web` middlewares
(`bootstrap/app.php:44`, order matters):

  1. `SetLocale` — i18n (en/ar); self-excludes `admin*` paths.
  2. `ApplyWebsiteHint` — `?ebq_site=<domain>` switches the session's
     `current_website_id`, but **only among the user's accessible websites**
     (`accessibleWebsitesQuery()`, domain matched via
     `CrawlSite::normalizeDomain` against `websites.normalized_domain`).
     Unsigned by design — it can't grant anything the user lacks. Consumed by
     the WP plugin's raw portal links (editor sidebar rank-tracking /
     custom-audit / page-audits / settings, dashboard-widget fallback hrefs)
     so multi-website accounts land on the right site. Silent no-op for
     guests; login preserves the intended URL so the hint applies post-auth.
     Must precede `ResolveShardContext` (reads the session on the same
     request). Tests: `tests/Feature/ApplyWebsiteHintTest.php`.
  3. `ResolveShardContext` — shard routing off session `current_website_id`.
  4. `EnsureTrialNotExpired` — expired-trial lockout to the billing surface.

---

## Auth guards

Two independent auth mechanisms; **no shared session between them**.

### Web session guard (`config/auth.php`)
- Default guard `web` (`AUTH_GUARD`), session driver, `User` provider.
- Used by everything in `web.php` + `auth.php`. `is_admin` is a boolean column on
  `users` (`User.php:71,105`), the only role flag for the platform-admin area.

### Plugin API — Sanctum per-**Website** token  <a id="plugin-api-sanctum"></a>
`WebsiteApiAuth` (`app/Http/Middleware/WebsiteApiAuth.php`) — **not** the stock
`auth:sanctum`. Key difference: the tokenable is a `Website`, not a `User`.

Flow (`handle()`):
1. `$request->bearerToken()` → 401 `missing_token` if absent.
2. `PersonalAccessToken::findToken()` → 401 `invalid_token`; expired → 401
   `expired_token`.
3. Tokenable must be a `Website` → else 403 `invalid_tokenable`. **This is the
   tenancy boundary** — the website the token belongs to is the only one the request
   can touch; no `website_id` param is ever trusted.
4. Optional ability check: `->middleware('website.api:read:insights')` passes the
   ability arg; `$accessToken->can($ability)` → 403 `insufficient_ability`.
5. Stamps `last_used_at`, sets request attributes `api_website` + `api_token`, logs a
   `plugin.api_request` activity, then `$next`.

Token issuance: `/wordpress/connect` POST → `$website->createToken($name,
['read:insights'])->plainTextToken` (`WordPressConnectController.php:77`). The plugin
stores the plaintext and sends it as a bearer on every call. See
[wordpress-plugin/hq-api.md](../wordpress-plugin/hq-api.md).

---

## Authorization

### Platform admin — `is_admin`
`EnsureAdmin` (`EnsureAdmin.php:14`): `abort(403)` unless `$user && $user->is_admin`.
Guards the whole `/admin/*` group. `ClientImpersonationController::start` re-checks
`is_admin` itself (defence in depth) before `Auth::login($impersonated)` — the
impersonator id is stashed in the session so `admin.impersonation.stop` (outside the
admin group) can restore it via `Auth::loginUsingId`.

### Website ownership — `WebsitePolicy`
`app/Policies/WebsitePolicy.php`, registered in `AppServiceProvider.php:59`
(`Gate::policy(Website::class, ...)`):

| Ability | Rule |
|---|---|
| `view` | owner (`website.user_id`) **or** a member (`website.members()`) |
| `update` / `delete` | owner only |

So **members can view but not mutate** a website. Owner = `user_id`; members come from
the `website_user` pivot.

### Per-feature team gating — `EnsureFeatureAccess` + `TeamPermissions`  <a id="feature-gating"></a>
Every authed app route carries `feature:<key>` (e.g. `feature:keywords`). The key is
one of `TeamPermissions::FEATURES` (`app/Support/TeamPermissions.php:18`): dashboard,
keywords, rank_tracking, pages, sitemaps, link_structure, audits, backlinks, reports,
ai_studio, settings, team. Each entry carries a `route` used for redirect fallback.

`EnsureFeatureAccess::handle()` (`EnsureFeatureAccess.php:15`):
1. No user → redirect `login`.
2. Unknown feature key (not in `FEATURES`) → pass through (fail-open for non-gated).
3. Resolves the active website from `session('current_website_id')`; if it's not
   accessible, falls back to the user's first accessible website by domain and
   re-stores it in the session. **This is the "current website" selector for the
   whole app** — driven by session, not the URL.
4. `$user->hasFeatureAccess($feature, $websiteId)` → pass.
5. Else redirect to `firstAccessibleRoute($websiteId)`. If the user is **already** on
   that target route → `abort(403)` (prevents a redirect loop).

`TeamPermissions::allows()` (`:135`): owner/admin role → always true; a member with
`permissions === null` → full access; otherwise the feature must be in the member's
permission list. Roles: owner / admin / member; `normalize()` collapses "all
features" to `null` to save space. This is **team/role** gating — distinct from
**plan-tier** gating, which lives in the billing layer. See
[accounts/README.md](../accounts/README.md) and
[billing/plans-and-gating.md](../billing/plans-and-gating.md).

### Onboarding gate — `EnsureOnboarded`
`EnsureOnboarded.php:16`: if the user has **no accessible websites** and isn't already
on an allow-listed route (`onboarding*`, `google.*`, `settings*`, `billing.*`,
`cashier.*`, `verification.*`, `logout`) → redirect to `onboarding`. Applied to the
main app group only (not the OAuth/onboarding group, which you must reach pre-site).

---

## Feature-flag injection (plugin) — `InjectFeatureFlags`

`website.features`, runs **after** the controller (it calls `$next` first). For JSON
responses that carry an `api_website` (i.e. `WebsiteApiAuth` ran), it spreads onto the
body — without clobbering keys the controller already set:
`features` (`effectiveFeatureFlags()`), `frozen` + `frozen_reason`
(`isFrozen()` / `plan_limit_exceeded`), `tier` (`effectiveTier()`), `free_promo`
(`config('app.free')`). WHY: the WP plugin passively syncs flags from *any* response
(`EBQ_Feature_Flags::handle_response`), so admin toggles propagate on the next API
round-trip with no extra fetch. Detail in
[InjectFeatureFlags.php:11-36](../../app/Http/Middleware/InjectFeatureFlags.php).

---

## CSRF / throttle / reCAPTCHA

### CSRF
Default `web`-group protection. Exempt: the 3 webhooks (`bootstrap/app.php:25`). Guest
tool POSTs keep CSRF (the Blade forms ship a token). The plugin API has no `web` group
so CSRF never applies there.

**Stale-token 419 recovery** (`bootstrap/app.php`, exceptions handler) — a bare 419
error page is a dead end for the ONE action a user reaches for specifically because
they're leaving (`logout`) or already mid-recovery (`admin.impersonation.stop`), both
easily hit after a tab sits open past `SESSION_LIFETIME` (120min default) or the token
rotates in another tab. A dedicated `render()` callback for the 419 `HttpException`
(must hook the HTTP exception, not `TokenMismatchException` — `Handler::prepareException()`
converts it before render callbacks run) redirects those two routes to a fresh page
carrying a valid token instead — CSRF verification still runs normally, this only
turns "dead end" into "click again and it works." Flash key `session_notice` (logout)
/ `impersonation_notice` (impersonation-stop), rendered in `layouts/app.blade.php`
and, for `session_notice` only, `auth/login.blade.php` too (a stale-session logout
can land on either depending on whether `auth()->check()` still holds).

### Throttle / rate limiters
- Named limiter **`oauth`** — `AppServiceProvider.php:77`: 20/min keyed by user id
  (fallback IP). Applied to all OAuth + SSO + CAP routes.
- Inline named throttles on hot routes: `throttle:60,1` (plugin API),
  `throttle:30,1` (AI Studio run, page-audit download), `throttle:20,1` /
  `throttle:10,1` (prompt store / brand-voice), `throttle:6,1` (email verification).
- **Guest tools** don't use route middleware — they call `RateLimiter::tooManyAttempts`
  directly with a per-IP minute + day key (e.g. `GuestAuditController` 5/min, 20/day,
  `:28-30`). <a id="guest-tools-friction"></a>
- **Progressive friction** is enforced in the guest controllers via a signed
  `ebq_guest_*` cookie counter, not middleware: #1 free, #2 requires email, #3+ →
  signup (`GuestAuditController.php:33-40`).

### reCAPTCHA  <a id="login-throttle"></a>
`App\Support\Recaptcha::isEnabled()` gates it on config presence
(`services.recaptcha.site_key` + `secret_key`). When enabled, `ValidRecaptcha`
(`app/Rules/ValidRecaptcha.php`) validates `g-recaptcha-response`. Used by all four
guest tools + login + register (`LoginRequest.php:31`, `RegisteredUserController`).
`LoginRequest` also runs a per-key login throttle (`ensureIsNotRateLimited` /
`RateLimiter::hit($this->throttleKey())`, cleared on success).

---

## Request lifecycle by surface

| Surface | Group / middleware chain | Auth result |
|---|---|---|
| **Marketing/public** | `web` only | anonymous; CSRF on POST |
| **Guest tools** | `web` only | anonymous; throttle + reCAPTCHA + cookie friction *in controller* |
| **App page** | `web` → `auth` → `verified` → `onboarded` → `feature:<key>` | session user, verified email, has a website, role+permission grants the feature; current website from session |
| **Admin** | `web` → `auth` → `admin` | session user with `is_admin` |
| **Plugin API** | `website.api:read:insights` → `website.features` → `throttle:60,1` (no `web`) | Sanctum Website token w/ ability; no session/CSRF; flag map injected on the way out |
| **Webhook** | route-level only, CSRF-exempt | signature/HMAC verified in controller |
| **OAuth** | `web` → `auth`(+`verified`) → `throttle:oauth` (SSO is `guest`) | session user (or guest for SSO) |

---

## Gotchas

- **Dangling alias**: `research.rollout` → `App\Http\Middleware\EnsureResearchRolloutAccess`
  is registered (`bootstrap/app.php:39`) but the class **does not exist** and no route
  references it. Harmless today (Laravel only resolves an alias when used), but any
  route adding `research.rollout` would 500. Remove the alias or add the class.
- **`feature:` fail-open**: an unknown feature key passes through silently
  (`EnsureFeatureAccess.php:22`) — typos in a `feature:` middleware arg won't gate.
- **Members can view but not edit** a website (`WebsitePolicy`): `update`/`delete` are
  owner-only; `feature:team`/`settings` further restrict who sees the management UIs.
- **Current-website is session-state**, not in the URL — `EnsureFeatureAccess`
  silently repairs/repoints `current_website_id`, so the same path renders different
  data depending on the session selector.
- **Cached config wiped prod once** — see the DATABASE SAFETY note in `CLAUDE.md`;
  unrelated to routing but bites anyone running the suite.
- **Plugin API tenancy is the tokenable**, never a request param; controllers read
  `request->attributes('api_website')`. Don't add a `website_id` query param path.
