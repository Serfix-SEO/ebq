#!/usr/bin/env bash
# EBQ — restore drill: prove a backup actually restores. Run periodically (a
# backup you've never restored is not a backup). Restores ONE schema out of the
# latest dump into a THROWAWAY database (the name must contain 'test', so the
# TestCase guard is happy and you can never confuse it with prod), checks the
# table count, then drops it.
#
# ⛔ WHY THIS IS NOT A ONE-LINER (2026-09-29)
# The previous version piped the whole dump straight into `mysql "$DRILL_DB"`.
# That is catastrophic here: backup.sh dumps with `--databases`, so the file
# carries `CREATE DATABASE` + `USE` for EVERY schema — ebq, ebq_v2, marketing,
# marketing_test, postal, postal-server-1, restore_test. A `USE` inside the
# stream overrides the database named on the command line, so the "drill" would
# have restored last night's snapshot straight over LIVE production (17.5 GB,
# 128 tables) and over Postal's mail database, destroying everything written
# since 03:30. It had never been run, which is the only reason that never
# happened. Never pipe a `--databases` dump at a target schema.
set -euo pipefail

ENV_FILE="${EBQ_BACKUP_ENV:-/etc/ebq-backup.env}"
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
: "${LOCAL_DIR:=/var/backups/ebq}"

# The drill needs CREATE/DROP, which the BACKUP user deliberately does not have
# — it can read every schema and write none, and that boundary is worth keeping
# (it is also why the old version's disaster would have needed a privileged user
# to happen at all). So: run this as root, where MariaDB's unix-socket auth
# applies, or set DRILL_USER/DRILL_PASS for an account with rights on the
# scratch database. DB_USER/DB_PASS from the backup env are not used here.
if [ -n "${DRILL_USER:-}" ]; then
  MYSQL=(mysql -u "$DRILL_USER" -p"${DRILL_PASS:-}")
else
  MYSQL=(mysql)
fi

# Which schema to prove. The app's data by default; override to drill another.
SOURCE_DB="${SOURCE_DB:-ebq_v2}"
DRILL_DB="${DRILL_DB:-ebq_restore_drill_test}"

# Hard guard: whatever an operator or a future edit passes in, we only ever
# write to something that is unmistakably a scratch database.
case "$DRILL_DB" in
  *test*) ;;
  *) echo "refusing: DRILL_DB ('$DRILL_DB') must contain 'test'"; exit 1 ;;
esac
if [ "$DRILL_DB" = "$SOURCE_DB" ]; then
  echo "refusing: DRILL_DB and SOURCE_DB are both '$SOURCE_DB'"; exit 1
fi

LATEST="${LATEST:-$(ls -1t "$LOCAL_DIR"/ebq-*.sql.gz 2>/dev/null | head -1 || true)}"
[ -n "$LATEST" ] || { echo "no backup found in $LOCAL_DIR"; exit 1; }

echo "[$(date -Is)] drilling $SOURCE_DB from $LATEST -> $DRILL_DB"

# Extract just this schema's section, then strip every CREATE DATABASE/USE so
# nothing in the stream can redirect the restore. Two independent defences: the
# section filter, and the strip. Either alone would do; both, because the cost
# of being wrong here is the production database.
extract() {
  gunzip -c "$LATEST" \
    | awk -v want="$SOURCE_DB" '
        BEGIN { preamble = 1; keep = 0 }
        /^-- Current Database: /{
          preamble = 0
          keep = ($0 ~ ("`" want "`"))
          next
        }
        preamble || keep
      ' \
    | grep -viE '^[[:space:]]*(CREATE[[:space:]]+DATABASE|USE[[:space:]])'
}

# Paranoid check before anything is written: if a USE survived the filter, stop.
if extract | grep -qiE '^[[:space:]]*USE[[:space:]]'; then
  echo "refusing: a USE statement survived filtering — restore would not stay in $DRILL_DB"
  exit 1
fi
LINES="$(extract | wc -l)"
[ "$LINES" -gt 100 ] || { echo "refusing: only $LINES lines extracted for $SOURCE_DB — wrong schema name?"; exit 1; }

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`; CREATE DATABASE \`$DRILL_DB\`;"

# --one-database is a third belt: even a USE that somehow reached the client is
# ignored unless it names the default schema.
extract | "${MYSQL[@]}" --one-database "$DRILL_DB"

TABLES="$("${MYSQL[@]}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DRILL_DB';")"
LIVE="$("${MYSQL[@]}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SOURCE_DB';")"
echo "[$(date -Is)] restored OK — $TABLES tables in $DRILL_DB (live $SOURCE_DB has $LIVE)"
if [ "$TABLES" -lt "$LIVE" ]; then
  echo "WARNING: the restore has fewer tables than the live schema — investigate before trusting this backup"
fi

"${MYSQL[@]}" -e "DROP DATABASE \`$DRILL_DB\`;"
echo "[$(date -Is)] drill cleaned up. Restore path verified."
