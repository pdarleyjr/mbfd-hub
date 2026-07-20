#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

readonly BACKUP_MOUNT="${MBFD_BACKUP_MOUNT:-/mnt/mbfd-backup-local}"
readonly EXPECTED_UUID="${MBFD_BACKUP_UUID:-f3483872-3bd2-4e9e-8b09-71cb7dca3eca}"
readonly ENV_FILE="${MBFD_BACKUP_ENV:-/etc/mbfd-backup/restic.env}"
readonly STAGING_ROOT="${MBFD_BACKUP_STAGING:-/var/backups/mbfd-ecosystem-staging}"
readonly LOCK_FILE="/var/lock/mbfd-ecosystem-backup.lock"
readonly SNAPSHOT_TAG="mbfd-ecosystem"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail() { log "FATAL: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail 'run as root'
[[ -r "$ENV_FILE" ]] || fail "missing $ENV_FILE"
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is required}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is required}"

exec 9>"$LOCK_FILE"
flock -n 9 || fail 'another ecosystem backup is running'

mountpoint -q "$BACKUP_MOUNT" || fail "$BACKUP_MOUNT is not mounted"
actual_uuid="$(findmnt -nr -o UUID --target "$BACKUP_MOUNT")"
[[ "$actual_uuid" == "$EXPECTED_UUID" ]] || fail "backup filesystem UUID mismatch"
case "$RESTIC_REPOSITORY" in
    "$BACKUP_MOUNT"/*) ;;
    *) fail 'RESTIC_REPOSITORY is not on the verified backup filesystem' ;;
esac

export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-/var/cache/restic-mbfd}"
install -d -m 0700 "$RESTIC_CACHE_DIR" "$STAGING_ROOT"

if [[ "${1:-}" == '--verify-only' ]]; then
    log 'checking repository metadata'
    restic check
    exit 0
fi

run_id="$(date -u +%Y%m%dT%H%M%SZ)"
stage="$(mktemp -d "$STAGING_ROOT/$run_id.XXXXXX")"
cleanup() { rm -rf -- "$stage"; }
trap cleanup EXIT

install -d -m 0700 "$stage/databases" "$stage/sqlite" "$stage/inventory"

container_running() {
    [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || true)" == true ]]
}

dump_postgres() {
    local container="$1" label="$2" output="$stage/databases/$2.dump"
    container_running "$container" || fail "$container is not running"
    log "dumping PostgreSQL database: $label"
    docker exec "$container" sh -euc \
        'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner --no-privileges --compress=6' \
        > "$output"
    [[ -s "$output" ]] || fail "$label dump is empty"
    docker exec -i "$container" sh -euc 'pg_restore --list >/dev/null' < "$output"
}

dump_mariadb() {
    local container="$1" label="$2" output="$stage/databases/$2.sql.zst"
    container_running "$container" || fail "$container is not running"
    log "dumping MariaDB database: $label"
    docker exec "$container" sh -euc \
        'mariadb-dump --single-transaction --quick --routines --events --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
        | zstd -T0 -8 -q -o "$output"
    [[ -s "$output" ]] || fail "$label dump is empty"
    zstd -tq "$output"
}

sqlite_backup() {
    local volume="$1" relative="$2" label="$3"
    local mount source output integrity
    mount="$(docker volume inspect -f '{{.Mountpoint}}' "$volume")"
    source="$mount/$relative"
    output="$stage/sqlite/$label.sqlite"
    [[ -s "$source" ]] || fail "SQLite source missing: $volume/$relative"
    log "snapshotting SQLite database: $label"
    sqlite3 "$source" ".timeout 10000" ".backup '$output'"
    integrity="$(sqlite3 "$output" 'PRAGMA integrity_check;')"
    [[ "$integrity" == ok ]] || fail "$label SQLite integrity check failed"
}

dump_postgres mbfd-hub-pgsql mbfd-hub
dump_postgres mbfd-postgres nextcloud
dump_postgres vac-postgres vacation
dump_postgres mbfd-media-peertube-postgres-1 peertube
dump_mariadb mbfd-snipeit-db snipe-it

sqlite_backup infra_cmd-data mbfd_command.sqlite mbfd-command
sqlite_backup mbfd-ai_openwebui_data webui.db openwebui
sqlite_backup media-control_media_control_db remote_display.db media-control
sqlite_backup observability_uptime-kuma-data kuma.db uptime-kuma
sqlite_backup screentinker_screentinker_db remote_display.db screentinker
sqlite_backup mbfd-office-doc-agent-db jobs.sqlite3 office-doc-agent

docker ps --no-trunc --format '{{json .}}' > "$stage/inventory/containers.jsonl"
docker volume ls --format '{{json .}}' > "$stage/inventory/volumes.jsonl"
docker image ls --digests --no-trunc --format '{{json .}}' > "$stage/inventory/images.jsonl"
findmnt --json > "$stage/inventory/mounts.json"
systemctl list-unit-files --state=enabled --no-legend > "$stage/inventory/enabled-units.txt"

(cd "$stage" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)

paths=(
    "$stage"
    /var/lib/docker/volumes
    /etc/systemd/system
    /etc/cloudflared
    /etc/mbfd-backup
    /opt/mbfd
    /opt/mbfd-vacation
    /opt/ai-stack
    /opt/mbfd-workspace
    /home/mbfd/media-control
    /home/mbfd/screentinker
    /mnt/mbfd-storage/nextcloud-data
    /mnt/mbfd-storage/mbfd-media-peertube/config
    /mnt/mbfd-storage/mbfd-media-peertube/data
    /mnt/mbfd-storage/mbfd-media-peertube/opendkim
)

excludes=(
    '--exclude=**/.git/**'
    '--exclude=**/node_modules/**'
    '--exclude=**/vendor/**'
    '--exclude=**/storage/logs/**'
    '--exclude=**/cache/**'
    '--exclude=/var/lib/docker/volumes/mbfd-hub_pgsql-data/**'
    '--exclude=/var/lib/docker/volumes/mbfd-postgres-data/**'
    '--exclude=/var/lib/docker/volumes/mbfd-vacation_vac-pgdata/**'
    '--exclude=/var/lib/docker/volumes/snipeit_snipeit-db-data/**'
    '--exclude=/var/lib/docker/volumes/mbfd-clamav-data/**'
    '--exclude=/var/lib/docker/volumes/mbfd-ai-tts-voices/**'
    '--exclude=/var/lib/docker/volumes/mbfd-ai-whisper-models/**'
    '--exclude=/var/lib/docker/volumes/mbfd-media-peertube_assets/**'
)

log 'creating encrypted restic snapshot'
restic backup --host mbfdhub --tag "$SNAPSHOT_TAG" --tag daily \
    --exclude-caches "${excludes[@]}" "${paths[@]}"

log 'applying retention policy'
restic forget --host mbfdhub --tag "$SNAPSHOT_TAG" \
    --keep-daily 14 --keep-weekly 8 --keep-monthly 12

log 'validating latest snapshot metadata and staged database artifacts'
restic snapshots --host mbfdhub --tag "$SNAPSHOT_TAG" --latest 1
restic check --read-data-subset=1/100

log "backup complete: $run_id"
