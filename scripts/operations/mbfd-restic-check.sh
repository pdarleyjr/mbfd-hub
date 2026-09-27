#!/usr/bin/env bash
# /opt/mbfd/restic-check.sh - verify the off-host repository once daily; --status is read-only and fast.
set -Eeuo pipefail
umask 077

if [ "$(id -u)" -ne 0 ]; then
  exec /usr/bin/sudo -n -- /opt/mbfd/restic-check.sh "$@"
fi

ENV=/opt/mbfd/secrets/restic.env
STATUS=/opt/mbfd/secrets/restic-last-status
CHECK_STATUS=/opt/mbfd/secrets/restic-check-last-status
MAXAGE=$((36*3600))
CHECK_MAXAGE=$((48*3600))
now=$(date +%s)

backup_status() {
  if [ ! -f "$STATUS" ] || ! grep -q '^ok ' "$STATUS"; then
    echo backup_failure
  elif [ $((now-$(stat -c %Y "$STATUS"))) -gt "$MAXAGE" ]; then
    echo backup_stale
  else
    echo ok
  fi
}

write_check_status() {
  local temp
  temp=$(mktemp "${CHECK_STATUS}.XXXXXX")
  printf '%s %s\n' "$1" "$(date -Is)" > "$temp"
  chmod 600 "$temp"
  mv -f -- "$temp" "$CHECK_STATUS"
}

if [ "${1:-}" = '--status' ]; then
  kind=$(backup_status)
  if [ "$kind" = ok ]; then
    if [ ! -f "$CHECK_STATUS" ]; then
      kind=restore_check_failure
    else
      read -r kind _ < "$CHECK_STATUS"
      case "$kind" in
        ok|repository_inaccessible|restore_check_failure) ;;
        *) kind=restore_check_failure ;;
      esac
      if [ "$kind" = ok ] && [ $((now-$(stat -c %Y "$CHECK_STATUS"))) -gt "$CHECK_MAXAGE" ]; then
        kind=restore_check_failure
      fi
    fi
  fi
  echo "$kind"
  [ "$kind" = ok ]
  exit $?
fi

if [ "$#" -ne 0 ]; then
  echo 'Unknown restic check option' >&2
  exit 2
fi

kind=$(backup_status)
if [ "$kind" != ok ]; then
  echo "CRITICAL: $kind"
  exit 2
fi

if [ ! -r "$ENV" ]; then
  write_check_status repository_inaccessible
  echo 'CRITICAL: repository configuration unavailable'
  exit 2
fi
set -a
. "$ENV"
set +a
export RESTIC_PROGRESS_FPS=${RESTIC_PROGRESS_FPS:-0.016666}

error_file=$(mktemp)
trap 'rm -f "$error_file"' EXIT
if ! restic --retry-lock 10m snapshots --latest 1 --tag daily >/dev/null 2>"$error_file"; then
  if grep -qF 'repository is already locked' "$error_file"; then
    echo 'DEFERRED: another Restic operation holds the repository lock'
    exit 0
  fi
  write_check_status repository_inaccessible
  echo 'CRITICAL: repository inaccessible'
  exit 2
fi

: > "$error_file"
if ! timeout 20m restic --retry-lock 10m check >/dev/null 2>"$error_file"; then
  if grep -qF 'repository is already locked' "$error_file"; then
    echo 'DEFERRED: another Restic operation holds the repository lock'
    exit 0
  fi
  write_check_status restore_check_failure
  echo 'CRITICAL: repository check failed'
  exit 2
fi

write_check_status ok
echo 'OK: restic backup fresh and repository metadata check passed'
