# Backups & point-in-time recovery

> **Status, verified 2026-09-29:** nightly logical backups are **running on box D**
> — `ebq-db-backup.timer` fires `scripts/db/backup.sh` at 03:30, 14 consecutive
> successes, ~1 GB gzipped each, 15 kept in `/var/backups/ebq`. Binlog is on
> (`ebq-bin.*`, `expire_logs_days = 7`).
>
> **Two gaps remain**, and both matter:
> 1. **Local-only.** `SB_HOST`/`SB_USER` are unset, so every dump sits on the same
>    machine as the database it protects. Survives a bad query; does not survive
>    losing that box. Ordering the Storage Box is the only outstanding step.
> 2. **Never restored.** `restore-drill.sh` exists and has no evidence of ever
>    having been run. A backup nobody has restored is not yet a backup.
>
> The backup now has an **alarm**: `App\Support\BackupHealth` reads the heartbeat
> `backup.sh` writes to `storage/app/backup-status.json` (that path exists because
> `/var/backups` is **not visible inside the app container**), and
> `ebq:failed-jobs-alert` reports missing / stale / failed / truncated, once a day,
> silent while healthy. Pinned by `tests/Feature/Ops/BackupHealthAlertTest.php`.
> ⚠️ The truncation check is the one worth understanding: a dump can exit 0 and
> still be a fraction of its usual size, and that looks exactly like a backup right
> up until the restore. It compares against the median of the dumps on disk.

## What it gives you
1. **Binary logging** → point-in-time recovery + the foundation for a read replica.
2. **Nightly offsite logical backups** of `ebq` to a Hetzner Storage Box (~€3.80/mo).
3. **A restore drill** so a backup is never untested.

## Operator setup (one-time, ~15 min)
1. **Enable binlog** (one restart; brief blip — box is shared with Postal/Jitsi, do it off-peak):
   ```
   sudo mkdir -p /var/log/mysql && sudo chown mysql:mysql /var/log/mysql   # REQUIRED — else startup aborts
   sudo cp scripts/db/binlog.cnf /etc/mysql/mariadb.conf.d/99-ebq-binlog.cnf
   sudo systemctl restart mariadb
   sudo mysql -uroot -e "SHOW VARIABLES LIKE 'log_bin';"   # ON
   ```
   (Done on the live box 2026-06-17: binlog active as `ebq-bin.000001`.)
   Then soften the `CLAUDE.md` "no backups, binlog off" warning to point here.
2. **Storage Box**: order a Hetzner **BX11**, accept its host key once, then write `/etc/ebq-backup.env`:
   ```
   DB_NAME=ebq  DB_USER=ebquser  DB_PASS=<pass>
   SB_HOST=uXXXXXX.your-storagebox.de  SB_USER=uXXXXXX  SB_PORT=23  SB_DIR=ebq-backups
   ```
3. **Schedule** (root cron): `30 3 * * * /var/www/ebq/scripts/db/backup.sh >> /var/log/ebq-backup.log 2>&1`
4. **Verify**: run `scripts/db/backup.sh` once, then `scripts/db/restore-drill.sh` (restores into a
   throwaway `*_test` DB, checks table count, drops it).

## Restore (real)
```
gunzip -c /var/backups/ebq/ebq-<stamp>.sql.gz | mysql -u ebquser -p ebq
```
For point-in-time after the last dump, replay binlog from the dump position with `mysqlbinlog`.

## Scaling note
Logical `mysqldump` is the correct starting point while the DB is small. Once data grows (or a
dedicated DB box lands), switch to streaming **physical** backups (`mariabackup`) + ZFS snapshots — see
the storage-scaling order in the `scaling-roadmap` memory.

## Per-node (sharding)
Each shard node needs the same: binlog drop-in (distinct `server_id`) + a `backup.sh` cron pointed at
that node's DB. The fleet bootstrap (`DbFleetService::bootstrap`) is where this belongs once a node is
provisioned. Tenant-level backups (one customer's data) are the `ebq:shard` export path — see
[../sharding/README.md](../sharding/README.md).
