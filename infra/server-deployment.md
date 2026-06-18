# Server deployment — live inventory

The **actual running state** of the two production boxes (captured 2026-06-16, read-only).
This is the concrete companion to [deployment-and-queues.md](./deployment-and-queues.md)
(which covers the *conceptual* topology, queues, and the deploy procedure). When the two
disagree, re-verify on the box — this file is a snapshot.

> Provider: Hetzner Cloud (fsn1). Private network `10.0.0.0/24` links the boxes.

## Box A — web box (`host.ebq.io`)

| | |
|---|---|
| Public IP | `138.199.217.239` (+ IPv6) |
| Private IP | `10.0.0.2` |
| OS / kernel | Ubuntu 24.04.3 LTS / 6.8 |
| Resources | 4 vCPU · 7.6 GiB RAM · 75 GB disk (~19% used) |
| PHP | 8.3.6 (ext: redis, igbinary, intl, gd, mbstring, bcmath, pdo_mysql, sqlite3, sodium…) |

**Serves the EBQ app:** Apache2 on `:80`/`:443` (TLS via Let's Encrypt/certbot), vhost
`ebq.io` → `DocumentRoot /var/www/ebq/public`, PHP via **PHP-FPM** unix socket
`/run/php/php8.3-fpm.sock`. FPM pool `www`: `pm=dynamic`, **`max_children=20`**, start 2 /
min-spare 1 / max-spare 12, **`request_terminate_timeout=120`** (widened after the 504
incident — memory `fpm-pool-504-starvation`). Opcache: `validate_timestamps=0` → code
changes need a **full FPM restart** (memory `CLAUDE.local.md`).

**Data services (shared with Box B over `10.0.0.2`):**
- **MariaDB 10.11** (⚠️ MariaDB, not MySQL — Laravel `mysql` driver) — db **`ebq`**, listening
  `127.0.0.1`, `10.0.0.2` (worker), `172.17.0.1` (docker). **No backups, binlog off** (see
  `CLAUDE.md`).
- **Redis** — `requirepass` set, **`appendonly yes`** (AOF persistence), **`maxmemory-policy
  noeviction`** (critical: cache *and* queues share Redis; eviction would drop jobs). Bound
  `127.0.0.1` + `10.0.0.2`.

**Background workers — supervisor** (`/etc/supervisor/conf.d/ebq.conf`, user `www-data`, logs
`/var/log/ebq/queue.log`):
| Program | procs | Command |
|---|---|---|
| `ebq-queue-interactive` | 2 | `queue:work --queue=interactive --sleep=3 --tries=3 --max-time=3600 --timeout=300` |
| `ebq-queue-general` | 1 | `queue:work --queue=default … --timeout=120` |
| `ebq-schedule` | 1 | `schedule:work` (drives the cron in `routes/console.php`) |

**Co-located, NON-EBQ apps on the same box** (relevant for resource contention; don't touch
when deploying EBQ):
- **Postal** mail server (Ruby) — SMTP `:25`, web `:5000`, `:9090/:9091`. EBQ sends mail
  through it (`MAIL_MAILER=postal`, `POSTAL_SMTP_*` → 127.0.0.1:25). Memory
  `email-delivery-postal`.
- **Jitsi / Prosody** video (`meet.ebq.io`) — XMPP `:5222/:5269/:5280/:5281`, videobridge
  (Java) `:8080/:8888`. Memory `meet-video-bookings` (booking app in `/var/www/marketing`).
- **nginx** on `127.0.0.1:8000` and a Node service on `:3001` — serve marketing/meet, **not**
  the main EBQ app (EBQ is Apache+FPM).

## Box B — worker box (`ubuntu-4gb-fsn1-3`)

| | |
|---|---|
| Private IP | `10.0.0.3` (SSH from Box A with `/root/.ssh/id_ed25519_worker`) |
| OS / kernel | Ubuntu 26.04 LTS / 7.0 (newer than Box A) |
| Resources | 2 vCPU · 3.7 GiB RAM |
| Docker | 29.1.3 · Compose 2.40 |

Runs **only** queue workers as Docker containers — no FPM, web, DB, Redis, or scheduler.
Points `DB_HOST`/`REDIS_HOST` at `10.0.0.2`.

**Stack — `docker-compose.worker.yml`** (lives **on the worker box at
`/var/www/ebq/docker-compose.worker.yml`, NOT in the git repo**), image `ebq-worker:8.3`
(built from `./docker/worker` Dockerfile on the box, ~948 MB), `network_mode: host`,
`restart: always`, bind-mount `/var/www/ebq:/var/www/ebq`:

Queues now run under **Laravel Horizon** (one `ebq-horizon-1` container running the Horizon
master; supervisors/pools come from `config/horizon.php`, selected by `APP_ENV=worker`), NOT
the old per-service raw `queue:work` replicas:

| Horizon pool | procs | queues | timeout | Notes |
|---|---|---|---|---|
| `worker-crawl` (`$crawlPool`) | `CRAWLER_MAX_PROCESSES`=**16** | `crawl` | **300s** | page-fetch pipeline (`CrawlPassJob`/`CrawlPageBatchJob`) |
| `worker-heavy` (`$heavyPool`) | **4** | `sync`, `crawl-finalize` | **3600s** | the long `AnalyzeSiteJob` (pinned box only) + GA/GSC sync. Uses the **`redis-long`** connection (retry_after **3900** > 3600 timeout), so a big-site finalize is never re-reserved mid-run |

> **⚠️ Worker memory ceiling (2026-06-18):** Horizon spawns its workers from `php artisan
> horizon`, which inherits PHP's **CLI default `memory_limit = 128M`** — the pre-Horizon raw
> workers ran `php -d memory_limit=2048M`, and the migration dropped it (Horizon's per-pool
> `memory` key is only the *restart threshold*, not the PHP limit). Large pages (`HtmlAuditor`)
> and the link-graph finalize OOM'd at 128M. Fix: the heavy jobs `ini_set` their own ceiling at
> the top of `handle()` — `CrawlPageBatchJob` (`crawler.batch_memory_limit`, 512M) and
> `AnalyzeSiteJob` (`crawler.analyze_memory_limit`, 1024M) — so it travels with the code to every
> box (pinned + ephemeral) regardless of the snapshot/php.ini. Ceilings, not reservations.

So the crawl pipeline + finalize + GA/GSC sync run **here**; Box A never crawls.
`REDIS_QUEUE_RETRY_AFTER=1320` must stay above the longest job timeout. **Autoscaled ephemeral
boxes** (see [crawler/autoscaling.md](./crawler/autoscaling.md)) run only the `crawl` service
(via `docker-compose.ephemeral.yml`, `--timeout=300`) — never finalize/sync — so a scale-down
drain can't interrupt a finalize. They reach Redis/MariaDB because Box A's `ufw` allows the
private subnet (`10.0.0.0/24 → 6379 + 3306`), not just the one pinned worker IP. Containers run with `network_mode: host`, so a code change = rsync `/var/www/ebq` +
`docker compose … up -d` (bind-mounted code; **no image rebuild needed** unless system deps
change). Code last synced 2026-06-16 18:16 — on current `crawl_site_id` code.

## Deploy mechanism

- **Manual, git-pull based.** `origin = git@github.com:malihaider19967-web/ebq.git`. There is
  **no CD pipeline** — `.github/workflows/` holds CI only (`tests.yml`, `issues.yml`,
  `pull-requests.yml`, `update-changelog.yml`).
- Full procedure (both boxes, in lockstep) in
  [deployment-and-queues.md](./deployment-and-queues.md#deploying-a-code-change).

## External integrations (from `.env` — keys only, no secrets)

| Purpose | Keys |
|---|---|
| Google OAuth (GSC/GA) | `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` |
| Google CAP (JWT validation) | `GOOGLE_CAP_AUDIENCE/JWKS_URL/ISSUERS` |
| LLM | **`MISTRAL_API_KEY`** (the AI suite runs on Mistral) |
| SERP | `SERPER_API_KEY` (+ `SERPER_COST_PER_CALL_USD`) |
| Keyword data | `KEYWORDS_EVERYWHERE_API_KEY`, `…_BACKLINKS_ENDPOINT`, `…_COST_PER_KEYWORD_USD` |
| Lighthouse | `LIGHTHOUSE_API_URL/KEY/TIMEOUT_S` (external Lighthouse service) |
| Billing | `STRIPE_KEY/SECRET/WEBHOOK_SECRET/WEBHOOK_TOLERANCE` |
| Storage | `FILESYSTEM_DISK`, `AWS_*` (S3 bucket) |
| Errors | `SENTRY_LARAVEL_DSN` |
| Abuse | `RECAPTCHA_SITE_KEY/SECRET_KEY` (guest tools) |
| Misc | `RESEARCH_SCRAPER_PYTHON`, `LANGUAGE_DETECTION_ENABLED` |

## ⚠️ Deployment risks / notes

- **`APP_ENV=local` and `APP_DEBUG=true` on production.** Debug mode leaks stack traces/config
  on errors and changes framework behaviour — and a stale **cached config** under
  `APP_ENV=local` is exactly what wiped the DB once (`CLAUDE.md`). Worth moving to
  `production`/`APP_DEBUG=false` deliberately (test first — some flows may assume `local`).
- **`MAIL_HOST=smtp.sendgrid.net` is stale** — the active mailer is `postal` via
  `POSTAL_SMTP_*`. The SendGrid host is leftover (SendGrid was silently dropping mail).
- **Redis is the single point for cache + all queues** — `noeviction` is correct; keep an eye
  on memory (7.6 GiB box shared with Postal/Jitsi).
- **Two different OS versions** across boxes (24.04 vs 26.04) — keep **PHP at 8.3** on both
  regardless (8.5 breaks queued-closure serialization).
- **The worker compose file isn't version-controlled.** If the worker box is rebuilt, recreate
  `/var/www/ebq/docker-compose.worker.yml` + `./docker/worker/Dockerfile` from this doc.
