#!/usr/bin/env bash
set -Eeuo pipefail

umask 077
readonly ENV_FILE="${MBFD_BACKUP_ENV:-/etc/mbfd-backup/restic.env}"
readonly WORK_ROOT="${MBFD_RESTORE_SMOKE_ROOT:-/var/backups/mbfd-restore-smoke}"

[[ "$(id -u)" -eq 0 ]] || { echo 'run as root' >&2; exit 1; }
[[ -r "$ENV_FILE" ]] || { echo "missing $ENV_FILE" >&2; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-/var/cache/restic-mbfd}"

work="$(mktemp -d "$WORK_ROOT.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT

snapshot="$(restic snapshots --host mbfdhub --tag mbfd-ecosystem --latest 1 --json | jq -r '.[0].short_id // empty')"
[[ -n "$snapshot" ]] || { echo 'no MBFD ecosystem snapshot found' >&2; exit 1; }

restic restore "$snapshot" --target "$work" \
    --include='*/databases/mbfd-hub.dump' \
    --include='*/databases/nextcloud.dump' \
    --include='*/sqlite/media-control.sqlite' \
    --include='*/sqlite/openwebui.sqlite' \
    --include='*/SHA256SUMS'

mbfd_dump="$(find "$work" -type f -name mbfd-hub.dump -print -quit)"
nextcloud_dump="$(find "$work" -type f -name nextcloud.dump -print -quit)"
media_db="$(find "$work" -type f -name media-control.sqlite -print -quit)"
openwebui_db="$(find "$work" -type f -name openwebui.sqlite -print -quit)"

[[ -s "$mbfd_dump" && -s "$nextcloud_dump" && -s "$media_db" && -s "$openwebui_db" ]]
docker exec -i mbfd-hub-pgsql sh -euc 'pg_restore --list >/dev/null' < "$mbfd_dump"
docker exec -i mbfd-postgres sh -euc 'pg_restore --list >/dev/null' < "$nextcloud_dump"
[[ "$(sqlite3 "$media_db" 'PRAGMA integrity_check;')" == ok ]]
[[ "$(sqlite3 "$openwebui_db" 'PRAGMA integrity_check;')" == ok ]]

printf 'restore_smoke=pass snapshot=%s\n' "$snapshot"
