# Sharding — full-ULID, multi-node tenant + crawl sharding

> Status: **deployed to production** (full ULID on `ebq_v2`, both boxes) and **acceptance-proven on a
> genuinely separate Hetzner DB node** (2026-06-17). A live tenant (`dubaiexplorer.ae`) was moved
> primary→real-node `10.0.0.4`→back, with the migrating lock observed flipping in real time, row-count
> verify, anchor flips, source purge, and accurate fleet counts on both moves. Plan: repo-root
> `SHARDING_PLAN.md`. Branch: `feature/db-sharding-ulid` (pending merge to main).
>
> **Provisioning a real node — operator path (proven):**
> 1. Build the MariaDB snapshot once: `bash scripts/db/build-mariadb-snapshot.sh` → prints an image id →
>    set `HCLOUD_DB_IMAGE=<id>` in `.env` (+ `.env.worker`), `php artisan config:clear`, restart FPM.
> 2. `/admin/db-fleet` → **Provision** (role tenant-shard|crawl-shard) → **Bootstrap**. Bootstrap is
>    **self-sufficient**: it pushes `99-ebq.cnf` (`bind-address=0.0.0.0`, binlog, `server_id`=IP last
>    octet, buffer pool), restarts MariaDB, creates the app DB + `ebquser@'10.0.0.%'` grant, opens
>    `ufw`, then migrates the schema over the network — so it works even on a vanilla MariaDB box.
> 3. The pinned **primary** must also be reachable as a node from both boxes:
>    `GRANT ALL ON ebq_v2.* TO 'ebquser'@'10.0.0.2'` and `@'10.0.0.3'` (needed for move-*back* to primary).
>
> **Admin UI runs fleet ops asynchronously (2026-06-17):** provision / bootstrap / migrate / move /
drain are dispatched as `App\Jobs\Fleet\*` jobs on the **`fleet` queue**, processed ONLY by the
**root** `ebq-queue-fleet` Supervisor worker on the web box (it SSHes/rsyncs with root's key and can
run for minutes — never in the FPM request). Restart that worker after editing fleet/mover code.
The whole DB-shard + crawl-worker + tenant-move + **crawl-site-move** lifecycle is UI-proven via
`tests/Browser/FleetUiTest.php` (Dusk); the run is shown as a slideshow at **`/admin/fleet-test`**.

Gotchas learned: ephemeral boxes recycle private IPs → `DbFleetService` (and `WorkerFleetService`) SSH use
> `UserKnownHostsFile=/dev/null` so a changed host key never blocks bootstrap; bootstrap SQL is piped via
> **stdin** (not `mysql -e "…"`) to avoid nested shell-quoting eating backticks/passwords; `db_nodes.
> last_error` is `varchar(255)` so error writes are truncated.

EBQ shards its database across MariaDB nodes in **two independent dimensions**, behind one routing
layer. In single-node mode every anchor is NULL → the default connection → behaviour is identical to
pre-sharding (so it ships safely and activates per-tenant).

## Three tiers

| Tier | Shard key | Central anchor (on the primary) | What lives there |
|---|---|---|---|
| **Central** (`global`/default conn) | — | — | identity, billing, `websites`, `crawl_sites`, `website_user`, global catalogs/caches (`keywords`, `serp_*`, `competitor_backlinks`, `niches`…), fleet (`db_nodes`, `worker_nodes`), framework tables |
| **Tenant shard** | owner (user) | `users.db_node_id`, `websites.db_node_id` | per-website fact data: `search_console_data`, `analytics_data`, `rank_tracking_*`, `page_audit_*`, `backlinks`, `ai_insights`, `writer_projects`, `client_activities`, … (20 tables, `App\Models\Concerns\UsesTenantConnection`) |
| **Crawl shard** | domain (crawl_site) | `crawl_sites.crawl_node_id` | `website_pages`, `website_internal_links`, `crawl_runs`, `crawl_findings`, `website_finding_states` (`UsesCrawlConnection`) |

**Why crawl is its own tier:** a crawl_site is shared across owners, so it can't live on one owner's
shard. Sharding it by domain relieves the central crawl-write bottleneck flagged in
[../crawler/autoscaling.md](../crawler/autoscaling.md).

## Identity = ULID

All app tables use **ULID `char(26)`** primary/foreign keys (`HasUlids`); framework queue tables
(`jobs`/`failed_jobs`) + the Sanctum `personal_access_tokens` / `website_user` surrogate ids stay
auto-increment bigint (not FK-referenced, not moved). Globally-unique ids make a tenant **move** a clean
copy with no id remapping.

## Routing (`App\Support\`)

- **`DbNode`** (`db_nodes`) — one row per MariaDB box; `role` primary|tenant-shard|crawl-shard,
  `is_pinned` primary, status lifecycle, `connectionName()` = `node:{id}`.
- **`ShardManager`** — at boot (`AppServiceProvider::boot`) registers a live `node:{id}` Laravel
  connection per active node (clones the `global` config, overrides host/db/port). Cached, graceful.
- **`ShardContext`** (singleton) — per-request/job state. `forWebsite($id)` resolves the central anchors
  (`websites.db_node_id` + linked `crawl_sites.crawl_node_id`) to connections; NULL → default.
  Set by **`ResolveShardContext`** web middleware (session `current_website_id`), **`WebsiteApiAuth`**
  (token's website), and write jobs (`SyncSearchConsoleData`, `CrawlPassJob`, … call
  `forWebsite`/`forCrawlSite`).
- Tier models route via `getConnectionName()` → `ShardContext`. No SQL query crosses a tier boundary
  (the crawl read-path already merges per-user GSC clicks + shared findings in PHP — see
  [../crawler/read-path.md](../crawler/read-path.md)).

## Cross-tier integrity (option A — app-enforced)

Cross-tier FKs are **dropped** (a shard node has no central tables to reference): tenant→
{websites,users,keywords}, crawl→{crawl_sites,websites} — see
`2026_06_19_020000_drop_cross_tier_foreign_keys` (**MySQL-only**; sqlite keeps them). Within-tier FKs
are kept. Integrity is now app-enforced:
- **`App\Services\Sharding\ShardCleanup`** — deletes a website's tenant rows / a crawl-site's crawl rows
  on the right node connection, child-before-parent, via the **`App\Support\ShardTables`** registry.
  Wired into `Website::deleted` + `User::deleting` (replaces DB cascade).
- Source of truth for which tables/filters belong to each tier: `ShardTables::TENANT` / `::CRAWL`.

## Fleet (admin-managed, like the crawl fleet)

- **`App\Services\Fleet\DbFleetService`** (reuses `HetznerClient`): `registerPrimary` / `registerExisting`
  / `provision` (Hetzner box from a MariaDB snapshot) / `bootstrap` (configure MariaDB + firewall, then
  the web box runs `migrate` over the node connection) / `migrateNode` / `drain` / `destroy` (refuses a
  non-empty node).
- **`App\Support\DbFleetConfig`** — `Setting`-backed defaults (server_type, snapshot_id, placement, caps).
- **`ebq:db-node`** command + the **"Database shards" tab on the unified `/admin/fleet` page**
  (`FleetController::index` renders both fleets; `DbFleetController` still owns the DB-fleet POST
  actions/data). `/admin/db-fleet` now **302-redirects to `/admin/fleet#data`** (old links still work).
  The page is one tabbed, self-documented screen — "Crawl workers" (compute, live via Livewire poll)
  and "Database shards" (data — also fully live since 2026-07-06 via `Livewire\Admin\DbShardPanel`,
  see §Fleet-page Database tab below; the old 10s full-page reload is gone). Tab state persists in
  the URL hash.
- `config/services.php` `hetzner.db_image` + `hetzner.db_firewall_id`.
- **Server-type dropdowns** (`DbFleetController::SERVER_TYPES`, `FleetController::SERVER_TYPES`) are
  hardcoded slug=>label lists. Verified against the live Hetzner API for this account (fsn1, 2026-06-17):
  the Intel CX line is **cx23**(2/4) / **cx33**(4/8) / **cx43**(8/16) / **cx53**(16/32) — there is **no
  cx22/cx32**, and an invalid slug fails at provision time. An earlier list had `cx22`/`cx32` (nonexistent)
  and shifted specs; re-confirm via `GET /v1/server_types` if the catalog changes.
- **Move form** (Database-shards tab): Livewire-driven since 2026-07-06 (`DbShardPanel`) — Kind
  switches the subject list (`tenant`→users owning websites, `crawl`→crawl-sites), a debounced search
  filters it, each option is labeled with its **current host node**, and that node is disabled in the
  target list (server-side re-checked in `move()`). The old inline-JS `moveOptions` dropdown is gone.
- **Node counts** (`db_nodes.tenant_count` / `site_count`): these columns are only ever bumped by
  `ShardMover` increment/decrement on **moves** — organic signups / new crawl-sites land on the primary
  via NULL `db_node_id`/`crawl_node_id` and are **never** counted, so the **primary drifts low** (it had
  1/1 stored vs 2/2 actual). Fix: **`DbNode::reconcileCounts()`** recomputes every node from actual data
  (`COUNT(DISTINCT user_id)` / `COUNT(*)` grouped by `COALESCE(node_id, primaryId)`) and persists drift;
  `FleetController::index` calls it on every render, so the table self-heals. `tenant_count` = distinct
  website owners on the node; `site_count` = crawl-sites on the node. `DbNode::isEmpty()` (destroy guard)
  reads these — safe because the only drifting node (primary) is pinned/undeletable, and shard nodes only
  receive data via counted moves.

## Move / backup (`ebq:shard`)

**`App\Services\Sharding\ShardMover`** — `moveTenant(userId, targetNode)` / `moveCrawlSite(crawlSiteId,
targetNode)`: chunked copy over node connections → per-table row-count **verify** (aborts leaving source
intact on mismatch) → flip the central anchor in a tx → **purge the source** (`ShardCleanup`). Reversible
until the purge. CLI: `ebq:shard move tenant|crawl <id> --to=<node>`; admin: the move form on
`/admin/db-fleet`. **Validated** end-to-end on docker MariaDB (3 dbs): seed tenant on node_a → move →
node_b has the rows, node_a purged, anchors flipped.

> In-flight safety: a per-tenant **migrating lock** ({@see App\Support\ShardLock}) is held for the whole
> move, so tenant/crawl write jobs (GSC sync, audits, rank, crawl) re-queue themselves (`release(30)`)
> instead of writing to the source during the window — no lost writes. Mover copy is chunked (bounded
> memory); for very large tenants a `mariabackup`/`mysqldump` streaming path is the next optimization.

### Fleet-page Database tab is fully live (2026-07-06)

The tab's node table, resident lists, move form and move progress are one Livewire
component (`Livewire\Admin\DbShardPanel`, `wire:poll.5s`) — the old full-page
`window.location.reload` loop (which wiped in-progress form input every 10s) is gone.
Per node: **residents** (tenants with their websites; crawl sites) expandable per row,
with **total row counts** per resident (all tenant/crawl-tier tables summed on the
hosting node — computed on expand, cached 1h; per-resident ↻ busts one, a "recount all
rows on this node" button busts every resident on that node and recounts in the same
request; a wire:loading "counting rows…" spinner shows while counts run).
The move form is **host-aware**: each subject option shows its current node, and that
node is disabled in the target list (can't "move" something onto its own host).
Anchor-NULL subjects display as residents of the pinned primary. Tests:
`tests/Feature/Admin/DbShardPanelTest.php`.

### Live progress (`shard_moves`, added 2026-07-06)

Every move creates a **`shard_moves`** row (`App\Models\ShardMove`) that the mover updates through
`counting → copying → verifying → cutover → purging → completed|failed`: totals are pre-counted per
table (so the percentage is real), `rows_copied` bumps per 1000-row chunk, `current_table` +
`tables_done/tables_total` track position, `table_counts` stores the final per-table numbers,
`error` the failure. The fleet page's Database tab renders a **"Data moves"** panel
(`Livewire\Admin\ShardMoves`, `wire:poll.3s`) with a progress bar for running moves and history for
the last 10. `ebq:shard` CLI moves get the same row for free (the mover owns tracking, not the job).
A worker-kill/timeout can't leave a row stuck "copying": `MoveShardJob::failed()` marks the
in-flight row failed (data stays safe — source intact until purge, `insertOrIgnore` re-runs are
idempotent). Before this a move was a black box — the June 18 `MoveShardJob` timeout was visible
only in `failed_jobs`. Tests: `tests/Feature/Sharding/ShardMoveProgressTest.php`.

## Known follow-ups (deferred)
- Boundary-join CI test (run read paths with each tier on a separate sqlite connection).
- Migration path split (`database/migrations/{tenant,crawl}/`) so a node creates only its tier's tables
  (today nodes run the full set; cross-tier FKs are dropped so the unused central tables are harmless).
- Entity-keyed job routing (`TrackKeywordRankJob`, `RunCustomPageAudit`, `RunCompetitorDiscovery` resolve
  their website then set `ShardContext`).
- **DONE (2026-06-17):** production re-derive cutover (ULID/`ebq_v2`) + real Hetzner DB-node
  provisioning + live tenant move/move-back acceptance on a separate box (10.0.0.4).
- Per-node `server_id` is the private-IP last octet (unique within the /24) — revisit if a node ever
  lands outside `10.0.0.0/24` or for cross-shard replication topology.
- Offsite Storage Box backups + `feature/db-sharding-ulid` → main merge still pending.

## Key files
Models: `app/Models/DbNode.php`, `Concerns/UsesTenantConnection.php`, `Concerns/UsesCrawlConnection.php`.
Routing: `app/Support/{ShardManager,ShardContext,ShardTables,DbFleetConfig}.php`,
`app/Http/Middleware/ResolveShardContext.php`. Fleet: `app/Services/Fleet/DbFleetService.php`,
`app/Console/Commands/DbNodeCommand.php`, `app/Http/Controllers/Admin/DbFleetController.php`.
Sharding ops: `app/Services/Sharding/{ShardCleanup,ShardMover}.php`, `app/Console/Commands/ShardCommand.php`.
Snapshot builder: `scripts/db/build-mariadb-snapshot.sh` (builds the `HCLOUD_DB_IMAGE` MariaDB image).
Note: `ShardMover` increments the target node's `tenant_count`/`site_count` **and decrements the source's**
on a move (keeps `/admin/db-fleet` counts accurate across move + move-back).
Migrations: `…_create_db_nodes_table`, `…_add_shard_anchors`, `…_drop_cross_tier_foreign_keys`.
