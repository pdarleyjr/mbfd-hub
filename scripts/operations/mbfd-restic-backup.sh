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

exec >>"$LOG" 2>&1
log() { echo "[$(date -Is)] $*"; }

exec 9>"$LOCK"
if ! flock -n 9; then
  log "restic backup skipped: another restic operation is already running"
  exit 0
fi

if [ ! -r "$ENV" ]; then log "missing env: $ENV"; exit 1; fi
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
  printf 'FAIL %s no-sources\n' "$(date -Is)" > "$STATUS"
  chmod 600 "$STATUS"
  exit 1
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
  printf 'ok %s\n' "$(date -Is)" > "$STATUS"
  chmod 600 "$STATUS"
  log "restic backup OK"
  log "retention/prune intentionally not run automatically in this safe phase; use /opt/mbfd/restic-retention.sh for dry-run or approved apply"
else
  cat "$error_file"
  if grep -qF 'repository is already locked' "$error_file"; then
    log "restic backup deferred: another Restic operation holds the repository lock"
    exit 0
  fi
  printf 'FAIL %s backup-error\n' "$(date -Is)" > "$STATUS"
  chmod 600 "$STATUS"
  log "restic backup FAILED"
  exit 1
fi
