#!/usr/bin/env bash
# /opt/mbfd/restic-backup.sh - encrypted off-host backup to Cloudflare R2/S3-compatible restic repo.
set -Eeuo pipefail
umask 077

if [ "$(id -u)" -ne 0 ]; then
  exec /usr/bin/sudo -- /opt/mbfd/restic-backup.sh
fi

ENV=/opt/mbfd/secrets/restic.env
SOURCES_FILE=/opt/mbfd/restic-sources.txt
EXCLUDES_FILE=/opt/mbfd/restic-excludes.txt
LOG=/var/log/mbfd-restic.log
STATUS=/opt/mbfd/secrets/restic-last-status
LOCK=/run/lock/mbfd-restic.lock
HOSTNAME_TAG=mbfdhub
LIMIT_UPLOAD=${RESTIC_LIMIT_UPLOAD_KIB:-4096}
LIMIT_DOWNLOAD=${RESTIC_LIMIT_DOWNLOAD_KIB:-4096}

log() { echo "[$(date -Is)] $*"; }

write_status() {
  local temp
  temp=$(mktemp "${STATUS}.XXXXXX") || return 1
  if ! printf '%s %s%s\n' "$1" "$(date -Is)" "${2:+ $2}" > "$temp" ||
     ! chmod 600 "$temp" || ! mv -f -- "$temp" "$STATUS"; then
    rm -f -- "$temp"
    return 1
  fi
}

fail() {
  trap - ERR
  if ! write_status FAIL "$1"; then
    # A stale success is less truthful than an absent status if storage is full.
    rm -f -- "$STATUS"
    log "CRITICAL: unable to write backup failure status"
  fi
  log "restic backup FAILED: $1"
  exit 1
}

trap 'fail preflight-error' ERR
exec >>"$LOG" 2>&1

exec 9>"$LOCK"
flock -n -E 75 9 || {
  code=$?
  if [ "$code" -eq 75 ]; then
    log "restic backup skipped: another restic operation is already running"
    exit 0
  fi
  fail lock-error
}

if [ ! -r "$ENV" ]; then fail missing-env; fi
if [ ! -r "$SOURCES_FILE" ]; then fail missing-sources-file; fi
if [ ! -r "$EXCLUDES_FILE" ]; then fail missing-excludes-file; fi
set -a
. "$ENV"
set +a
export RESTIC_PROGRESS_FPS=${RESTIC_PROGRESS_FPS:-0.016666}
export RESTIC_CACHE_DIR=${RESTIC_CACHE_DIR:-/var/cache/restic}
mkdir -p "$RESTIC_CACHE_DIR"

mapfile -t configured_sources < <(grep -Ev '^\s*(#|$)' "$SOURCES_FILE")
sources=()
for p in "${configured_sources[@]}"; do
  if [ -e "$p" ]; then
    sources+=("$p")
  else
    log "WARN: source missing, skipped: $p"
  fi
done
if [ "${#sources[@]}" -eq 0 ]; then
  log "no existing restic sources found"
  fail no-sources
fi

log "restic backup start: ${#sources[@]} source(s), upload limit ${LIMIT_UPLOAD} KiB/s"
error_file=$(mktemp)
trap 'rm -f "$error_file"' EXIT
if restic --retry-lock 10m backup \
  --host "$HOSTNAME_TAG" \
  --tag daily \
  --tag mbfd-expanded-scope \
  --exclude-file "$EXCLUDES_FILE" \
  --limit-upload "$LIMIT_UPLOAD" \
  --limit-download "$LIMIT_DOWNLOAD" \
  "${sources[@]}" 2>"$error_file"; then
  cat "$error_file"
  write_status ok
  log "restic backup OK"
  log "retention/prune intentionally not run automatically in this safe phase; use /opt/mbfd/restic-retention.sh for dry-run or approved apply"
else
  cat "$error_file"
  if grep -qF 'repository is already locked' "$error_file"; then
    log "restic backup deferred: another Restic operation holds the repository lock"
    exit 0
  fi
  fail backup-error
fi
