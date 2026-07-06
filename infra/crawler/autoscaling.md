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
web box then **pushes** over SSH (`/root/.ssh/id_ed25519_worker`). **No private key or API token
ever lands on an ephemeral box.** `bootstrap()` does, in order:
1. `rsync` code (NO `--delete`, excludes `vendor/` among others).
2. `rsync vendor/` **separately, WITH `--delete`** — vendor is excluded from step 1, so without
   this a Composer change never reaches a worker (its vendor stays frozen at snapshot age).
   Makes "composer change → redeploy" enough; no snapshot rebuild. Verified: a fresh box was
   2,705 vendor files behind the web box before this.
3. `rsync` the worker `.env`, then **stamp per-box identity**: `APP_ENV=worker-ephemeral`
   (selects the crawl-only Horizon supervisor), `FLEET_NODE_ID` + `HORIZON_NAME` (per-box queue
   counters + dashboard label). Stamp uses `grep||sed||echo` per key — NO nested double-quotes
   (an earlier for-loop version silently no-op'd FLEET_NODE_ID inside the ssh double-quotes).
4. `docker compose up -d --force-recreate --remove-orphans`. **`--force-recreate` is essential:**
   the box boots from a snapshot whose containers auto-start (`restart:always`) on OLD code, and
   `up -d` alone will NOT recreate a container whose service config is unchanged — so a code-only
   deploy would leave the long-running worker (Horizon master) running **stale code in memory**
   (volume-mounted files update; the PHP process does not). `--force-recreate` rebuilds the
   container every bootstrap so Horizon reloads the freshly-rsynced code. Verified by uptime reset
   (container "Up 3 min" → "Up 14 s") and a sentinel file present in the new code.

The box runs a single **Horizon** master (`docker-compose.ephemeral.yml` → `php artisan horizon`),
crawl-only via the `worker-ephemeral` env. See [../reference/jobs-and-scheduler.md](../reference/jobs-and-scheduler.md) (Horizon section).

### Reliability — "box runs old code" is two failure modes, both handled

A fresh box boots from the snapshot, whose containers `restart:always` → they auto-start the
snapshot's OLD code immediately. Two ways that bites, both now permanent-fixed:
1. **Resync happened but the worker holds old code in memory** → bootstrap step 4 uses
   `up -d --force-recreate`, which restarts the Horizon master AFTER the rsync, so it loads the
   new code. ("Supervisor activated after the code update.")
2. **Resync never happened** (bootstrap aborted, e.g. `waitForSsh` timed out because the box came
   up after the wait) → the box was left stuck at `provisioning`, running snapshot code forever AND
   blocking scale-up. Fixes: (a) bootstrap **stops the snapshot's stale containers on connect**
   (step 0 `docker compose down`) so old code stops pulling jobs even mid-bootstrap; (b) `waitForSsh`
   raised to ~7.5 min; (c) **`FleetAutoscale::recoverStuck()`** runs every tick — re-runs the
   idempotent `bootstrap()` on any box stuck at `provisioning` (recovers late-SSH boxes), reaps
   `failed` boxes, and destroys boxes stuck > `PROVISION_STUCK_MINUTES` (18). So the fleet self-heals
   without manual intervention; nothing sits on old code or blocks the loop.

**Deeper root fix: rebuild the worker snapshot "cold"** — current code baked in AND the worker
containers NOT auto-starting. **Script: `scripts/worker/build-worker-snapshot.sh`** — provisions a
temp box from the current `HCLOUD_WORKER_IMAGE` (reuses Docker + the `ebq-worker` image + deploy
key), rsyncs current code+vendor+`.env.worker`+`docker-compose.ephemeral.yml`, runs
`docker compose down` (so a fresh box auto-starts NOTHING), powers off, snapshots, prints the new
image id. Then set `HCLOUD_WORKER_IMAGE` to it (+ `.env.worker`) and `cache:forget fleet:snapshots:worker`.
Re-run after any deploy you want baked into the base image; bootstrap still rsyncs current code on
top, so the snapshot only needs refreshing occasionally.

**Auto-refresh on git-HEAD drift (so the snapshot self-maintains):**
- `AutoscalerConfig` tracks `snapshot_id` + `snapshot_head` (the git HEAD the snapshot was built from)
  and an `auto_snapshot` kill-switch (toggle at /admin/fleet — turn OFF while working on the server so
  it doesn't rebuild repeatedly).
- **`ebq:refresh-worker-snapshot`** (scheduled hourly, `runInBackground` + `withoutOverlapping` + an
  internal Cache lock): if `auto_snapshot` is on and current HEAD ≠ `snapshot_head`, it runs the build
  script, then sets `snapshot_id`=new image + `snapshot_head`=HEAD. `--force` rebuilds regardless.
- **Provision-time gates** (run before a box is created, every scale-up):
  1. **Snapshot exists** (`FleetAutoscale::snapshotExists` → `HetznerClient::imageExists`): the configured
     `snapshot_id` must still resolve to a live image. `imageExists` is **tri-state** — a confirmed 404
     means gone (→ rebuild if `auto_snapshot`, else skip with an actionable error); a transient API error
     (`null`) just holds the tick without triggering a rebuild. Added 2026-06-18 after a deleted snapshot
     made `createServer` 422 ("image not found") every tick and the autoscaler **looped provision→reap**
     a dead node (the git-HEAD gate below let it through because HEAD still matched). `provision()` repeats
     the check as defense-in-depth for the manual `ebq:fleet-worker` path.
  2. **HEAD not drifted** (`FleetAutoscale::snapshotReady`): if HEAD drifted it kicks the (self-locked)
     rebuild and **defers** scale-up to a later tick — so a box is never built from a stale base. It defers
     across 2-min ticks (each ~instant), it does NOT block one tick for the ~15-min build. With
     `auto_snapshot` OFF, no drift gate — provisioning uses whatever `snapshot_id`/`HCLOUD_WORKER_IMAGE` is set
     (the existence gate still applies).
- HEAD-based drift fits the workflow: uncommitted edits don't move HEAD (no rebuild while hacking on the
  box); a commit (a real deploy) does → one background rebuild.

**Worker memory ceiling on ephemeral boxes (2026-06-18):** ephemeral boxes run crawl-only, and their
crawl workers parse large HTML (`HtmlAuditor`) — which OOM'd at PHP's CLI-default 128M after the Horizon
migration dropped the old `-d memory_limit=2048M` (see server-deployment.md). The fix is a per-job
`ini_set` (`CrawlPageBatchJob` → `crawler.batch_memory_limit`, default 512M) that **travels to every box
via `bootstrap()`'s full-app rsync** — so a new autoscaled box is covered without a snapshot rebuild and
without any `.env.worker` change (the knob defaults in `config/crawler.php`). `AnalyzeSiteJob` does the
same (1024M) but only ever runs on the pinned box.

## How fixes reach new boxes & snapshots (permanence — no manual step)

Crawler-behaviour fixes (memory ceilings, the finalize lock/graph fixes, rate/block logic, etc.) live in
**application code**, not in the box-local image/compose, so they propagate automatically:
- **Existing boxes** (pinned + already-running ephemeral): a normal deploy rsyncs current code; restart.
- **A new ephemeral box at provision time**: `WorkerFleetService::bootstrap()` rsyncs the **full current
  app** onto it before starting Horizon — so it runs the latest committed code regardless of how old the
  snapshot is.
- **The snapshot itself**: `scripts/worker/build-worker-snapshot.sh` **rsyncs current committed code**
  onto the temp box *before* snapshotting — so every newly-built snapshot bakes the latest fixes. The
  hourly `ebq:refresh-worker-snapshot` (when `auto_snapshot` is ON) rebuilds it on git-HEAD drift.

Net: **commit + push, and the next snapshot/box has the fixes — no manual edit on any box.** The memory
ceiling deliberately uses a per-job `ini_set` (not the image's php.ini) precisely so it travels with the
code and never depends on rebuilding the box-local `docker/worker` image. (Do **not** move
`docker-compose.worker.yml` into git — pinned and ephemeral composes differ, and a deploy rsync would
overwrite the pinned box's; it is intentionally box-local.)

> **Recovery if the snapshot AND its base are both deleted** (2026-06-18, during unrelated Hetzner
> cleanup): `build-worker-snapshot.sh` provisions its temp box *from* `HCLOUD_WORKER_IMAGE`, so if that
> base image is gone the build can't run and `auto_snapshot` can't self-heal. This is the **only** step
> needing the operator: seed **one** new worker snapshot (per prerequisite 2 — a box with Docker + the
> `ebq-worker` image + deploy key), then set `HCLOUD_WORKER_IMAGE`/`autoscaler.snapshot_id` to it. After
> that, snapshots stay current automatically (the build rsyncs current code). Until then the autoscaler's
> `snapshotExists` gate keeps the fleet from looping on a missing image, and the **pinned box handles all
> crawl load** — correctness is unaffected, only elastic scale-up is paused.
>
> **This actually happened and was recovered 2026-06-23.** Both `398793967` (snapshot) and
> `398736889` (its base) were gone — confirmed via `HetznerClient::imageExists()` returning
> `false` for both, which is why a real `provision` attempt logged `WorkerFleet: provision
> aborted — snapshot missing` and the autoscaler reaped the failed node every tick. Since
> `docker/worker/Dockerfile` lives only on the worker box (not in the repo — see above), there
> was no way to rebuild the image from a stock OS + Dockerfile either. Recovery path used: boot
> a temp box from a Hetzner **stock** `ubuntu-24.04` system image, `docker save ebq-worker:8.3`
> off the still-alive **pinned** box (10.0.0.3) piped straight into `docker load` on the temp box
> (no local tarball needed — `ssh ... save | ssh ... load` relayed through the web box), rsync
> current code+vendor+`.env.worker`, then snapshot cold. **Gotcha hit on the first attempt:** a
> truly-from-scratch box has never had `bootstrap/cache/` (or the `storage/framework/*`
> subdirs) created — `WorkerFleetService::bootstrap()` only runs `rm -f bootstrap/cache/*.php`,
> it assumes the directory already exists (true for every *incremental* rebuild, since those
> provision FROM the previous working snapshot — false for a from-scratch build). Container
> crash-looped on `PackageManifest.php: The /var/www/ebq/bootstrap/cache directory must be
> present and writable.` until `mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,views}
> storage/logs` was run once, then re-snapshotted. New snapshot: `400739386`. Verified with a
> real `ebq:fleet-worker provision` → container stable, Horizon started clean, 8 Redis
> connections from the new box's IP confirmed it was actually polling the crawl queue → drained
> + destroyed the test node. **If a snapshot is ever rebuilt fully from scratch again, add the
> directory creation to `build-worker-snapshot.sh` (or bake empty dirs into a base image) so
> this doesn't repeat.**
>
> Also found + fixed while testing this: `ebq:fleet-worker drain/destroy/bootstrap --id=`
> (`app/Console/Commands/FleetWorker.php`'s `node()` helper) did `(int) $this->option('id')` on
> a ULID — left over from before the ULID migration, silently mangled e.g.
> `01kvta9tq98p8kv81zhnstk1ew` into `1` and always failed with "--id is required...". Fixed to
> a plain string lookup.

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
6. **Web box `ufw` must allow the private SUBNET to Redis + MariaDB** — not just the one existing
   worker IP. Ephemeral workers get fresh IPs (10.0.0.4, .5, …), so:
   ```
   ufw allow from 10.0.0.0/24 to 10.0.0.2 port 6379 proto tcp   # Redis
   ufw allow from 10.0.0.0/24 to 10.0.0.2 port 3306 proto tcp   # MariaDB
   ```
   Without this a new box's workers crash-loop on "Connection timed out" (the firewall drops
   them) and can't pull jobs. (Found during the first live provision test, 2026-06-17.)
8. **MariaDB GRANT for the worker SUBNET on the PRIMARY DB** — separate layer from `ufw`: ufw lets
   the TCP connection through, but MariaDB still rejects the host with `[1130] Host '10.0.0.X' is
   not allowed to connect` unless the app user is granted for that host. Shard nodes already do this
   in `DbFleetService::bootstrap` (`CREATE USER 'ebquser'@'10.0.0.%'` + GRANT). The **primary** DB
   is *registered*, not bootstrapped, so it needs it once (as root on 10.0.0.2):
   ```sql
   CREATE USER IF NOT EXISTS 'ebquser'@'10.0.0.%' IDENTIFIED BY PASSWORD '<same hash as @10.0.0.3>';
   GRANT ALL PRIVILEGES ON `ebq_v2`.* TO 'ebquser'@'10.0.0.%';  -- + ebq, ebq_shard1 to match
   FLUSH PRIVILEGES;
   ```
   The `10.0.0.%` wildcard covers ALL current + future ephemeral worker IPs. Applied 2026-06-18 —
   before it, every autoscaled worker failed 100% of DB jobs with `[1130]`. Re-apply if the primary
   DB is ever rebuilt.
7. **`server_type` must be a line your account/location offers** — these boxes use `cx` (shared
   Intel, e.g. `cx23` in fsn1); `cpx*` (AMD) returned "unsupported location for server type".
   The default is `cx23`; pick at `/admin/fleet`.

> **Verified end-to-end (2026-06-17):** a live `provision → bootstrap → (Redis CLIENT LIST
> confirmed the 5 workers polling the crawl queue) → drain → destroy` cycle succeeded against
> the real Hetzner API, from a snapshot of the existing worker box, never touching the pinned box.

Until the token/snapshot/network are set, `ebq:fleet-worker provision` returns a clear error
(e.g. "No worker image configured…") — the code is in place; provisioning activates once they're set.

## Status — fully built + live-tested; Hetzner setup done; autoscaler off pending an operator enable

> Setup complete as of 2026-06-17: `HCLOUD_TOKEN`, network (`12332718`), ssh key, firewall,
> the worker **snapshot**, and `.env.worker` are all configured, and the web box `ufw` allows
> the private subnet. A live provision→drain→destroy cycle passed. The **only** remaining step
> to start burst scaling is flipping `autoscaler.enabled` on at `/admin/fleet`.

- **Phase 1 ✅** fleet model, Hetzner client, fleet service, `ebq:fleet-worker`, config, pinned box.
- **Phase 2 ✅** `crawl-finalize` queue split — `AnalyzeSiteJob` (1200s) now runs ONLY on the
  pinned box (`Queues::CRAWL_FINALIZE`; the box's compose gained an `ebq-finalize-1` worker +
  `stop_grace_period: 360s`). Ephemeral boxes run `--queue=crawl` only, so a drain can never
  interrupt a finalize.
- **Phase 3 ✅** `DomainRateLimiter` (Redis fixed-window counter per normalized domain) in
  `PageCrawlProcessor::fetchWithPolicy()` — fleet-wide per-domain politeness, fail-open.
  **Fixed 2026-07-03:** `throttle()` used to call Laravel's `RateLimiter` facade, which
  resolves to the `database` cache store on this app — each box/worker checked its own DB
  connection's rows, so the "fleet-wide" limit was never actually shared across boxes.
  Rewritten on raw `Redis::connection()->incr()` per-second (or per-2s under WAF slow-mode)
  slot keys, which is genuinely atomic fleet-wide. Also added WAF/CDN auto-detection
  (`BlockDetector::detectWaf()`, header/server fingerprinting) → domains behind a detected
  WAF get pinned to 1 req/2s for 48h instead of the adaptive ramp (`config('crawler.rate_step')`
  10→1 — ramps +1 req/sec/domain per clean minute, much gentler than the old +10; `rate_max`
  100→3→**15** (2026-07-03) — non-WAF domains ceiling at 15 req/sec/domain, still reached
  slowly since `rate_step` stayed at 1).
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
