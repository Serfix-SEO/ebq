#!/usr/bin/env bash
# EBQ — nightly logical backup of ALL MariaDB databases to an offsite
# Hetzner Storage Box. The first real backup story for the no-backup prod DB.
#
# Setup (operator, one-time):
#   1. Order a Hetzner Storage Box (BX11, ~EUR 3.80/mo) → note its host + user.
#   2. ssh-copy-id the box's key so this runs non-interactively:
#        ssh-keygen -y ... ; ssh -p23 <user>@<host>   (accept host key once)
#   3. Set env (e.g. /etc/ebq-backup.env, sourced below):
#        DB_USER=...  DB_PASS=...   (a user that can read every database)
#        SB_HOST=uXXXXXX.your-storagebox.de  SB_USER=uXXXXXX  SB_PORT=23  SB_DIR=ebq-backups
#   4. Schedule: a root cron at 03:30 →  30 3 * * * /var/www/ebq/scripts/db/backup.sh
#
# Keeps 14 daily dumps locally + mirrors to the Storage Box. For large data later,
# switch to streaming physical backups (mariabackup) — this logical path is the
# simple, correct starting point while the DB is small.
set -euo pipefail

ENV_FILE="${EBQ_BACKUP_ENV:-/etc/ebq-backup.env}"
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

: "${DB_USER:?set DB_USER}" "${DB_PASS:?set DB_PASS}"
: "${LOCAL_DIR:=/var/backups/ebq}" "${KEEP_DAYS:=14}"
: "${SB_PORT:=23}" "${SB_DIR:=ebq-backups}"

mkdir -p "$LOCAL_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$LOCAL_DIR/ebq-$STAMP.sql.gz"

echo "[$(date -Is)] dumping all databases -> $OUT"
# --single-transaction = consistent snapshot without locking (InnoDB);
# routines/triggers/events for a complete restore.
# Explicit DB list (never --all-databases: that would carry the mysql system
# schema into restores). 2026-09-05: the old default dumped only the DEAD
# legacy `ebq` schema — a backup that would have restored nothing useful.
DBS="$(mysql -u "$DB_USER" -p"$DB_PASS" -Nse "SHOW DATABASES" \
  | grep -vE '^(information_schema|performance_schema|mysql|sys)$' | tr '\n' ' ')"
# shellcheck disable=SC2086
mariadb-dump --single-transaction --quick --routines --triggers --events \
  -u "$DB_USER" -p"$DB_PASS" --databases $DBS | gzip -9 > "$OUT"

# Local rotation
find "$LOCAL_DIR" -name 'ebq-*.sql.gz' -mtime "+$KEEP_DAYS" -delete

# Offsite mirror (skip cleanly if the Storage Box isn't configured yet)
if [ -n "${SB_HOST:-}" ] && [ -n "${SB_USER:-}" ]; then
  echo "[$(date -Is)] uploading to $SB_USER@$SB_HOST:$SB_DIR"
  rsync -az -e "ssh -p $SB_PORT -o StrictHostKeyChecking=accept-new" \
    "$OUT" "$SB_USER@$SB_HOST:$SB_DIR/"
else
  echo "[$(date -Is)] SB_HOST/SB_USER unset — local-only backup (configure the Storage Box to go offsite)"
fi

echo "[$(date -Is)] backup ok: $OUT ($(du -h "$OUT" | cut -f1))"
