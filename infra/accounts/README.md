# Accounts, onboarding & teams

How a person becomes an authenticated EBQ user, picks/connects a website, and
shares it with teammates. Billing/plan *resolution* lives on `User` and is
summarised here, but the Stripe/checkout machinery is documented elsewhere.
Crawl hooks on `Website` are documented in
[infra/crawler/data-model.md](../crawler/data-model.md) — cross-ref, not repeated.

## Overview

Three entry points create a `User`: password registration, Google SSO, and
admin "create client". After auth, an **onboarding gate** forces any user with
no accessible website to `/onboarding`, where they connect a GA property / GSC
site (or **Skip**) and a `Website` row is created. Access to a website is either
**ownership** (`websites.user_id`) or **membership** (`website_user` pivot with
a role + optional per-feature permissions). The "current" website is just a
session value (`current_website_id`) chosen by `WebsiteSelector`.

## Key components

| Component | File:line | Role |
|---|---|---|
| Login | `app/Http/Controllers/Auth/AuthenticatedSessionController.php:20` | Authenticates, regenerates session, picks first accessible website + route, logs `auth.login`. |
| Login request | `app/Http/Requests/Auth/LoginRequest.php:41` | `Auth::attempt` + throttle (5/key); disabled-account block; recaptcha when enabled. Errors keyed to **`auth`** (banner, not field). |
| Registration | `app/Http/Controllers/Auth/RegisteredUserController.php:45` | Creates user, accepts pending invitations, fires `Registered`, optional pay-first redirect to Stripe checkout. |
| Google SSO | `app/Http/Controllers/GoogleOAuthController.php:17` | `ssoRedirect`/`ssoCallback`: login-or-create by email, auto-verifies email, persists `GoogleAccount`, routes to onboarding if no website. |
| Google connect (sync) | `app/Http/Controllers/GoogleOAuthController.php:112` | `redirect`/`callback`/`redirectMailScope`: add a data-source / Gmail-send account; returns to onboarding **or** settings via whitelisted `return`. |
| Onboarding | `app/Livewire/Onboarding/ConnectGoogle.php:12` | 2-step wizard: connect Google → pick GA/GSC → `saveWebsite()` / `skipForNow()`. |
| Connect-sources modal | `app/Livewire/ConnectSourcesModal.php:22` | App-wide "attach GA/GSC later" modal opened by the banner from any page. |
| Connect banner | `resources/views/partials/connect-source-banner.blade.php` | Owner-only nudge when current site lacks GA and/or GSC; dispatches `open-connect-sources`. |
| Source pool | `app/Support/GoogleSourcePool.php:24` | Pools GA properties + GSC sites across **all** of a user's `GoogleAccount`s; best-effort per account. |
| Website selector | `app/Livewire/WebsiteSelector.php:8` | Dropdown that writes `current_website_id` + dispatches `website-changed`. |
| Websites list | `app/Livewire/Websites/WebsitesList.php:12` | Add/remove websites; domain-only allowed; historical import + crawl bootstrap on create. |
| Team manager | `app/Livewire/Websites/WebsiteTeam.php:20` | Invite/edit/revoke members & invitations; role + per-feature permissions. |
| Permissions | `app/Support/TeamPermissions.php:5` | Role constants, the 12-feature catalog, `normalize()` / `allows()`. |
| Onboarding gate | `app/Http/Middleware/EnsureOnboarded.php:16` | Redirects website-less users to `/onboarding` (except onboarding/google/settings/billing/verification/logout routes). |
| Admin gate | `app/Http/Middleware/EnsureAdmin.php:14` | `is_admin` 403 guard (alias `admin`, see [infra/admin](../admin/README.md)). |

## Data model

```
users ──< websites (user_id = owner)                 ──< website_user (members)
  │           │                                              role, permissions(JSON)
  │           ├─ ga_property_id  + ga_google_account_id
  │           └─ gsc_site_url    + gsc_google_account_id
  ├──< google_accounts (access/refresh tokens, encrypted; email = label)
  └──< website_invitations (email, role, permissions, sha256 token, expires_at)
```

- **`users`** (`app/Models/User.php`): `is_admin`, `is_disabled`, Cashier billing
  columns + `current_plan_slug` snapshot, `timezone`. `email_verified_at` cast
  datetime; `password` hashed cast.
- **`website_user`** pivot: `role` (`owner`/`admin`/`member`) + `permissions`
  (JSON list of feature keys, **null = full access**). Owner isn't stored in the
  pivot — it's `websites.user_id`.
- **`google_accounts`** (`app/Models/GoogleAccount.php`): `access_token` /
  `refresh_token` are `encrypted` casts; `label()` = email or `#id`. A user can
  hold several, so GA and GSC can come from two different Google logins
  (picker values are encoded `"accountId|value"`).
- **`website_invitations`** (`app/Models/WebsiteInvitation.php`): token stored as
  `sha256(plain)`; `issue()` returns the plain token for the email link;
  `findValidByPlainToken()` + `acceptFor()` consume it.

## Flows

**Register (password) → onboarding**
`RegisteredUserController::store` creates the user → accepts any matching
invitations → `Registered` event (sends verification mail) → if a `pending_plan`
was captured from `/pricing`, redirect to Stripe checkout; otherwise
`verification.notice`. `EnsureOnboarded` then forces `/onboarding` until a
website exists.

**Google SSO** (`auth/google/sso`): login-or-create by lowercased email,
**force-verifies** `email_verified_at` (SSO email is trusted), persists the
`GoogleAccount`, then routes to `/onboarding` when `! hasAccessibleWebsites()`.

**Onboarding** (`ConnectGoogle`): step 1 connects Google (bounces through
`google.redirect`, stashing in-progress picks in session); step 2 lists pooled
GA/GSC options. `saveWebsite()` requires **≥1 source** and dispatches the 365-day
`SyncAnalyticsData` / `SyncSearchConsoleData` backfills + `CrawlSiteBootstrapper`.
`skipForNow()` requires only a domain (free tools + crawl still work). Both reuse
the pay-first **placeholder** Website (empty-domain row sorted first) when present,
and gate `create` on `canAddWebsite()`.

**Connect later** (`ConnectSourcesModal`): banner dispatches
`open-connect-sources` → modal lazy-loads the pool, saves GA/GSC onto the current
website, fires the backfills only for **newly** connected sources, reloads the page.

**Team invite** (`WebsiteTeam::inviteMember`): owner/admin only (Gate `update`).
Existing user → attached to `website_user` immediately + `WebsiteAccessGrantedMail`;
unknown email → `WebsiteInvitation::issue` + `WebsiteTeamInvitationMail`. Members get
a role (`admin` = full, `member` = checked permission keys). Permission checks run
through `User::hasFeatureAccess()` → `TeamPermissions::allows()`.

## Gotchas / known issues

- **Login errors are a banner, not a field.** `LoginRequest` keys failures to
  `auth` (recent change), so the login view renders one banner instead of a
  per-field error. Don't re-key to `email`.
- **Onboarding "Skip" still needs a domain.** `skipForNow()` validates `domain`
  so the skip yields a real, named site — not an empty placeholder.
- **Picker values are `"accountId|value"`** and must round-trip through
  `splitSelection()`; an empty string means "skip that source". A bare value with
  no `|` is treated as account-less (legacy).
- **Source-account ownership** is enforced in `ConnectSourcesModal::editableWebsite()`
  (`user_id === Auth::id()`), and the connect banner only shows to the **owner** —
  shared members can't reconfigure sources.
- **Permissions `null` = full access** in both the pivot and `TeamPermissions::allows`.
  `normalize()` collapses "all features selected" back to null to save space —
  don't treat null as "no access".
- **Two Google scopes flows.** SSO/connect deliberately **omit**
  `include_granted_scopes` (keeps the consent screen scoped to GA/GSC); only the
  Gmail-send incremental-consent flow sets it. `prompt=consent` + `access_type=offline`
  are required to actually receive a refresh token.
- **`stateless()` on the OAuth callback** is intentional — the session-state check
  breaks behind a proxy that strips the callback cookie; CSRF still comes from
  Google's state nonce.
- **Frozen websites.** `User::frozenWebsiteIds()` computes (live, no column) which
  owned sites exceed the plan's `max_websites`; the oldest stay active. Crawl/admin
  paths must check this (see admin recrawl gotcha).
- Onboarding/connect routes are throttled `throttle:oauth`; verification still
  happens via the standard `verified` middleware — checkout is **not** gated on it.

## Key files

- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `RegisteredUserController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Controllers/GoogleOAuthController.php`
- `app/Livewire/Onboarding/ConnectGoogle.php`, `app/Livewire/ConnectSourcesModal.php`
- `app/Livewire/Websites/WebsitesList.php`, `WebsiteTeam.php`, `app/Livewire/WebsiteSelector.php`
- `app/Support/GoogleSourcePool.php`, `app/Support/TeamPermissions.php`
- `app/Models/User.php`, `Website.php`, `GoogleAccount.php`, `WebsiteInvitation.php`
- `app/Policies/WebsitePolicy.php`
- `app/Http/Middleware/EnsureOnboarded.php`
- `resources/views/partials/connect-source-banner.blade.php`, `resources/views/onboarding/index.blade.php`
- Routes: `routes/auth.php` (login/register/sso), `routes/web.php:247` (onboarding/google connect)
