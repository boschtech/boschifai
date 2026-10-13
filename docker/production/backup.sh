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
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

umask 077
mkdir -p "$BACKUP_DIR"

# Credentials come from the db container's own environment, never this script's arguments, so
# they don't show up in `ps` on the host. --single-transaction: consistent InnoDB snapshot without
# locking the app out. --no-tablespaces: the app user has no PROCESS privilege (and needs none).
"${COMPOSE[@]}" exec -T db sh -c \
    'exec mysqldump --single-transaction --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz.partial"
mv "$BACKUP_DIR/db-$STAMP.sql.gz.partial" "$BACKUP_DIR/db-$STAMP.sql.gz"

# ai-credentials holds decrypted per-step Vertex key files while a step runs — never back those up.
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" --exclude="app/private/ai-credentials" -C storage app

find "$BACKUP_DIR" -maxdepth 1 \( -name 'db-*.sql.gz' -o -name 'storage-*.tar.gz' \) \
    -mtime +"$BACKUP_KEEP_DAYS" -delete

echo "Backup written: $BACKUP_DIR/{db,storage}-$STAMP"
