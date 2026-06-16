# Deployment & queues

## Topology — two boxes, one database, one Redis

```
        ┌──────────────────────────┐        ┌──────────────────────────┐
        │  WEB BOX (this host)      │        │  WORKER BOX (separate)   │
        │  10.0.0.2                 │        │  10.0.0.3 (Docker php8.3) │
        │                          │        │  docker-compose.worker.yml│
        │  • PHP-FPM (the web app) │        │                          │
        │  • supervisor queues:     │        │  • queue:work --queue=    │
        │      interactive, default │        │      crawl, sync          │
        │  • schedule:work (cron)   │        │    (heavy/long jobs)      │
        └─────────────┬────────────┘        └─────────────┬────────────┘
                      │   shared MySQL `ebq` + shared Redis │
                      └──────────────────────────────────────┘
```

- **MySQL `ebq`** and **Redis** are shared by both boxes. (No DB backups; binary
  logging off — data loss is permanent. See the top of `CLAUDE.md`.)
- The web box's `.env` has `REDIS_HOST=127.0.0.1`; the worker box points its
  `REDIS_HOST` at the web box (`10.0.0.2`) so both use the **same** Redis
  (queues + cache).
- Redis key prefix is `ebq-database-`; cache (locks) add `ebq-cache-`, so a unique
  lock key looks like
  `ebq-database-ebq-cache-laravel_unique_job:App\Jobs\CrawlWebsitePagesJob:crawl-site-8`.

### Worker box concrete facts (verified)

- Host `10.0.0.3` (`ubuntu-4gb-fsn1-3`). SSH from the web box with
  `/root/.ssh/id_ed25519_worker` as `root` (dedicated deploy key — use this, do
  **not** repurpose other services' credentials).
- Its `/var/www/ebq` is a **plain copy, not a git repo** — code is pushed there by
  **rsync from the web box**, not `git pull`.
- Workers run as Docker containers from `docker-compose.worker.yml`:
  `ebq-crawl-1..5` (5× `--queue=crawl`) + `ebq-sync-1` (`--queue=sync`), image
  `ebq-worker:8.3`.
- **The containers bind-mount the host's `/var/www/ebq` → `/var/www/ebq`.** So the
  container always runs the host dir's code; a code deploy is "update the host dir +
  restart the containers" — **you do NOT need to rebuild the image** (rebuilding
  without changing the bind-mounted code does nothing; restarting without updating
  the code keeps the old code — that mismatch caused the incident below).

## Queues

| Queue | Runs on | Workers | Notes |
|-------|---------|---------|-------|
| `interactive` | web box | supervisor `ebq-queue-interactive` (×2) | user-facing, low latency |
| `default` | web box | supervisor `ebq-queue-general` | mail, misc; mailables (`ShouldQueue`) land here |
| `crawl` | **worker box** | docker `queue:work --queue=crawl` | the whole crawl pipeline (`->onQueue(Queues::CRAWL)`) |
| `sync` | **worker box** | docker `queue:work --queue=sync` | GA/GSC sync, large imports |

Because crawl jobs go to the `crawl` queue, **they only run on the worker box.** The
web box never crawls.

## Deploying a code change

1. **Both boxes** get the new code (the web box checkout *and* the worker box's
   docker checkout — they are independent).
2. **Run migrations once** (shared DB): `php artisan migrate --force`.
3. **Web box:** `sudo systemctl restart php8.3-fpm` — opcache runs with
   `opcache.validate_timestamps=0`, so a graceful reload is **not** enough; a full
   restart rebuilds the opcache SHM (the master PID changes). Then
   `php artisan queue:restart` for the web-box workers.
4. **Worker box:** push the code with rsync (it's a plain dir, not a repo), clear
   its cached config, then **stop → start** the containers so the long-running
   `queue:work` processes reload the new classes:
   ```bash
   # from the web box
   rsync -az --exclude='.env' --exclude='.git/' --exclude='storage/' \
     --exclude='node_modules/' --exclude='vendor/' --exclude='public/build/' \
     --exclude='bootstrap/cache/' \
     -e "ssh -i /root/.ssh/id_ed25519_worker" /var/www/ebq/ root@10.0.0.3:/var/www/ebq/
   # on the worker box (note: docker compose -f wants an absolute path or a cd)
   ssh -i /root/.ssh/id_ed25519_worker root@10.0.0.3 \
     'rm -f /var/www/ebq/bootstrap/cache/*.php; \
      docker compose -f /var/www/ebq/docker-compose.worker.yml up -d'
   ```
   Verify the code actually landed:
   `grep -c crawl_site_id /var/www/ebq/app/Jobs/CrawlWebsitePagesJob.php` (> 0), and
   confirm at runtime by dispatching a crawl and checking the new `crawl_runs` row is
   `crawl_site_id`-keyed (old code = `website_id`-keyed).

`opcache.enable_cli = 0`, so `artisan` / `tinker` / queue workers compile fresh —
but a long-running `queue:work` process still holds loaded **classes** in memory, so
it must be restarted (or `queue:restart`-signalled) to pick up new class code.
Compiled Blade views are re-checked by mtime, so `view:clear` + a restart is the
safe combo for view changes.

## Two-box deploy gotcha (read this)

A migration changes the **shared** schema instantly for **both** boxes. If only one
box has the matching code, the other runs old code against the new schema. This bit
the shared-crawl rollout:

- After migrating, the worker box still ran **old** crawl code. It created
  `crawl_runs` keyed by `website_id` (the new schema keys by `crawl_site_id`), so
  the new dashboards couldn't see them.
- Worse, a **uniqueId mismatch** silently broke dispatch: the web box (new code)
  dispatches `CrawlWebsitePagesJob` with `uniqueId = "crawl-site-{id}"`, but the
  worker box (old code) processed it and released the **old** `uniqueId =
  "crawl-website-{websiteId}"`. The new-format lock was never released → every
  subsequent dispatch was de-duplicated by `ShouldBeUnique` and **never queued**.
  Symptom: "crawls not starting" with an empty `crawl` queue and a held lock.

**Rule:** for any change to a queued job's identity/serialization (constructor
args, `uniqueId`), deploy **both** boxes together, or pause the worker box's queue
while the schema/code is mid-migration. To clear a stuck unique lock manually
(what the admin "recrawl" button does):

```php
$uid = (new App\Jobs\CrawlWebsitePagesJob($websiteId))->uniqueId();
foreach ([
  'laravel_unique_job:'.App\Jobs\CrawlWebsitePagesJob::class.':'.$uid,
  'laravel_unique_job:'.App\Jobs\CrawlWebsitePagesJob::class.$uid,
] as $k) { Cache::lock($k)->forceRelease(); }
```
(If `forceRelease` doesn't clear it because the lock came from a *different*
code/uniqueId, delete the key at the Redis level — `redis-cli -n 0 DEL
'<prefixed-key>'` — or just make both boxes run the same code; the lock then
releases normally.)

## Incident postmortem — the shared-crawl rollout (2026-06-16)

What actually happened deploying the shared-crawl rework, and how it was recovered.
Read this before the next cross-box migration.

**Symptom chain:**
1. Migrated the shared DB + reloaded the **web box** only. The **worker box still ran
   old code** (its containers bind-mount its own `/var/www/ebq`, which hadn't been
   rsynced).
2. The migration **cleared all crawl data**, so the old-code worker's scheduler saw
   every domain as "never crawled" and re-crawled them — writing `website_id`-keyed
   rows with `crawl_site_id = NULL` (**orphans**: ~50k pages + ~130k links the new
   read paths ignore).
3. Dispatches appeared to vanish: old code released the **old** `uniqueId`
   (`crawl-website-{id}`) while the web box set the **new** one (`crawl-site-{id}`),
   so the new-format `ShouldBeUnique` lock leaked and de-duped every later dispatch.

**Recovery sequence (what worked):**
1. `docker compose -f /var/www/ebq/docker-compose.worker.yml stop` — halt the bleed.
2. `rsync` the new code web → worker; `rm bootstrap/cache/*.php`; `up -d` to restart
   on new code. Verified: new dispatch → `crawl_site_id`-keyed run.
3. Delete orphan rows (`WHERE crawl_site_id IS NULL`) on the four crawl tables —
   children first (findings, links, then pages, runs).
4. Clear leaked `*unique_job*` cache keys in Redis.
5. The poison **queue backlog** (old batch jobs referencing now-deleted pages) is
   harmless once the pages are gone — the batch jobs no-op on an empty page set; do
   **not** `queue:clear` the shared `sync` queue (it carries unrelated GA/GSC jobs).
6. `ebq:crawl-websites --backfill` to re-crawl every domain once, shared.

**Hardening applied:**
- `CrawlWebsitePagesJob` now wraps the `isCrawling()` check + run creation in a short
  atomic `Cache::lock('crawl-site-start-{id}', 30)`, so two near-simultaneous
  dispatches can't both create a run (the earlier symptom: duplicate concurrent runs
  for one crawl_site). `ShouldBeUnique` alone isn't enough — it only de-dupes
  *queued* jobs, not two already executing.

**Takeaways:**
- For a shared-schema migration, deploy **both boxes**, or stop the worker's `crawl`
  queue *before* migrating, and run `--backfill` *after* both boxes are on new code.
- A queued job's identity (`uniqueId`, constructor args) is part of its wire
  contract — changing it requires both boxes to match or it leaks locks / fails to
  deserialize.

## Sizing / timeouts

- The shared `php8.3-fpm` pool was widened (≈20 workers, `terminate_timeout` 120s)
  after site-wide 504s caused by a small pool + slow synchronous external calls.
- `REDIS_QUEUE_RETRY_AFTER=1320` must stay **above** the longest job timeout
  (`AnalyzeSiteJob` 1200s), on **both** boxes, or long crawls get re-dispatched
  while still running.
- PHP must be **8.3** on both boxes (8.5 breaks queued-closure serialization).
