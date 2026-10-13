#!/usr/bin/env bash
# Nightly backup for the VPS deployment (docs/deploy-vps.md "Backups"): a consistent MySQL dump
# plus storage/app (run attachments, artifacts). Repo checkouts under var/ are NOT backed up —
# they're re-cloned from GitHub. Neither is .env: it holds APP_KEY and BOSCHIFAI_CREDENTIALS_KEY,
# so keep that copy in the team's password manager, not next to the data it decrypts.
#
# Writes to $BACKUP_DIR (default /var/backups/boschifai) and keeps $BACKUP_KEEP_DAYS (default 14).
# This is an ON-BOX copy only — ship it off the server (see the runbook).
set -euo pipefail

cd "$(dirname "$0")/../.."

BACKUP_DIR="${BACKUP_DIR:-/var/backups/boschifai}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DB_CONTAINER=boschifai-db
# mysqldump from a newer client than the server image (mysql:8.0.32), run in a throwaway container
# on the db's network: 8.0.32's own client needs the RELOAD/FLUSH_TABLES privilege for
# --single-transaction (fixed in 8.0.33), which the app user doesn't have — confirmed on the first
# VPS deploy ("Couldn't execute 'FLUSH TABLES': Access denied").
DUMP_IMAGE=mysql:8.0.40

umask 077
mkdir -p "$BACKUP_DIR"
# A failed dump leaves no half-written file behind.
trap 'rm -f "$BACKUP_DIR/db-$STAMP.sql.gz.partial"' EXIT

DB_NETWORK="$(docker inspect "$DB_CONTAINER" -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')"
# Credentials come from the db container's own environment and reach the dump container as
# MYSQL_PWD by name only (`-e MYSQL_PWD`), so they never appear in a process list. Password last:
# `read` gives it the rest of the line.
read -r DB_USER DB_NAME MYSQL_PWD < <(docker exec "$DB_CONTAINER" \
    sh -c 'printf "%s %s %s" "$MYSQL_USER" "$MYSQL_DATABASE" "$MYSQL_PASSWORD"')
export MYSQL_PWD

# --single-transaction: consistent InnoDB snapshot without locking the app out.
# --no-tablespaces: the app user has no PROCESS privilege (and needs none).
docker run --rm --network "$DB_NETWORK" -e MYSQL_PWD "$DUMP_IMAGE" \
    mysqldump -h db --single-transaction --no-tablespaces --set-gtid-purged=OFF \
    -u"$DB_USER" "$DB_NAME" \
    | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz.partial"
unset MYSQL_PWD
mv "$BACKUP_DIR/db-$STAMP.sql.gz.partial" "$BACKUP_DIR/db-$STAMP.sql.gz"

# ai-credentials holds decrypted per-step Vertex key files while a step runs — never back those up.
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" --exclude="app/private/ai-credentials" -C storage app

find "$BACKUP_DIR" -maxdepth 1 \( -name 'db-*.sql.gz' -o -name 'storage-*.tar.gz' \) \
    -mtime +"$BACKUP_KEEP_DAYS" -delete

echo "Backup written: $BACKUP_DIR/{db,storage}-$STAMP"
