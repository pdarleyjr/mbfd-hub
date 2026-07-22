#!/usr/bin/env bash
# Captures the EXACT snapshot id just created by `restic backup`.
# Called by /usr/local/sbin/mbfd-ecosystem-backup immediately after a successful
# `restic backup`. Writes the short id to $STAGING/latest-snapshot-id so the
# restore-smoke restores that precise snapshot (never an independent "latest").
set -Eeuo pipefail
STAGING="${MBFD_BACKUP_STAGING:-/var/backups/mbfd-ecosystem-staging}"
EXPECTED_HOST="${MBFD_BACKUP_HOST:-mbfdhub}"
EXPECTED_TAG="${MBFD_BACKUP_TAG:-mbfd-ecosystem}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HELPER="$HERE/mbfd_backup_snapshot.py"

mkdir -p "$STAGING"
snapshot="$(restic snapshots --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG" --json \
    | python3 "$HELPER" capture --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG")" \
    || { echo "FATAL: could not capture snapshot id" >&2; exit 1; }
printf '%s\n' "$snapshot" > "$STAGING/latest-snapshot-id"
echo "captured snapshot id: $snapshot"
