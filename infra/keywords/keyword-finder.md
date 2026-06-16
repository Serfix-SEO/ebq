# Keyword Finder — self-hosted fleet

The `keyword_finder` provider: a pool of **self-hosted keyword-data API servers**, each
fronting a logged-in Google Ads / Keyword Planner browser session. It returns volume,
competition, top-of-page bid range, and (uniquely) **related-keyword discovery**. It is
**asynchronous**: a dispatch ACKs instantly, then the server POSTs results back to our
webhook later. Opt-in via the `keyword.volume_provider` Setting.

## Overview

```
caller (Volume Finder / Idea Finder / Gap / KeywordMetricsService.refresh)
  │  KeywordFinderPool::dispatchIdeas() | dispatchVolume()
  ▼
KeywordApiRequest  (status=queued, request_id=uuid)
  │  walk routable() servers least-busy-first → POST /keywords/{ideas,volume}
  │     ok        → status=running, dispatched_at set, RETURN
  │     transient → try next server (5xx / 429 / conn refused)
  │     permanent → markFailed + flag server unhealthy (400/401/409), STOP
  ▼  ... server processes async ...
POST /webhooks/keyword-finder  (request_id + HMAC-SHA256 of raw body)
  │  KeywordFinderWebhookController
  │   verify signature vs originating server's webhook_secret
  │   idempotent: finished request → no-op
  │   failure (status/error/needsLogin) → markFailed (+ flag unhealthy if needsLogin)
  │   success → markCompleted(result) + ingestFinderResults() warms keyword_metrics
  ▼
KeywordMetric rows (data_source='gkp', 30-day fresh) — UI polling picks them up
```

## Key components

| Component | Role | File |
|---|---|---|
| `KeywordFinderPool` | Load-balancer + failover; creates the request row, walks servers, classifies outcomes | `app/Services/KeywordFinder/KeywordFinderPool.php` |
| `KeywordFinderClient` | Thin per-server HTTP wrapper; never throws; structured `{ok,status,transient,...}` outcome | `app/Services/KeywordFinder/KeywordFinderClient.php` |
| `KeywordApiServer` | One server row; encrypted `api_key`/`webhook_secret`; health columns; `routable()` scope | `app/Models/KeywordApiServer.php` |
| `KeywordApiRequest` | Lifecycle + result record per async call; `request_id` is route key | `app/Models/KeywordApiRequest.php` |
| `KeywordFinderWebhookController` | Receives async results, verifies HMAC, caches volumes | `app/Http/Controllers/Webhooks/KeywordFinderWebhookController.php` |
| `KeywordApiServerController` | Admin CRUD + live "Test" probes (`/admin/keyword-servers`) | `app/Http/Controllers/Admin/KeywordApiServerController.php` |
| `CheckKeywordServers` | `ebq:check-keyword-servers`, polls `/health` `/status` `/queue` every 5 min | `app/Console/Commands/CheckKeywordServers.php` |
| `KeywordFinderLocations` | KE-code ⇄ Google-Ads location name, language list, cache-key/Serper-gl bridging | `app/Support/KeywordFinderLocations.php` |

## Data model

- **`keyword_api_servers`** — `name, base_url, api_key*, webhook_secret*, default_location,
  default_language, weight, is_active`, plus cached health: `is_healthy, logged_in,
  last_queue_waiting, last_queue_running, last_health_at, last_error`. (`*` = `encrypted` cast.)
- **`keyword_api_requests`** — `request_id (uuid), keyword_api_server_id, type
  (ideas|volume), mode (keywords|website|page), payload, status
  (queued|running|completed|failed), result, error, user_id, website_id, dispatched_at,
  completed_at`. `payload` carries an internal `country_key` that is **stripped from the
  outgoing body** (`KeywordFinderPool.php:161`) and used only by the webhook to know which
  country to cache under.
- **`keyword_metrics`** — shared cache (see [keyword-research.md](./keyword-research.md));
  the finder writes `search_volume, competition (index/100), low/high_top_of_page_bid` and a
  representative `cpc=highBid` under `data_source='gkp'` (`KeywordMetricsService.php:261`).

## Server reference API (each server)

`GET /health` (no key — liveness) · `GET /status` (`{loggedIn, reason?}`) ·
`GET /queue` (`{waiting, running}`) · `POST /keywords/ideas` (discovery: `seeds` OR
`url`+`scope`) · `POST /keywords/volume` (known keywords). Auth via `x-api-key`
(`KeywordFinderClient.php:19`).

## Routing & failover (why)

- `KeywordApiServer::routable()` = active AND not known-unhealthy (`is_healthy` null counts
  as a candidate — optimistically tried), ordered **least-busy first**
  (`COALESCE(last_queue_waiting,0)`), then highest `weight`, then id (`KeywordApiServer.php:81`).
- Outcome classification (`KeywordFinderClient.php:164`): **5xx / 429 / connection error =
  transient** → advance to next server; **4xx (esp. 400/401/409) = permanent** → stop the
  cascade with the same bad body. `401`→unhealthy (bad key); `409`→unhealthy + `logged_in=false`
  (browser session needs re-login) (`KeywordFinderPool.php:211`).
- A successful **webhook callback proves liveness** and clears stale failure state on the
  server row (`KeywordFinderWebhookController.php:104`); `needsLogin:true` in a webhook flags
  the server unhealthy until the next health check (`:70`).

## Async webhook (why HMAC + idempotent)

- Signed with **HMAC-SHA256 of the raw body**, keyed on the *originating server's*
  `webhook_secret` (looked up via the request's server), accepts optional `sha256=` prefix,
  constant-time compare (`KeywordFinderWebhookController.php:144`). CSRF-exempt (server-to-server;
  `bootstrap/app.php`).
- **Idempotent**: a redelivery for a finished request returns `{ok,duplicate}` (`:55`).
- On success it caches **every returned keyword** (`result.results[]`), not just the asked-for
  ones — a single "seo audit" discovery can warm thousands of related volumes for free future
  lookups (`:90`).

## Discovery semantics (why ideas, not bare volume)

`KeywordMetricsService::refresh()` and the gap/research flows call **`dispatchIdeas`** (seed
expansion) even for a plain volume need (`KeywordMetricsService.php:128`): it returns the
requested keywords *plus* many related ones, all with volume data, and the webhook caches them
all — so one call warms the cache far beyond what was asked.

## Location / language bridging

The fleet wants **exact Google-Ads names** ("United States", "Spain", "English"); `All`/
`Global`/`Worldwide` drop geo-targeting. The app internally keys its cache on KE short codes.
`KeywordFinderLocations` bridges: `resolveLocation()` (code/name → Ads location, unknown
passes through for free-text region/city pickers), `resolveLanguage()`, `cacheKey()`
(≤16-char, country names reuse their short code), `serperGl()` (KE key → 2-letter Serper `gl`;
`uk`→`gb`). Sanctioned locations (Cuba/Iran/N.Korea/Syria) are intentionally omitted
(`KeywordFinderLocations.php:37`).

## Config (non-secret env)

| Env | Default | Meaning |
|---|---|---|
| `KEYWORD_FINDER_WEBHOOK_PATH` | `/webhooks/keyword-finder` | callback path sent to servers |
| `KEYWORD_FINDER_SIGNATURE_HEADER` | `x-webhook-signature` | HMAC header name |
| `KEYWORD_FINDER_FRESH_DAYS` | `30` | cache TTL for finder rows |
| `KEYWORD_FINDER_REQUEST_TIMEOUT_S` | `15` | per-HTTP-call timeout (connect 5s) |
| `KEYWORD_FINDER_POLL_TTL_MINUTES` | `5` | UI poll budget |
| `KEYWORD_FINDER_DEFAULT_LOCATION` | `United States` | fallback location |
| `KEYWORD_FINDER_DEFAULT_LANGUAGE` | `English` | fallback language |

Per-server `api_key`/`webhook_secret` are admin-entered and encrypted at rest; not env.

## Caps & limits

- **20 seeds** per Idea Finder run; **100 keywords** per Volume Finder run (enforced in the
  UI, see [keyword-research.md](./keyword-research.md)).
- Volume results are bucketed: competition index → low (<34) / medium (<67) / high (≥67).
- One in-flight check per server is serialized by the server's own `/queue` (`running: 0|1`);
  the pool routes around busy ones by queue depth.

## Gotchas / known issues

- **Needs maintained Google Ads logins.** A logged-out server returns `409`/`needsLogin` and
  is auto-flagged unhealthy; if *all* servers are logged out, dispatches `markFailed` with a
  friendly "temporarily unavailable" and **no metrics ever arrive** — health-check cron is the
  early-warning (`CheckKeywordServers.php`).
- **Health is cached, not live at dispatch.** The pool routes off the last 5-min snapshot, so a
  server that died <5 min ago is still tried (then fails transiently and the pool moves on).
- **`is_healthy = up AND logged_in ≠ false`** — a server that's reachable but logged out is
  treated as unhealthy (`CheckKeywordServers.php:77`).
- **Webhook never arrives ⇒ request stuck `running`.** There is no reaper; the UI poll simply
  times out (`KEYWORD_FINDER_POLL_TTL_MINUTES`). The row stays `running` in the DB.
- **`country_key` must not leak upstream** — it's an internal cache key; `dispatch()` unsets it
  from the outgoing body (`KeywordFinderPool.php:161`).

## Key files

- `app/Services/KeywordFinder/{KeywordFinderPool,KeywordFinderClient}.php`
- `app/Models/{KeywordApiServer,KeywordApiRequest}.php`
- `app/Http/Controllers/Webhooks/KeywordFinderWebhookController.php`
- `app/Http/Controllers/Admin/KeywordApiServerController.php`
- `app/Console/Commands/CheckKeywordServers.php` · `app/Support/KeywordFinderLocations.php`
- Migrations: `database/migrations/2026_06_13_100000_create_keyword_api_servers_table.php`,
  `..._100100_create_keyword_api_requests_table.php`, `..._100200_add_bid_range_to_keyword_metrics_table.php`
</content>
