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
- Per-server concurrency is the node's `QUEUE_CONCURRENCY` (Node 1: **2** since
  2026-07-07, so `/queue` can report `running: 0..2`); the pool routes around busy
  servers by queue depth (`last_queue_waiting`).

## Admin live queue (added 2026-06-23)

`/admin/keyword-servers` shows a "Live queue" panel above the server list: every
`KeywordApiRequest` still `queued`/`running`, across all servers, with server, type/mode,
keyword(s)/URL (`KeywordApiRequest::keywordSummary()` — first 3 seeds + "+N more", or the
URL for website/page mode), the requesting user, and queued-at. Built because there was no
way to see what's backed up without grepping logs — the existing per-server "Last result"
panel only ever shows the single most recent request, any status. `user()`/`website()`
relations were missing on the model entirely (only `server()` existed) — added both.

## Ideas results cached for the calendar month, shared across users (added 2026-06-23)

`KeywordIdeaFinder` (seed expansion + website/page discovery — NOT the Volume Finder's
per-keyword metrics, which already has its own rolling cache via `KeywordMetricsService`)
now checks `KeywordIdeasMonthlyCache` before dispatching: same seeds (order/case
insensitive) or same URL+scope, same location/language → same cached result, **instantly**,
no queue dispatch, no node load, shared across every user — not just the original
searcher. Deliberately calendar-month, not a rolling N-day TTL (explicit product
decision): the cache key embeds `Y-m` and `Cache::put()` expires at `now()->endOfMonth()`,
so a new month is a guaranteed miss even if the TTL math were ever off.

`KeywordFinderPool::dispatchIdeas()` was split to expose `buildIdeasPayload()` (the
mode+payload normalization) so the cache key is computed from the *exact* same normalized
data a real dispatch would send — no risk of the cache-key logic drifting out of sync with
what actually gets POSTed. `KeywordIdeaFinder::run()` checks the cache first; on a miss it
dispatches as before and stashes the cache key in `$pendingCacheKey` (a public Livewire
property, so it survives the poll round-trip); `poll()` writes the result into that key once
the webhook completes it. UI shows an indigo "Instant result" badge when `$fromCache` is true.

## Gotchas / known issues

- **Needs maintained Google Ads logins.** A logged-out server returns `409`/`needsLogin` and
  is auto-flagged unhealthy; if *all* servers are logged out, dispatches `markFailed` with a
  friendly "temporarily unavailable" and **no metrics ever arrive** — health-check cron is the
  early-warning (`CheckKeywordServers.php`).
- **Health is cached, not live at dispatch.** The pool routes off the last 5-min snapshot, so a
  server that died <5 min ago is still tried (then fails transiently and the pool moves on).
- **`is_healthy = up AND logged_in ≠ false`** — a server that's reachable but logged out is
  treated as unhealthy (`CheckKeywordServers.php:77`).
- **Webhook never arrives ⇒ request stuck `running` — until the reaper fails it.** The node
  gives up webhook delivery after 3 attempts (~15s), so a node crash or lost delivery used to
  strand rows forever (UI poll just times out via `KEYWORD_FINDER_POLL_TTL_MINUTES`). Since
  2026-07-07 `ebq:reap-stuck-keyword-requests` (every 10 min, `routes/console.php`) marks
  `queued`/`running` rows older than 15 min as failed
  (`app/Console/Commands/ReapStuckKeywordRequests.php`).
- **`webhook_url` is built from `url()` at dispatch time ⇒ every box that dispatches must have
  the canonical `APP_URL`.** Incident 2026-07-07: worker box B's `.env` still had
  `APP_URL=https://ebq.io` after the serfix.io migration; Horizon-dispatched requests told the
  node fleet to call `https://ebq.io/webhooks/keyword-finder`, Apache 301'd to serfix.io, the
  node HTTP client followed the redirect **as GET** (per-RFC method rewrite on 301), Laravel
  answered 405, the POST body was lost, and rows sat `running` forever. Same-domain changes
  must update `.env` on **both** boxes (+ `docker restart ebq-horizon-1`). Recovery: re-POST
  the same `request_id` + stored `payload` to the server with the fixed `webhook_url` — the
  webhook is idempotent per `request_id`, so the original rows complete in place.
- **The node fleet IGNORES the per-request `webhook_url`** — `dispatchWebhook` only reads
  `config.webhookUrl` from the node's own `.env` (`src/webhook.js`); the `webhook_url` field
  our pool sends is dead weight. Node 1's `.env` was fixed to serfix.io on 2026-07-07. The
  old-domain vhosts also carry a method-preserving **308** redirect for `/webhooks/*` (before
  the 301 catch-all, both `/etc/apache2/sites-enabled/ebq.io.conf` and `ebq.io-le-ssl.conf`)
  as defense-in-depth — node webhook `fetch` follows a 301 as GET (payload lost, Laravel 405);
  308 preserves POST+body. Keep those rules.

## Node internals (Node 1, learned 2026-07-07 via SSH)

Node 1 runs **on worker box B itself** (public IP `178.105.218.22` = `ubuntu-4gb-fsn1-3`,
private `10.0.0.3`) — not a separate machine. Root SSH is password-auth (no shared key).

- App: `/root/keywordfetcher` (Node 22 + Express + Playwright 1.60), systemd unit
  `keywordfetcher.service` wrapped in `xvfb-run` (headed Chromium on a virtual display;
  `HEADLESS=false` because GKP bot-detection). nginx proxies :80 → :3000. A second unit
  `kwf-relay.service` (`relay.mjs`) is a local retry proxy on 127.0.0.1:8888 that Chromium
  uses as `--proxy-server` (with `--disable-http2` — Google coalesces onto one h2 connection
  which stalls behind a CONNECT proxy).
- Config: `/root/keywordfetcher/.env` — `WEBHOOK_URL` (the real callback target),
  `WEBHOOK_SECRET`/`API_KEY` (must match the server row in our admin), `USER_DATA_DIR`
  (persistent Chrome profile holding the Google Ads login + 2FA session, created once via
  `npm run setup`). After editing: `systemctl restart keywordfetcher` (login survives — it
  lives in the profile dir).
- **Concurrency: `QUEUE_CONCURRENCY` env — Node 1 runs 1; the code supports N but THIS BOX
  can't sustain 2.** `src/browser.js` (rewritten 2026-07-07) runs each job on its **own tab**
  (`ctx.newPage()`, closed in `finally`) of the single shared logged-in persistent context,
  `PQueue({concurrency})` gates in-flight jobs, and a launch mutex (`contextLaunch` promise)
  stops concurrent jobs double-launching the persistent context (second launch dies on the
  profile lock). Pre-rewrite it was one shared reused tab (GKP pickers are stateful page UI —
  parallel jobs on one tab would corrupt each other; tabs isolate that). **2026-07-07 trial
  of `QUEUE_CONCURRENCY=2`: mechanism works** (two jobs overlapped, independent per-tab
  location/language, ~1.7× when both succeeded) **but ~50% of concurrent jobs failed with
  30–45s interaction timeouts** ("Get results" click, results-table wait, "Include" outside
  viewport) while single jobs passed 100% — the box has **2 vCPU shared with Horizon**, and
  two software-rendered (swiftshader/xvfb) GKP tabs starve each other. Reverted to 1.
  Raising it again needs more vCPUs or a dedicated node (separate Ads account — also
  the quota/detection-budget answer). CSV downloads are concurrency-safe (unique `hrtime`
  suffix). Rollback of the whole rewrite: `src.bak-2026-07-07/` on the node. App log:
  `/var/log/keywordfetcher.log` (systemd `StandardOutput=append:`).
- Webhook delivery: 3 attempts (0s/+1s/+3s backoff), 10s timeout each, HMAC-SHA256 of raw
  body, then **gives up permanently** (`src/webhook.js`) — a down receiver still loses
  results; the app-side reaper (`ebq:reap-stuck-keyword-requests`) is the backstop.
- **Crash incident (2026-07-07, first hour of concurrency 2):** a tab died mid-job (renderer
  crash, no OOM logged) while `page.waitForEvent('download')` was pending but not yet awaited
  → unhandled rejection → **Node 22 killed the whole process** → every in-flight job lost, no
  failure webhooks, rows stuck. Hardened: `process.on('unhandledRejection'/'uncaughtException')`
  guards in `src/server.js` (log `[fatal-guard]`, keep serving) + pre-attached no-op catch on
  the download promise in `captureCsv`. A failed job now delivers a `failed` webhook (verified
  live: a GKP "Element is outside of the viewport" flake failed one job cleanly, the parallel
  job completed). If viewport/click flakes recur at concurrency 2, consider per-job retry or
  dropping `QUEUE_CONCURRENCY` back to 1.
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
