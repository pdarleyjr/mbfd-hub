#!/usr/bin/env bash
set -Eeuo pipefail
#
# MBFD ecosystem restore-smoke (corrected, canonical source of truth).
#
# Restores the EXACT snapshot captured by the backup script (written to
# $STAGING/latest-snapshot-id) and verifies representative artifacts. Never
# independently selects "latest"; never silently selects another snapshot.
#
# §10 invariants:
#   1. backup writes the exact new snapshot id  -> latest-snapshot-id file
#   2. restore reads that exact id explicitly
#   3. restored id == requested id (verified via mbfd_backup_snapshot.py)
#   4. restore into an isolated mktemp dir
#   5. compare SHA256SUMS of representative artifacts
#   6. fail on hash mismatch
#   7. fail when the snapshot is absent / wrong host / wrong tag
#   8. never select another "latest" snapshot (helper fails closed)
#   9. log only safe metadata (sizes, short id, pass/fail)
#
# Deploy POST-CLASS. Do NOT deploy during an active class.
#

umask 077
readonly ENV_FILE="${MBFD_BACKUP_ENV:-/etc/mbfd-backup/restic.env}"
readonly WORK_ROOT="${MBFD_RESTORE_SMOKE_ROOT:-/var/backups/mbfd-restore-smoke}"
readonly STAGING="${MBFD_BACKUP_STAGING:-/var/backups/mbfd-ecosystem-staging}"
readonly SNAPSHOT_ID_FILE="$STAGING/latest-snapshot-id"
readonly EXPECTED_HOST="mbfdhub"
readonly EXPECTED_TAG="mbfd-ecosystem"
readonly HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SNAPSHOT_HELPER="$HERE/mbfd_backup_snapshot.py"

[[ "$(id -u)" -eq 0 ]] || { echo 'run as root' >&2; exit 1; }
[[ -r "$ENV_FILE" ]] || { echo "missing $ENV_FILE" >&2; exit 1; }
[[ -x "$SNAPSHOT_HELPER" || -r "$SNAPSHOT_HELPER" ]] || {
    echo "missing snapshot helper: $SNAPSHOT_HELPER" >&2; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
export RESTIC_CACHE_DIR="${RESTIC_CACHE_DIR:-/var/cache/restic-mbfd}"

work="$(mktemp -d "$WORK_ROOT.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT

# --- 1. Read the exact snapshot id written by the backup script ---
if [[ ! -r "$SNAPSHOT_ID_FILE" ]]; then
    echo "FATAL: backup snapshot id file not found: $SNAPSHOT_ID_FILE" >&2
    echo "The backup script must run first and write the exact snapshot id." >&2
    exit 1
fi
requested="$(tr -d '[:space:]' < "$SNAPSHOT_ID_FILE")"
[[ -n "$requested" ]] || { echo "FATAL: snapshot id file is empty" >&2; exit 1; }

# --- 2/3/7/8. Verify the exact id exists with expected host/tag; never latest ---
verify_json="$(restic snapshots --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG" --json)"
snapshot="$(printf '%s' "$verify_json" | python3 "$SNAPSHOT_HELPER" verify \
    --id "$requested" --host "$EXPECTED_HOST" --tag "$EXPECTED_TAG")" \
    || { echo "FATAL: snapshot $requested could not be verified" >&2; exit 1; }
echo "Confirmed snapshot: $snapshot (requested=$requested)"

# --- 4. Restore the EXACT snapshot into an isolated dir ---
restic restore "$snapshot" --target "$work" \
    --include='*/databases/mbfd-hub.dump' \
    --include='*/databases/nextcloud.dump' \
    --include='*/sqlite/media-control.sqlite' \
    --include='*/sqlite/openwebui.sqlite' \
    --include='*/SHA256SUMS'

# --- 5/6. Verify representative artifacts exist and hashes match ---
# A snapshot may also contain historical SHA256SUMS files below application
# backup directories. Select one coherent staging root that contains the
# manifest and all representative databases; never combine independent finds.
stage_dir_candidates=()
while IFS= read -r -d '' manifest_candidate; do
    candidate="${manifest_candidate%/SHA256SUMS}"
    if [[ -s "$candidate/databases/mbfd-hub.dump" \
        && -s "$candidate/databases/nextcloud.dump" \
        && -s "$candidate/sqlite/media-control.sqlite" \
        && -s "$candidate/sqlite/openwebui.sqlite" ]]; then
        stage_dir_candidates+=("$candidate")
    fi
done < <(find "$work" -type f -name SHA256SUMS -print0)
[[ "${#stage_dir_candidates[@]}" -eq 1 ]] || {
    echo "FATAL: expected one complete backup staging root, found ${#stage_dir_candidates[@]}" >&2
    exit 1
}
stage_dir="${stage_dir_candidates[0]}"
mbfd_dump="$stage_dir/databases/mbfd-hub.dump"
nextcloud_dump="$stage_dir/databases/nextcloud.dump"
media_db="$stage_dir/sqlite/media-control.sqlite"
openwebui_db="$stage_dir/sqlite/openwebui.sqlite"
sha256_file="$stage_dir/SHA256SUMS"

docker exec -i mbfd-hub-pgsql sh -euc 'pg_restore --list >/dev/null' < "$mbfd_dump" \
    || { echo "FATAL: mbfd-hub dump failed pg_restore --list" >&2; exit 1; }
docker exec -i mbfd-postgres sh -euc 'pg_restore --list >/dev/null' < "$nextcloud_dump" \
    || { echo "FATAL: nextcloud dump failed pg_restore --list" >&2; exit 1; }
[[ "$(sqlite3 "$media_db" 'PRAGMA integrity_check;')" == ok ]] \
    || { echo "FATAL: media-control SQLite integrity check failed" >&2; exit 1; }
[[ "$(sqlite3 "$openwebui_db" 'PRAGMA integrity_check;')" == ok ]] \
    || { echo "FATAL: openwebui SQLite integrity check failed" >&2; exit 1; }

# The backup manifest covers the entire staging tree, while this smoke test
# intentionally restores only four representative data artifacts. Build a
# strict sub-manifest so omitted deployment/rollback files are not reported as
# corrupt, and fail closed if any required artifact is absent from the manifest.
manifest_dir="$stage_dir"
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

# --- 9. Log only safe metadata ---
printf 'restore_smoke=pass snapshot=%s requested=%s\n' "$snapshot" "$requested"
printf 'restored_artifacts:\n'
printf '  mbfd-hub.dump: %s bytes\n' "$(stat -c%s "$mbfd_dump" 2>/dev/null || stat -f%z "$mbfd_dump")"
printf '  nextcloud.dump: %s bytes\n' "$(stat -c%s "$nextcloud_dump" 2>/dev/null || stat -f%z "$nextcloud_dump")"
printf '  media-control.sqlite: %s bytes\n' "$(stat -c%s "$media_db" 2>/dev/null || stat -f%z "$media_db")"
printf '  openwebui.sqlite: %s bytes\n' "$(stat -c%s "$openwebui_db" 2>/dev/null || stat -f%z "$openwebui_db")"
printf 'verification:\n  pg_restore_list: pass\n  sqlite_integrity: pass\n  sha256sums: pass\n'
