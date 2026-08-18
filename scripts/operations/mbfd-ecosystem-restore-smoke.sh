#!/usr/bin/env bash
set -Eeuo pipefail

umask 077
readonly ENV_FILE="${MBFD_BACKUP_ENV:-/etc/mbfd-backup/restic.env}"
readonly WORK_ROOT="${MBFD_RESTORE_SMOKE_ROOT:-/var/backups/mbfd-restore-smoke}"
readonly STAGING="${MBFD_BACKUP_STAGING:-/var/backups/mbfd-ecosystem-staging}"
readonly SNAPSHOT_ID_FILE="$STAGING/latest-snapshot-id"
readonly EXPECTED_HOST="mbfdhub"
readonly EXPECTED_TAG="mbfd-ecosystem"
readonly HELPER="${MBFD_BACKUP_SNAPSHOT_HELPER:-/usr/local/sbin/mbfd_backup_snapshot.py}"

[[ "$(id -u)" -eq 0 ]] || { echo 'run as root' >&2; exit 1; }
[[ -r "$ENV_FILE" ]] || { echo "missing $ENV_FILE" >&2; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-/var/cache/restic-mbfd}"

# Read the EXACT snapshot id captured by the backup script (never independently select latest).
[[ -r "$SNAPSHOT_ID_FILE" ]] || { echo "FATAL: snapshot id file not found: $SNAPSHOT_ID_FILE" >&2; exit 1; }
requested_id="$(cat "$SNAPSHOT_ID_FILE")"
[[ -n "$requested_id" ]] || { echo "FATAL: snapshot id file is empty" >&2; exit 1; }

# Verify the requested id matches a real snapshot with the correct host/tag.
# Never falls back to latest; fails closed on missing/ambiguous/wrong.
snapshot="$(restic snapshots --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG" --json \
    | python3 "$HELPER" verify --id "$requested_id" --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG")" \
    || { echo "FATAL: snapshot $requested_id failed verification" >&2; exit 1; }

echo "verified exact snapshot: $snapshot"

work="$(mktemp -d "$WORK_ROOT.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT

restic restore "$snapshot" --target "$work" \
    --include='*/databases/mbfd-hub.dump' \
    --include='*/databases/nextcloud.dump' \
    --include='*/sqlite/media-control.sqlite' \
    --include='*/sqlite/openwebui.sqlite' \
    --include='*/SHA256SUMS' \
    --include='*/inventory/containers.jsonl' \
    --include='*/inventory/enabled-units.txt'

mbfd_dump="$(find "$work" -type f -name mbfd-hub.dump -print -quit)"
nextcloud_dump="$(find "$work" -type f -name nextcloud.dump -print -quit)"
media_db="$(find "$work" -type f -name media-control.sqlite -print -quit)"
openwebui_db="$(find "$work" -type f -name openwebui.sqlite -print -quit)"
sha256_file="$(find "$work" -type f -name SHA256SUMS -print -quit)"

[[ -s "$mbfd_dump" && -s "$nextcloud_dump" && -s "$media_db" && -s "$openwebui_db" ]]
[[ -s "$sha256_file" ]] || { echo 'FATAL: SHA256SUMS not found in restored snapshot' >&2; exit 1; }

# The backup manifest covers the entire staging tree, while this smoke test
# intentionally restores only four representative data artifacts. Build a
# strict sub-manifest so omitted deployment/rollback files are not reported as
# corrupt, and fail closed if any required artifact is absent from the manifest.
manifest_dir="$(dirname "$sha256_file")"
required_manifest="$work/required-SHA256SUMS"
required_artifacts=("$mbfd_dump" "$nextcloud_dump" "$media_db" "$openwebui_db")
: > "$required_manifest"
for artifact in "${required_artifacts[@]}"; do
    case "$artifact" in
        "$manifest_dir"/*) ;;
        *) echo "FATAL: restored artifact escaped manifest directory" >&2; exit 1 ;;
    esac
    relative="${artifact#"$manifest_dir"/}"
    expected="./$relative"
    awk -v expected="$expected" '$2 == expected { print; found=1 } END { exit(found ? 0 : 1) }' \
        "$sha256_file" >> "$required_manifest" \
        || { echo "FATAL: required artifact is absent from SHA256SUMS: $relative" >&2; exit 1; }
done
( cd "$manifest_dir" && sha256sum -c "$required_manifest" --quiet ) \
    || { echo 'FATAL: SHA256SUMS verification failed' >&2; exit 1; }

docker exec -i mbfd-hub-pgsql sh -euc 'pg_restore --list >/dev/null' < "$mbfd_dump"
docker exec -i mbfd-postgres sh -euc 'pg_restore --list >/dev/null' < "$nextcloud_dump"
[[ "$(sqlite3 "$media_db" 'PRAGMA integrity_check;')" == ok ]]
[[ "$(sqlite3 "$openwebui_db" 'PRAGMA integrity_check;')" == ok ]]

printf 'restore_smoke=pass snapshot=%s\n' "$snapshot"
