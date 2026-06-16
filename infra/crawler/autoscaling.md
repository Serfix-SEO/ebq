# Crawl-worker fleet autoscaling

Elastic scaling of the crawl-worker fleet on Hetzner Cloud. One worker box can't drain large
backlogs; this lets the fleet **grow and shrink automatically** with crawl demand.

## Why it's safe to scale by construction

The crawl queue lives in **one central Redis** (10.0.0.2); workers are **stateless competing
consumers** that *pull* jobs. A new box's containers connect to that Redis and immediately
start pulling — **no job hand-off, no rebalancing, no coordination.** Scaling is purely an
*infrastructure* problem: provision/destroy boxes and keep them polite + safe to kill. The
scale-down safety net already exists — [`CrawlSupervisor`](./pipeline.md) recovers any run
wedged by a killed worker within `stall_minutes` (10), and Laravel re-runs a reserved job
after `retry_after` (1320s).

## Fleet data model — `worker_nodes`

One row per worker box (mirrors the keyword-server fleet pattern). `app/Models/WorkerNode.php`:
- `status`: `provisioning → active → draining → deleting`, or `failed`. Scopes `active`,
  `billable` (provisioning+active+draining = currently costing money), `drainable`.
- `is_pinned`: the **permanent box (10.0.0.3)** — never scaled down; baseline capacity + home
  of the (future) `crawl-finalize` queue.
- `hetzner_server_id`, `private_ip`, `server_type`, health columns (`is_healthy`,
  `last_seen_at`, `last_queue_*`), `provisioned_at` / `drain_started_at` for cost + drain math.
- **No secrets** stored — a node holds no token; the only fleet secret is `HCLOUD_TOKEN` on the
  web box.

## Components

| Piece | File | Role |
|---|---|---|
| Hetzner API client | `app/Services/Fleet/HetznerClient.php` | `Http` wrapper (create/delete/get/listByLabel); never throws, returns structured outcomes. |
| Fleet orchestration | `app/Services/Fleet/WorkerFleetService.php` | `provision/bootstrap/drain/destroy/reconcile` + `desiredFromBacklog()` (pure scaling math) + `billableCount()`. |
| Config (admin-editable) | `app/Support/AutoscalerConfig.php` | `Setting`-backed, clamped knobs: `enabled, min/max_boxes, target_backlog_per_box, server_type, snapshot_id, cooldowns, min_box_lifetime, per_domain_rate`. |
| Operator command | `app/Console/Commands/FleetWorker.php` | `ebq:fleet-worker {list\|provision\|bootstrap\|drain\|destroy\|reconcile\|register-pinned}` — manual control (Phase 1). |
| Fixed infra | `config/services.php` → `hetzner.*` | token, location (`fsn1`), network/ssh-key/firewall ids, snapshot image, web-box IP. |

**Scaling math:** `desired = clamp(ceil(crawlQueueDepth / target_backlog_per_box), min_boxes, max_boxes)`.

## Bootstrap model — web-box PUSH (reuses today's deploy)

A new box boots from a **snapshot** (Docker + the `ebq-worker` image + `docker-compose.worker.yml`
+ `docker/worker/Dockerfile` + the web box's **public** deploy key in `authorized_keys`). The
web box then **pushes**: `rsync` code (NO `--delete`) + a worker `.env` and runs
`docker compose up -d` over SSH with `/root/.ssh/id_ed25519_worker`. **No private key or API
token ever lands on an ephemeral box.**

## Operator prerequisites (must be set up before real provisioning)

These are infra one-time setup, not code:
1. **`HCLOUD_TOKEN`** in the web box `.env` (read/write Hetzner project token).
2. **A worker snapshot** — build the `ebq-worker` image on a box, install Docker, drop in
   `docker-compose.worker.yml` + `docker/worker/Dockerfile` + the deploy public key, snapshot it,
   and set its id in `HCLOUD_WORKER_IMAGE` (or the `autoscaler.snapshot_id` setting).
3. **Hetzner ids** in `.env`: `HCLOUD_NETWORK_ID` (the 10.0.0.0/24 private net), `HCLOUD_SSH_KEY_ID`
   (the id_ed25519_worker public key registered in Hetzner), `HCLOUD_FIREWALL_ID`.
4. **A Hetzner Cloud Firewall** on label `role=ebq-crawl-worker`: block public 6379/3306, allow
   only the `10.0.0.0/24` subnet; SSH only from 10.0.0.2.
5. **`/var/www/ebq/.env.worker`** on the web box — the worker `.env` template (DB_HOST/REDIS_HOST
   = 10.0.0.2, `REDIS_PASSWORD`, `APP_KEY` (must match for queue payload decryption),
   `REDIS_QUEUE_RETRY_AFTER=1320`). Pushed as each new box's `.env`.

Until these exist, `ebq:fleet-worker provision` returns a clear "HCLOUD_TOKEN not configured"
error — the code is in place; provisioning activates once the token/snapshot are set.

## Status — all phases shipped (code); autoscaler disabled until the Hetzner setup is done

- **Phase 1 ✅** fleet model, Hetzner client, fleet service, `ebq:fleet-worker`, config, pinned box.
- **Phase 2 ✅** `crawl-finalize` queue split — `AnalyzeSiteJob` (1200s) now runs ONLY on the
  pinned box (`Queues::CRAWL_FINALIZE`; the box's compose gained an `ebq-finalize-1` worker +
  `stop_grace_period: 360s`). Ephemeral boxes run `--queue=crawl` only, so a drain can never
  interrupt a finalize.
- **Phase 3 ✅** `DomainRateLimiter` (Redis token bucket per normalized domain) in
  `PageCrawlProcessor::fetchWithPolicy()` — fleet-wide per-domain politeness, fail-open.
- **Phase 4 ✅** `ebq:fleet-autoscale` (every 2 min, `withoutOverlapping`, hysteresis,
  one-box-per-tick) + `ebq:check-worker-nodes` (5-min health) + **`/admin/fleet`** panel (live
  status + est. hourly cost + editable autoscaler settings + provision/drain/reconcile buttons).

**To turn it on:** complete the operator prerequisites above, then set `autoscaler.enabled`
(and `min/max_boxes`, `target_backlog_per_box`, `server_type`, `snapshot_id`) at `/admin/fleet`.
Until enabled, `ebq:fleet-autoscale` is a no-op and the scheduled tick logs nothing. Validate
manually first with `ebq:fleet-worker provision` (one box) and `ebq:fleet-autoscale --dry-run`.

## Cross-cutting risks

- **DB write contention is the next bottleneck** — `website_pages`/`website_internal_links`
  writes scale linearly against the single MariaDB; cap `max_boxes` below the DB knee.
- **Expand/contract migrations only** — you can't update N ephemeral boxes atomically; never
  change a queued job's identity while old-version boxes may be draining (the shared-crawl
  `uniqueId` lock-leak lesson — see [../deployment-and-queues.md](../deployment-and-queues.md)).
- **Redis is a SPOF** for the whole fleet; Hetzner bills hourly → scale-down is deliberately
  conservative (`min_box_lifetime_s`, long idle window).
