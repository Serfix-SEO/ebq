# Firecrawl render server (10.0.0.4) — converted from staging 2026-07-27

Self-hosted [Firecrawl](https://github.com/firecrawl/firecrawl) render/scrape service. Runs a
headless Chromium (Playwright) behind our **residential proxy**, so we can read sites the
HTTP-only crawler can't — JS/SPA pages and **Cloudflare-challenged** sites (e.g.
gpsmarketing.agency, Cloudflare Managed Challenge) that the Content wizard needs to profile.

Replaces the old staging environment (same Hetzner instance, clean OS rebuild — see
`git log`/[[staging-environment]]). **Staging is retired**: `scripts/deploy-staging.sh`,
`staging.serfix.io`, and the staging-first workflow no longer apply.

## The box

| | |
|---|---|
| Server | `ebq-firecrawl` (Hetzner id **151872820**, cx33 4 vCPU / 8 GB, fsn1) — rebuilt to Ubuntu 24.04, **same instance/IP** |
| IPs | private **`10.0.0.4`** (10.0.0.0/24), public `178.105.24.246` |
| Endpoint | **`http://10.0.0.4:3002`** — bound to the **private IP only** (compose `ports: "10.0.0.4:3002:3002"`); public `178.105.24.246:3002` is refused. Reachable from box A/B over the private net. |
| Auth | `USE_DB_AUTHENTICATION=false` (no key enforced); a `TEST_API_KEY` bearer is set in the box `.env` (security = private-net isolation, not the key) |
| Access | `ssh -i /root/.ssh/id_ed25519_worker root@10.0.0.4` from box A |
| Firewall | still `ebq-staging-web` (id 11323177) + shared — public 3002 not allowed, so private-only holds even without firewall edits |

## Stack — `/opt/firecrawl` (Docker Compose)

- Cloned from `github.com/firecrawl/firecrawl`; **prebuilt GHCR images** (edited `docker-compose.yaml`:
  `build:` → `image: ghcr.io/firecrawl/{firecrawl,playwright-service,nuq-postgres}` — no source build).
- Services run: `api` (harness = API + workers + extract-worker, one container ~2.8 GB), `playwright-service`
  (Chromium, mem_limit 4 G), `redis`, `rabbitmq`, `nuq-postgres` (Postgres queue backend).
  **FoundationDB skipped** (`foundationdb`/`-init` not started; `NUQ_BACKEND` unset → Postgres).
- `.env` (root): `PORT=3002`, `USE_DB_AUTHENTICATION=false`, concurrency capped for 8 GB
  (`NUM_WORKERS_PER_QUEUE=2`, `MAX_CONCURRENT_JOBS=2`, `BROWSER_POOL_SIZE=2`,
  `CRAWL_CONCURRENT_REQUESTS=2`), `PROXY_SERVER=http://<residential-ip>:12323` +
  `PROXY_USERNAME`/`PROXY_PASSWORD` (from box A `proxylist.txt` line 1 — the Webshare residential pool).
- 4 GB swap added as a Chromium OOM cushion. Idle footprint ≈ 3.9 GB used / 7.6 GB.

## Ops

```
cd /opt/firecrawl
docker compose up -d api playwright-service redis rabbitmq nuq-postgres   # bring up (don't start foundationdb)
docker compose ps
docker compose logs api --tail 50
docker compose down && docker compose up -d api playwright-service redis rabbitmq nuq-postgres  # restart
```
Scrape: `POST http://10.0.0.4:3002/v1/scrape` `{"url":"...","formats":["markdown"|"html"|"rawHtml"]}`
with `Authorization: Bearer <TEST_API_KEY>`. All requests egress through the residential proxy.

## Validation (2026-07-27) — **GO**

- `example.com` → 200, real content. `vercel.com` (Next.js SPA) → 200, 3.4 KB rendered (JS works).
- **`gpsmarketing.agency` (Cloudflare Managed Challenge)** → **200, 44 KB real content**
  ("Top Digital Marketing Agency In Dubai"). Residential IP dropped CF's risk score enough that no
  interactive challenge was served; Chromium handled the rest. First hit was a transient CF **520** →
  **retry-on-5xx** clears it (all retries succeeded). ⚠️ Self-host has **no Fire-engine** (CF's own
  anti-bot layer is cloud-only), so harder targets may still fail; the residential IP is what carried this one.

## App integration (Phase 6 — PENDING, not yet wired)

- New `App\Services\Crawler\FirecrawlClient` → `POST {FIRECRAWL_URL}/v1/scrape`
  (`services.firecrawl.{url,key,enabled,timeout}`; `FIRECRAWL_URL=http://10.0.0.4:3002`,
  `FIRECRAWL_API_KEY=<box .env TEST_API_KEY>` on box A/B `.env`). Run `SafeHttpGuard::check($targetUrl)`
  **before** calling (we hand it client URLs). Retry once on 5xx (transient 520).
- Wire as a **render fallback**, gated per-site, at the seams:
  `CrawlFetcher::fetch` (`app/Services/Crawler/CrawlFetcher.php:37/84`) and
  `SiteProfileExtractor::fetchFollowingRedirects` (`app/Services/Content/SiteProfileExtractor.php:313`)
  — escalate to Firecrawl only when HTTP returns a Cloudflare challenge (`403` + `Just a moment` /
  `cf-mitigated: challenge` / `/cdn-cgi/challenge-platform`) or a thin body; cache the "needs render"
  decision on `crawl_sites` (also set `crawl_protection='cloudflare'`).

## Caveats

- 4 static residential IPs shared with the crawler's `on_block` proxy path — minor contention;
  Firecrawl use is low-volume (onboarding/profile fetches).
- Single box, no HA. `restart: always` survives reboot. If it dies, onboarding for CF/JS sites falls
  back to blank fields (user fills manually) — no prod dependency.
