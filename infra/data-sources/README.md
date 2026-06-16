# Data sources subsystem

EBQ's **external data ingestion** layer: the Google account connection (OAuth), the three
Google APIs we pull from — **Search Console** (queries/pages/positions), **GA4 Analytics**
(traffic), **URL Inspection / Indexing** (per-page index state) — and the Microsoft/Outlook
connection. Everything a website *measures* enters the app through here; the crawler
(`../crawler/`) is the only other ingest path and it's first-party fetching, not third-party APIs.

## Read in this order

| Doc | What it covers |
|---|---|
| [google-oauth.md](./google-oauth.md) | **Start here.** Socialite connect flows (SSO login vs. data-connect vs. mail-scope), scopes & why, multi-account `google_accounts`, token storage/refresh in `GoogleClientFactory`, the CAP/RISC cross-account-protection receiver. |
| [sync-jobs.md](./sync-jobs.md) | The three `sync`-queue jobs: cadence, date-window strategy, upsert keys, what each populates. The **GSC/GA degradation rule** (4 presence combos) and where it's gated. |
| [data-model.md](./data-model.md) | Tables + schema: `google_accounts`, `microsoft_accounts`, `search_console_data`, `analytics_data`, `page_indexing_statuses`, the source-account FKs on `websites`, and the index history (online ALTERs on the big fact table). |

## One paragraph

A user connects a **Google account** (`google_accounts`, one row per `(user_id, google_id)`,
tokens `encrypted`-cast). Per website, two nullable FKs pick *which* account + which property:
`gsc_google_account_id` + `gsc_site_url` for Search Console, `ga_google_account_id` +
`ga_property_id` for GA4. The nightly `ebq:sync-daily-data` command fans out
`SyncSearchConsoleData` + `SyncAnalyticsData` per website onto the **`sync` queue**; each job
no-ops unless its source is configured. GSC rows land in `search_console_data` (the large fact
table — query × page × country × device × date), GA4 in `analytics_data`, per-page index state
(separately, on demand) in `page_indexing_statuses`. Every feature must tolerate any of the
**4 source-presence combinations** (GSC y/n × GA y/n) — gated by `Website::hasGsc()` / `hasGa()`.

## Invariants (do not break)

1. **A job for a source that isn't connected must no-op, not error.** Every sync job guards on
   `gsc_site_url`/`ga_property_id` emptiness and on `gscAccountResolved()`/`gaAccountResolved()`
   returning null. The `created`/connect dispatchers guard on `hasGsc()`/`hasGa()` first.
2. **Frozen websites never burn Google quota.** `Website::isFrozen()` (plan-limit over-cap)
   short-circuits both sync jobs before any API call. See `sync-jobs.md`.
3. **Tokens are at-rest encrypted** via the `encrypted` cast on `GoogleAccount`/`MicrosoftAccount`
   — never store or log them in clear, never print them here.
4. **`google.cap.events` is unauthenticated by design** — it's a Google→EBQ webhook. Trust comes
   only from the RS256 JWT signature verified against Google's JWKS, plus `jti` replay-dedup.
   Do not add `auth` middleware; do not weaken `GoogleCapTokenVerifier`.
5. **Multi-account: prefer the explicit per-source FK**, fall back to the owner's latest account
   only as a transitional backfill safety net (`gscAccountResolved()`/`gaAccountResolved()`).

## Microsoft / Bing — mail only, no data ingestion

Microsoft is connected **solely for the "send reports from Outlook" mail transport** via
Microsoft Graph (`graph.microsoft.com/v1.0/me/sendMail`). There is **no Bing Webmaster Tools
ingestion** — Google Search Console is the only search-data source in the app. The Microsoft
pieces mirror the Google OAuth shape for symmetry: `MicrosoftOAuthController` (`redirect`/
`callback`), `Services/Microsoft/{MicrosoftOAuthService,MicrosoftClientFactory}`, and the
`microsoft_accounts` table (`app/Models/MicrosoftAccount.php`). Scopes: `offline_access`
(refresh token), `Mail.Send`, `User.Read`. Refresh is a raw `login.microsoftonline.com/common`
token POST (Microsoft *rotates* the refresh token on every use, so the new one is persisted).
Credentials live in `config/services.php` (`services.microsoft.*`,
`MICROSOFT_CLIENT_ID/SECRET/REDIRECT_URI/TENANT`).

## Key files

- OAuth — `app/Http/Controllers/{GoogleOAuthController,GoogleCapController,MicrosoftOAuthController}.php`,
  `app/Services/Google/{GoogleOAuthService,GoogleClientFactory,GoogleCapTokenVerifier}.php`,
  `app/Services/Microsoft/{MicrosoftOAuthService,MicrosoftClientFactory}.php`
- API clients — `app/Services/Google/{SearchConsoleService,GoogleAnalyticsService}.php`
- Sync jobs — `app/Jobs/{SyncSearchConsoleData,SyncAnalyticsData,SyncPageIndexingStatus}.php`,
  `app/Console/Commands/SyncDailyData.php`, `app/Actions/SyncWebsiteData.php`
- Models — `app/Models/{GoogleAccount,MicrosoftAccount,SearchConsoleData,AnalyticsData,PageIndexingStatus}.php`,
  Google helpers on `app/Models/Website.php`
- Routes — `routes/auth.php` (SSO), `routes/web.php` (connect/callback + `google.cap.events`)
- Config — `config/services.php` (`services.google.*`, `services.microsoft.*`)
