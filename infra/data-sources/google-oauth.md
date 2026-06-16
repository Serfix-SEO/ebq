# Google OAuth & token lifecycle

How a user connects Google, where tokens live, how they refresh, and the Cross-Account
Protection receiver. Three distinct Socialite flows share one `google_accounts` store.

## Key components

| Component | Role |
|---|---|
| `GoogleOAuthController::ssoRedirect/ssoCallback` (`app/Http/Controllers/GoogleOAuthController.php:17,46`) | **Login/register via Google** (`routes/auth.php`). Creates the `User` if new, accepts pending invites, logs them in, then persists the Google account. |
| `GoogleOAuthController::redirect/callback` (`:112,184`) | **Data-source connect** from onboarding or Settings → Integrations. Whitelisted `return` param routes the user back. |
| `GoogleOAuthController::redirectMailScope` (`:165`) | **Incremental consent** for `gmail.send` (report-mail transport), uses `include_granted_scopes=true`; callback keyed by `google_oauth.intent='mail_send'`. |
| `GoogleOAuthService::persistAccount` (`app/Services/Google/GoogleOAuthService.php:12`) | `updateOrCreate` on `(user_id, google_id)`; stores access token + `expires_at`, refresh token *only when returned*, email *only when the email scope was requested*. |
| `GoogleClientFactory::make` (`app/Services/Google/GoogleClientFactory.php:11`) | Builds a `Google\Client`, transparently refreshes an expired access token via the refresh token, persists the new token. The single choke-point all API calls go through. |
| `SearchConsoleService` / `GoogleAnalyticsService` (`app/Services/Google/`) | Thin API wrappers: list sites/properties/sitemaps + fetch search-analytics / daily-traffic rows. |
| `GoogleCapController` + `GoogleCapTokenVerifier` (`app/Http/Controllers/GoogleCapController.php`, `app/Services/Google/GoogleCapTokenVerifier.php`) | RISC/CAP webhook: Google notifies us of account events (disabled, credential change) → we kill sessions. |

## Scopes (and why each)

All three connect flows request, beyond `openid email profile`:

| Scope | Used by |
|---|---|
| `analytics.readonly` | GA4 Admin (property list) + Data API (traffic) — `GoogleAnalyticsService`. |
| `webmasters.readonly` | Search Console sites/sitemaps + Search Analytics + URL Inspection — `SearchConsoleService`, `SyncPageIndexingStatus`. |
| `indexing` | `indexing.googleapis.com/v3/urlNotifications:publish` — request a reindex of a page (`PageDetail.php:120`, `PluginHqController.php:932`). |
| `gmail.send` | Mail-scope flow only; outbound report email. |

**Why scopes are listed explicitly and `include_granted_scopes` is OFF on the connect/SSO
flows:** with that flag on, Google surfaces *every* previously-granted scope (e.g. `gmail.send`
from the mail panel) on the consent screen, cluttering it. The mail-scope flow is the one
deliberate exception — it turns the flag ON so the user keeps their Analytics/GSC grants while
adding `gmail.send` (`GoogleOAuthController.php:179`).

**Why `access_type=offline` + `prompt=consent`:** offline requests a **refresh token** so the
background sync jobs keep working without re-prompting; `prompt=consent` forces Google to
actually re-issue the refresh token (it omits it on repeat OAuth when scopes are unchanged).

## Multi-account model

A user can connect several Google logins (work + personal) — each is a `google_accounts` row,
labeled by its `email` (`GoogleAccount::label()`). Per website, two nullable FKs choose the
account *and* the property independently:

- GSC: `gsc_google_account_id` → `gscAccount` + `gsc_site_url`
- GA4: `ga_google_account_id` → `gaAccount` + `ga_property_id`

`Website::gscAccountResolved()` / `gaAccountResolved()` (`app/Models/Website.php:573,581`) return
the explicit per-source account, **falling back** to `user->googleAccounts()->latest()->first()`.
That fallback is a transitional backfill safety net (flagged for removal) — once dropped,
deleting an account degrades the site cleanly instead of silently borrowing another login.

## Token storage & refresh

`google_accounts`: `access_token` + `refresh_token` are **`encrypted` cast** (at-rest);
`expires_at` is a datetime. On every API call `GoogleClientFactory::make` seeds the SDK client
with the stored token and `expires_in` derived from `expires_at`. If
`$client->isAccessTokenExpired()`:
- **no refresh token** → throws a "please reconnect" `RuntimeException` (e.g. an old SSO-only row).
- refresh fails (`error` in response) → logs + throws "please reconnect".
- refresh succeeds → `$account->update([access_token, expires_at])` and the client is reseeded.

Sync jobs catch these throws per-website (logged warning, job continues), so one stale account
doesn't poison a whole nightly run.

## Connect flow (sequence)

1. User hits `google.redirect` (`?return=onboarding|settings.integrations`) →
   `Socialite::driver('google')` consent.
2. Google → `google.callback`. Socialite runs **`stateless()`** (proxies sometimes strip the
   session cookie on the round-trip; CSRF still covered by Google's state nonce).
3. `persistAccount()` upserts the `google_accounts` row.
4. Redirect back to onboarding or Settings; the UI then lets the user pick the GA property / GSC
   site, which writes the `*_google_account_id` + `gsc_site_url`/`ga_property_id` columns.
5. Setting those (in `ConnectGoogle` / `IntegrationsPanel`) dispatches the 365-day backfill syncs
   (guarded by `hasGsc()`/`hasGa()`).

## CAP / RISC (Cross-Account Protection)

`POST /auth/google/cap/events` (`google.cap.events`) is an **unauthenticated** Google webhook
(`throttle:oauth` only — no `auth`). `GoogleCapTokenVerifier::verify`:

1. Splits the JWT, requires `alg=RS256` + a `kid`.
2. Fetches Google's JWKS (cached 1h, `services.google.cap_jwks_url`), hand-builds the RSA PEM
   from the `n`/`e` JWK params (ASN.1/SPKI — no JWT lib dependency), `openssl_verify`s the signature.
3. Validates `iss` ∈ `cap_issuers`, `exp`/`iat` skew, and optional `aud` == `cap_audience`.

The controller then dedups on `jti` (`Cache::add` 1-day), extracts affected Google `sub`s from
the event, maps them to `google_accounts.google_id` → users, and **invalidates sessions**:
deletes `sessions` rows, rotates `remember_token`, logs `auth.google_cap_protect`, and logs out
the current request if it's an affected user. Config: `GOOGLE_CAP_AUDIENCE`, `GOOGLE_CAP_ISSUERS`,
`GOOGLE_CAP_JWKS_URL` (`config/services.php`).

## Gotchas

- **SSO login does NOT set email on a data-connect.** The connect/mail flows omit the email
  scope, so `persistAccount` only overwrites `email` when Google actually returns one — older
  rows lazily backfill email on the next email-bearing flow (`label()` falls back to `#id`).
- **`stateless()` everywhere** — Socialite's own state check is intentionally bypassed because
  of the proxy/cookie issue. Re-enabling it will break callbacks behind the proxy.
- A `google_accounts` row with no refresh token (e.g. SSO-only before consent was forced) will
  throw "please reconnect" the moment its access token expires (~1h).

## Key files

- `app/Http/Controllers/GoogleOAuthController.php`, `GoogleCapController.php`
- `app/Services/Google/{GoogleOAuthService,GoogleClientFactory,GoogleCapTokenVerifier,SearchConsoleService,GoogleAnalyticsService}.php`
- `app/Models/GoogleAccount.php`; Google helpers on `app/Models/Website.php`
- `routes/auth.php`, `routes/web.php`; `config/services.php` (`services.google.*`)
